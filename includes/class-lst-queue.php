<?php
/**
 * Background translation.
 *
 * Two jobs run on cron. The first drains strings that real visits discovered
 * but could not translate in time. The second is the pre-fill crawler: it walks
 * every page in every active language so the translations exist in the database
 * before a search engine or a visitor ever asks for them.
 *
 * Pre-filling is what makes a hundred languages practical. Nothing is
 * translated twice, crawlers never trigger a paid call, and the daily character
 * cap bounds the spend no matter how large the site is.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron workers.
 */
class LEADST_Queue {

	const TICK_HOOK = 'leadst_queue_tick';
	const WARM_HOOK = 'leadst_warm_tick';

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Queue|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Queue
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register cron hooks and schedules.
	 *
	 * @return void
	 */
	public function boot() {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::TICK_HOOK, array( $this, 'process_pending' ) );
		add_action( self::WARM_HOOK, array( $this, 'process_warm' ) );

		self::schedule();
	}

	/**
	 * A five minute interval for the workers.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedule( $schedules ) {
		if ( ! isset( $schedules['leadst_five_minutes'] ) ) {
			$schedules['leadst_five_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'Every five minutes (Leadstart Translate)', 'leadstart-translate' ),
			);
		}
		return $schedules;
	}

	/**
	 * Make sure both events are scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 120, 'leadst_five_minutes', self::TICK_HOOK );
		}
		if ( ! wp_next_scheduled( self::WARM_HOOK ) ) {
			wp_schedule_event( time() + 180, 'leadst_five_minutes', self::WARM_HOOK );
		}
	}

	/**
	 * Remove scheduled events.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::TICK_HOOK );
		wp_clear_scheduled_hook( self::WARM_HOOK );
		wp_clear_scheduled_hook( 'leadst_translate_slug_batch' );
	}

	/**
	 * Secret shared between the crawler and the processor.
	 *
	 * @return string
	 */
	public static function warm_token() {
		$token = get_option( 'leadst_warm_token' );

		if ( ! $token ) {
			$token = wp_generate_password( 32, false, false );
			update_option( 'leadst_warm_token', $token, false );
		}

		return $token;
	}

	/**
	 * Translate strings that are sitting in the table with no translation.
	 *
	 * @return int Strings translated.
	 */
	public function process_pending() {
		$lock = wp_generate_password( 32, false, false );
		if ( ! add_option( 'leadst_pending_lock', array( 'token' => $lock, 'time' => time() ), '', false ) ) {
			$previous = get_option( 'leadst_pending_lock', array() );
			if ( ! is_array( $previous ) || time() - (int) ( $previous['time'] ?? 0 ) <= 600 ) {
				return 0;
			}
			delete_option( 'leadst_pending_lock' );
			if ( ! add_option( 'leadst_pending_lock', array( 'token' => $lock, 'time' => time() ), '', false ) ) { return 0; }
		}
		try {
		$primary  = LEADST_Engine::make();
		$fallback = new LEADST_Engine_Fallback();
		$deepl = new LEADST_Engine_DeepL();
		$can_deepl = leadst()->get( 'deepl_fallback_enabled' ) && $deepl->is_configured();
		$ultimate = new LEADST_Engine_Ultimate();

		$ai_client = new LEADST_Engine_AI_Client();
		if ( ! $primary->is_configured() && ! $fallback->is_configured() && ! $can_deepl && ! $ultimate->is_configured() && ! $ai_client->is_configured() ) {
			return 0;
		}

		$batch_size = (int) leadst()->get( 'queue_batch_size', 40 );
		$rows       = LEADST_DB::get_pending( '', $batch_size );

		if ( empty( $rows ) ) {
			return 0;
		}

		$source   = leadst()->get( 'source_language', 'en' );
		$by_lang  = array();
		$done     = 0;

		foreach ( $rows as $row ) {
			$by_lang[ $row['lang'] ][] = $row;
		}

		foreach ( $by_lang as $lang => $items ) {
			$texts = array();
			$ids   = array();
			$chars = 0;

			foreach ( $items as $item ) {
				$texts[] = $item['source_text'];
				$ids[]   = (int) $item['id'];
				$chars  += LEADST_DB::char_count( $item['source_text'] );
			}

			$engine = LEADST_Engine::make_for( $chars, $lang );
			if ( ! $engine || ! $engine->is_configured() ) {
				continue;
			}

			$result = $engine->translate( $texts, $lang, $source );
			list( $engine, $result ) = LEADST_Engine::recover_with_deepl( $engine, $result, $texts, $lang, $source );

			if ( is_wp_error( $result ) ) {
				set_transient( 'leadst_last_error', $result->get_error_message(), HOUR_IN_SECONDS );

				$data = $result->get_error_data();
				if ( is_array( $data ) && isset( $data['status'] ) && in_array( (int) $data['status'], array( 400, 401, 403 ), true ) ) {
					return $done;
				}

				continue;
			}

			foreach ( $texts as $i => $text ) {
				if ( isset( $result[ $i ] ) ) {
					LEADST_DB::save( $text, $lang, $result[ $i ], LEADST_DB::STATUS_MACHINE, $engine->id() );
					$done++;
				}
			}

			LEADST_Processor::flush_cache( $lang );
		}

		return $done;
	
		} finally {
			$owned = get_option( 'leadst_pending_lock', array() );
			if ( is_array( $owned ) && ( $owned['token'] ?? '' ) === $lock ) {
				delete_option( 'leadst_pending_lock' );
			}
		}
	}

	/**
	 * Build the pre-fill crawl list: every published URL, every language.
	 *
	 * @return int Number of pages queued.
	 */
	public function build_warm_queue() {
		$languages = array_keys( LEADST_Languages::active() );
		if ( ! $languages ) { return 0; }

		LEADST_DB::clear_warm_jobs();
		$jobs = array();
		$total = 0;
		$flush = static function () use ( &$jobs, &$total ) {
			if ( $jobs ) { $total += LEADST_DB::enqueue_warm_jobs( $jobs ); $jobs = array(); }
		};
		$queue_url = static function ( $url ) use ( $languages, &$jobs, $flush ) {
			foreach ( $languages as $lang ) {
				$jobs[] = array( 'lang' => $lang, 'url' => $url );
				if ( count( $jobs ) >= 75 ) { $flush(); }
			}
		};
		$queue_url( home_url( '/' ) );

		// Iterate WordPress content in small pages, not a 1,000-post cap.
		$page = 1;
		$types = (array) apply_filters( 'leadst_warm_post_types', array( 'post', 'page' ) );
		while ( true ) {
			$query = new WP_Query( array(
				'post_type' => $types,
				'post_status' => 'publish',
				'posts_per_page' => 200,
				'paged' => $page,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'no_found_rows' => true,
				'suppress_filters' => false,
			) );
			$ids = (array) $query->posts;
			foreach ( $ids as $id ) { $queue_url( get_permalink( $id ) ); }
			if ( count( $ids ) < 200 ) { break; }
			++$page;
		}
		$flush();
		update_option( 'leadst_warm_total', $total, false );
		return $total;
	}

	/**
	 * Fetch the next few pages so their strings get translated and stored.
	 *
	 * @return int Pages fetched.
	 */
	public function process_warm() {
		global $wpdb;
		$table = LEADST_DB::warm_table();
		$primary = LEADST_Engine::make();
		$fallback = new LEADST_Engine_Fallback();
		$deepl = new LEADST_Engine_DeepL();
		$can_deepl = leadst()->get( 'deepl_fallback_enabled' ) && $deepl->is_configured();
		$ultimate = new LEADST_Engine_Ultimate();
		$ai = new LEADST_Engine_AI_Client();
		if ( ! $primary->is_configured() && ! $fallback->is_configured() && ! $can_deepl && ! $ultimate->is_configured() && ! $ai->is_configured() ) { return 0; }
		if ( ( ( $primary->is_billable() && LEADST_Budget::remaining() < 1000 ) || ( $primary->uses_ai_budget() && LEADST_Budget::ai_remaining() < 1000 ) ) && ! $fallback->is_configured() && ! $can_deepl && ! $ultimate->is_configured() && ! $ai->is_configured() ) { return 0; }
		$size = max( 1, min( 25, (int) apply_filters( 'leadst_warm_batch_size', (int) leadst()->get( 'warm_batch_size', 3 ) ) ) );
		$claim = wp_generate_password( 32, false, false );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 600 );
		// UPDATE LIMIT atomically claims jobs. Abandoned claims become available after 10 min.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic job claim with LIMIT is not expressible via WordPress CRUD.
		$wpdb->query( $wpdb->prepare(
			'UPDATE %i SET status = 1, attempts = attempts + 1, claim_token = %s, claimed_at = %s WHERE status = 0 OR (status = 1 AND claimed_at < %s) ORDER BY id ASC LIMIT %d',
			$table, $claim, current_time( 'mysql', true ), $stale, $size
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh rows owned by this unique worker claim, must not cache.
		$items = $wpdb->get_results( $wpdb->prepare( 'SELECT id, lang, url, attempts FROM %i WHERE status = 1 AND claim_token = %s ORDER BY id', $table, $claim ), ARRAY_A );
		if ( ! $items ) { return 0; }
		$fetched = 0;
		foreach ( $items as $item ) {
			$id = (int) $item['id'];
			if ( ( ( $primary->is_billable() && LEADST_Budget::remaining() < 1000 ) || ( $primary->uses_ai_budget() && LEADST_Budget::ai_remaining() < 1000 ) ) && ! $fallback->is_configured() && ! $can_deepl && ! $ultimate->is_configured() && ! $ai->is_configured() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- State change in a private custom queue table; no cached row is used for job claims.
				$wpdb->update(
					$table,
					array( 'status' => 0, 'claim_token' => '', 'claimed_at' => null ),
					array( 'id' => $id, 'claim_token' => $claim ),
					array( '%d', '%s', '%s' ),
					array( '%d', '%s' )
				);
				continue;
			}
			$target = LEADST_Router::instance()->translate_url( $item['lang'], $item['url'] );
			$response = wp_safe_remote_get( $target, array(
				'timeout' => 60,
				'blocking' => true,
				'sslverify' => true,
				'redirection' => 0,
				'user-agent' => 'LeadstartTranslate/' . LEADST_VERSION . ' (pre-fill)',
				'headers' => array( 'X-WPTR-Warm' => self::warm_token(), 'Accept' => 'text/html', 'Cache-Control' => 'no-cache' ),
			) );
			$ok = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
			$status = $ok ? 2 : ( (int) $item['attempts'] >= 3 ? 3 : 0 );
			// WordPress' update API prepares values and identifiers. The claim
			// token in WHERE prevents a stale worker from overwriting a job
			// that has already been reclaimed by a newer worker.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional write to private claimed jobs; progress cache invalidated after processing.
			$updated = $wpdb->update(
				$table,
				array( 'status' => $status, 'claim_token' => '', 'claimed_at' => null ),
				array( 'id' => $id, 'claim_token' => $claim ),
				array( '%d', '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( $ok && 1 === $updated ) {
				++$fetched;
				LEADST_Processor::flush_cache( $item['lang'] );
			}
		}
		wp_cache_delete( 'warm_progress', 'leadstart_translate' );
		$progress = $this->warm_progress();
		$left = (int) $progress['remaining'];
		if ( 0 === $left ) { update_option( 'leadst_warm_finished', current_time( 'mysql' ), false ); }
		return $fetched;
	}

	/**
	 * Progress of the pre-fill crawl.
	 *
	 * @return array
	 */
	public function warm_progress() {
		global $wpdb;
		$table = LEADST_DB::warm_table();
		$counts = wp_cache_get( 'warm_progress', 'leadstart_translate' );
		if ( false === $counts ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Short-lived object-cache wrapper around a custom-table aggregate.
			$counts = $wpdb->get_row(
				$wpdb->prepare( 'SELECT COUNT(*) AS total, SUM(status IN (0,1)) AS remaining FROM %i', $table ),
				ARRAY_A
			);
			wp_cache_set( 'warm_progress', $counts, 'leadstart_translate', 15 );
		}
		$total = (int) ( $counts['total'] ?? 0 );
		$left = (int) ( $counts['remaining'] ?? 0 );
		$size = max( 1, min( 25, (int) apply_filters( 'leadst_warm_batch_size', (int) leadst()->get( 'warm_batch_size', 3 ) ) ) );
		return array(
			'total' => $total,
			'remaining' => $left,
			'done' => max( 0, $total - $left ),
			'percent' => $total > 0 ? round( ( $total - $left ) * 100 / $total ) : 0,
			'per_tick' => $size,
			'seconds_left' => (int) ceil( $left / $size ) * 300,
		);
	}

	public function clear_warm_queue() {
		LEADST_DB::clear_warm_jobs();
		delete_option( 'leadst_warm_queue' );
		delete_option( 'leadst_warm_total' );
	}

}
