<?php
/**
 * Atomic character usage reservations for billable translation requests.
 *
 * @package LeadstartTranslate
 */
defined( 'ABSPATH' ) || exit;

class LEADST_Budget {
	const DAILY_OPTION = 'leadst_usage_daily';
	const MONTHLY_OPTION = 'leadst_usage_monthly';
	const TOTAL_OPTION = 'leadst_usage_total';

	private static function table() {
		global $wpdb;
		return LEADST_DB::usage_table();
	}

	/**
	 * Read-only dashboard usage cache. Enforcement NEVER consults this cache:
	 * reserve_group() locks and reads live InnoDB rows in its transaction.
	 */
	private static function used( $key ) {
		global $wpdb;
		$cache_key = 'usage_' . md5( $key );
		$cached = wp_cache_get( $cache_key, 'leadstart_translate' );
		if ( false !== $cached ) {
			return max( 0, (int) $cached );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached, read-only usage dashboard query on a custom indexed table.
		$value = $wpdb->get_var(
			$wpdb->prepare( 'SELECT chars FROM %i WHERE period_key = %s', self::table(), $key )
		);
		$used = max( 0, (int) $value );
		wp_cache_set( $cache_key, $used, 'leadstart_translate', 15 );
		return $used;
	}

	public static function daily_used() {
		return self::used( 'day:' . current_time( 'Y-m-d' ) );
	}

	public static function monthly_used() {
		return self::used( 'month:' . current_time( 'Y-m' ) );
	}

	public static function total_used() {
		return self::used( 'total' );
	}

	public static function can_spend( $chars ) {
		$chars = (int) $chars;
		if ( $chars <= 0 ) {
			return false;
		}
		$day = max( 0, (int) leadst()->get( 'daily_char_cap', 0 ) );
		$month = max( 0, (int) leadst()->get( 'monthly_char_cap', 0 ) );
		return ( ! $day || self::daily_used() + $chars <= $day )
			&& ( ! $month || self::monthly_used() + $chars <= $month );
	}

	public static function remaining() {
		$day = max( 0, (int) leadst()->get( 'daily_char_cap', 0 ) );
		$month = max( 0, (int) leadst()->get( 'monthly_char_cap', 0 ) );
		return min(
			$day ? max( 0, $day - self::daily_used() ) : PHP_INT_MAX,
			$month ? max( 0, $month - self::monthly_used() ) : PHP_INT_MAX
		);
	}

	/**
	 * Reserve characters across three periods in one transaction.
	 *
	 * $wpdb is the WordPress database API. Its transactional queries are
	 * necessary here: get_option()/update_option() cannot atomically enforce
	 * limits across concurrent PHP workers. The usage table is InnoDB.
	 * All values and the table identifier use prepare() placeholders.
	 *
	 * @param string $prefix  Counter namespace (empty, ai:, or deepl:).
	 * @param int    $chars   Number of source characters.
	 * @param int    $day_cap Daily maximum, zero = unlimited.
	 * @param int    $month_cap Monthly maximum, zero = unlimited.
	 * @return bool Whether the reservation committed.
	 */
	private static function reserve_group( $prefix, $chars, $day_cap, $month_cap ) {
		global $wpdb;
		$chars = (int) $chars;
		if ( $chars < 1 || $chars > 100000000 ) {
			return false;
		}

		$table = self::table();
		$keys = array(
			$prefix . 'day:' . current_time( 'Y-m-d' ),
			$prefix . 'month:' . current_time( 'Y-m' ),
			$prefix . 'total',
		);
		$limits = array( max( 0, (int) $day_cap ), max( 0, (int) $month_cap ), 0 );

		// A single transaction with deterministic lock ordering prevents a
		// burst of requests from spending over a configured limit.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic provider quota reservation requires an InnoDB transaction, not cached options.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		foreach ( $keys as $index => $key ) {
			// Idempotent row creation; ignore duplicate primary keys.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique indexed row creation must occur inside the active transaction.
			if ( false === $wpdb->query(
				$wpdb->prepare( 'INSERT IGNORE INTO %i (period_key, chars) VALUES (%s, 0)', $table, $key )
			) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back this provider-quota transaction on failure.
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			// A cached value would be unsafe here: FOR UPDATE obtains a
			// fresh reading and holds its row lock until COMMIT/ROLLBACK.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FOR UPDATE locking must read live data, never cache a quota decision.
			$used = $wpdb->get_var(
				$wpdb->prepare( 'SELECT chars FROM %i WHERE period_key = %s FOR UPDATE', $table, $key )
			);
			if ( null === $used || ( $limits[ $index ] > 0 && (int) $used + $chars > $limits[ $index ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back this provider-quota transaction on failure.
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic increment prevents over-budget races between PHP workers.
			if ( false === $wpdb->query(
				$wpdb->prepare( 'UPDATE %i SET chars = chars + %d WHERE period_key = %s', $table, $chars, $key )
			) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back this provider-quota transaction on failure.
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit the atomic reservation before invalidating display caches.
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Best-effort rollback after failed transaction commit.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		foreach ( $keys as $key ) {
			wp_cache_delete( 'usage_' . md5( $key ), 'leadstart_translate' );
		}
		return true;
	}

	/**
	 * Atomically reserve Google Cloud Translation source characters.
	 * Failed provider calls remain reserved: Google may still bill them.
	 */
	public static function reserve( $chars ) {
		return self::reserve_group(
			'',
			$chars,
			leadst()->get( 'daily_char_cap', 0 ),
			leadst()->get( 'monthly_char_cap', 0 )
		);
	}

	/**
	 * Token-billed AI engines share a source-character usage guard. This
	 * protects request volume; it is NOT a prediction of provider token costs.
	 */
	public static function reserve_ai( $chars ) {
		return self::reserve_group(
			'ai:',
			$chars,
			leadst()->get( 'ai_daily_char_cap', 50000 ),
			leadst()->get( 'ai_monthly_char_cap', 300000 )
		);
	}

	public static function ai_daily_used() {
		return self::used( 'ai:day:' . current_time( 'Y-m-d' ) );
	}

	public static function ai_monthly_used() {
		return self::used( 'ai:month:' . current_time( 'Y-m' ) );
	}

	public static function ai_remaining() {
		$day = max( 0, (int) leadst()->get( 'ai_daily_char_cap', 50000 ) );
		$month = max( 0, (int) leadst()->get( 'ai_monthly_char_cap', 300000 ) );
		return min(
			$day ? max( 0, $day - self::ai_daily_used() ) : PHP_INT_MAX,
			$month ? max( 0, $month - self::ai_monthly_used() ) : PHP_INT_MAX
		);
	}

	/** Atomic DeepL character reservations, distinct from Google and AI. */
	public static function reserve_deepl( $chars ) {
		return self::reserve_group(
			'deepl:',
			$chars,
			leadst()->get( 'deepl_daily_char_cap', 50000 ),
			leadst()->get( 'deepl_monthly_char_cap', 500000 )
		);
	}

	public static function deepl_daily_used() { return self::used( 'deepl:day:' . current_time( 'Y-m-d' ) ); }
	public static function deepl_monthly_used() { return self::used( 'deepl:month:' . current_time( 'Y-m' ) ); }
	public static function deepl_remaining() {
		$day = max( 0, (int) leadst()->get( 'deepl_daily_char_cap', 50000 ) );
		$month = max( 0, (int) leadst()->get( 'deepl_monthly_char_cap', 500000 ) );
		return min( $day ? max( 0, $day - self::deepl_daily_used() ) : PHP_INT_MAX,
			$month ? max( 0, $month - self::deepl_monthly_used() ) : PHP_INT_MAX );
	}

	/** Deprecated compatibility method for extensions. */
	public static function record( $chars ) {
		return self::reserve( $chars );
	}

	public static function estimate_cost( $chars ) {
		$rate = (float) apply_filters( 'leadst_price_per_million_chars', 20.0 );
		return round( ( (int) $chars / 1000000 ) * $rate, 2 );
	}

	public static function is_bot() {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return true;
		}
		$ua = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );
		if ( '' === $ua ) {
			return true;
		}
		$patterns = 'bot|crawler|crawling|spider|slurp|mediapartners|facebookexternalhit|'
			. 'ia_archiver|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|gptbot|'
			. 'ccbot|claudebot|perplexity|applebot|yandex|baidu|duckduck|lighthouse|'
			. 'pingdom|uptimerobot|headlesschrome|python-requests|curl/|wget';
		return (bool) preg_match( '#(' . $patterns . ')#i', $ua );
	}
}
