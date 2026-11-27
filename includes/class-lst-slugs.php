<?php
/**
 * URL (slug) translation.
 *
 * /es/about-us/ becomes /es/sobre-nosotros/. Outgoing links are rewritten to
 * the translated form and incoming requests are mapped back to the original
 * slug before WordPress parses the request, so core routing is untouched.
 *
 * Slugs are translated from the post title rather than the hyphenated slug —
 * machine translation handles a sentence far better than "about-us".
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slug map and path rewriting.
 */
class LEADST_Slugs {

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Slugs|null
	 */
	private static $instance = null;

	/**
	 * Per-request memo of slug lookups.
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Slugs
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook into post saves.
	 *
	 * @return void
	 */
	public function boot() {
		if ( ! leadst()->get( 'translate_slugs' ) ) {
			return;
		}

		add_action( 'save_post', array( $this, 'queue_post_slug' ), 20, 3 );
		add_action( 'leadst_translate_slug_batch', array( $this, 'process_batch' ) );
	}

	/**
	 * Convert an original path to its translated form.
	 *
	 * @param string $path Path without leading slash or language prefix.
	 * @param string $lang Language slug.
	 * @return string
	 */
	public function translate_path( $path, $lang ) {
		$source = leadst()->get( 'source_language', 'en' );

		if ( $lang === $source || '' === trim( (string) $path, '/' ) ) {
			return $path;
		}

		$trailing = ( '/' === substr( $path, -1 ) );
		$segments = array_values( array_filter( explode( '/', trim( (string) $path, '/' ) ), 'strlen' ) );
		$map      = $this->map_for( $lang, 'forward' );

		foreach ( $segments as $i => $segment ) {
			$key = rawurldecode( $segment );
			if ( isset( $map[ $key ] ) ) {
				$segments[ $i ] = $map[ $key ];
			}
		}

		$out = implode( '/', $segments );
		return $trailing && '' !== $out ? $out . '/' : $out;
	}

	/**
	 * Convert a translated path back to the original slugs.
	 *
	 * @param string $path Path without leading slash or language prefix.
	 * @param string $lang Language slug.
	 * @return string
	 */
	public function restore_path( $path, $lang ) {
		$source = leadst()->get( 'source_language', 'en' );

		if ( $lang === $source || '' === trim( (string) $path, '/' ) ) {
			return $path;
		}

		$trailing = ( '/' === substr( $path, -1 ) );
		$segments = array_values( array_filter( explode( '/', trim( (string) $path, '/' ) ), 'strlen' ) );
		$map      = $this->map_for( $lang, 'reverse' );

		foreach ( $segments as $i => $segment ) {
			$key = rawurldecode( $segment );
			if ( isset( $map[ $key ] ) ) {
				$segments[ $i ] = $map[ $key ];
			}
		}

		$out = implode( '/', $segments );
		return $trailing && '' !== $out ? $out . '/' : $out;
	}

	/**
	 * Load and memoize the slug map for a language.
	 *
	 * Sites with very large slug tables should rely on the object cache; the
	 * map is stored in a transient so it costs one query per cache lifetime.
	 *
	 * @param string $lang      Language slug.
	 * @param string $direction forward (original => translated) or reverse.
	 * @return array
	 */
	private function map_for( $lang, $direction ) {
		$key = $lang . '_' . $direction;

		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		$transient = 'leadst_slugmap_' . $key;
		$cached    = get_transient( $transient );

		if ( is_array( $cached ) ) {
			$this->cache[ $key ] = $cached;
			return $cached;
		}

		global $wpdb;
		$table = LEADST_DB::slugs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( 'SELECT original_slug, translated_slug FROM %i WHERE lang = %s', $table, $lang ),
			ARRAY_A
		);

		$forward = array();
		$reverse = array();

		foreach ( (array) $rows as $row ) {
			$forward[ $row['original_slug'] ]   = $row['translated_slug'];
			$reverse[ $row['translated_slug'] ] = $row['original_slug'];
		}

		set_transient( 'leadst_slugmap_' . $lang . '_forward', $forward, DAY_IN_SECONDS );
		set_transient( 'leadst_slugmap_' . $lang . '_reverse', $reverse, DAY_IN_SECONDS );

		$this->cache[ $lang . '_forward' ] = $forward;
		$this->cache[ $lang . '_reverse' ] = $reverse;

		return $this->cache[ $key ];
	}

	/**
	 * Drop the cached maps after a write.
	 *
	 * @param string $lang Language slug.
	 * @return void
	 */
	public function flush_cache( $lang = '' ) {
		if ( $lang ) {
			delete_transient( 'leadst_slugmap_' . $lang . '_forward' );
			delete_transient( 'leadst_slugmap_' . $lang . '_reverse' );
			unset( $this->cache[ $lang . '_forward' ], $this->cache[ $lang . '_reverse' ] );
			return;
		}

		foreach ( array_keys( LEADST_Languages::all() ) as $slug ) {
			delete_transient( 'leadst_slugmap_' . $slug . '_forward' );
			delete_transient( 'leadst_slugmap_' . $slug . '_reverse' );
		}

		$this->cache = array();
	}

	/**
	 * Store one slug mapping, keeping it unique within the language.
	 *
	 * @param string $lang       Language slug.
	 * @param string $original   Original slug.
	 * @param string $translated Translated slug.
	 * @param string $type       Object type for reference.
	 * @param int    $object_id  Object id for reference.
	 * @return bool Whether a row was written.
	 */
	public function save( $lang, $original, $translated, $type = 'post', $object_id = 0 ) {
		global $wpdb;

		$original   = sanitize_title( $original );
		$translated = sanitize_title( $translated );

		if ( '' === $original || '' === $translated || $original === $translated ) {
			return false;
		}

		$table = LEADST_DB::slugs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
		$clash = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT original_slug FROM %i WHERE lang = %s AND translated_slug = %s AND original_slug <> %s',
				$table,
				$lang,
				$translated,
				$original
			)
		);

		if ( $clash ) {
			$suffix = 2;
			while ( $suffix < 50 ) {
				$candidate = $translated . '-' . $suffix;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
				$exists    = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'SELECT id FROM %i WHERE lang = %s AND translated_slug = %s',
						$table,
						$lang,
						$candidate
					)
				);
				if ( ! $exists ) {
					$translated = $candidate;
					break;
				}
				$suffix++;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO %i
					(object_type, object_id, lang, original_slug, translated_slug, status, updated_at)
				 VALUES (%s, %d, %s, %s, %s, 1, %s)
				 ON DUPLICATE KEY UPDATE
					translated_slug = VALUES(translated_slug),
					object_type = VALUES(object_type),
					object_id = VALUES(object_id),
					updated_at = VALUES(updated_at)",
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$table,
				$type,
				$object_id,
				$lang,
				$original,
				$translated,
				current_time( 'mysql' )
			)
		);

		$this->flush_cache( $lang );
		return true;
	}

	/**
	 * Mark a post's slugs for retranslation when it is saved.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function queue_post_slug( $post_id, $post, $update ) {
		unset( $update );

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$pending   = get_option( 'leadst_slug_queue', array() );
		$pending   = is_array( $pending ) ? $pending : array();
		$pending[] = (int) $post_id;

		update_option( 'leadst_slug_queue', array_values( array_unique( $pending ) ), false );

		if ( ! wp_next_scheduled( 'leadst_translate_slug_batch' ) ) {
			wp_schedule_single_event( time() + 60, 'leadst_translate_slug_batch' );
		}
	}

	/**
	 * Translate queued post slugs into every active language.
	 *
	 * @return void
	 */
	public function process_batch() {
		$queue = get_option( 'leadst_slug_queue', array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		$batch     = array_splice( $queue, 0, 10 );
		update_option( 'leadst_slug_queue', $queue, false );

		$primary   = LEADST_Engine::make();
		$fallback  = new LEADST_Engine_Fallback();
		$ultimate  = new LEADST_Engine_Ultimate();
		$source    = leadst()->get( 'source_language', 'en' );
		$languages = LEADST_Languages::active();

		if ( ( ! $primary->is_configured() && ! $fallback->is_configured() && ! $ultimate->is_configured() ) || empty( $languages ) ) {
			return;
		}

		$titles = array();
		foreach ( $batch as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status && $post->post_title ) {
				$titles[ $post_id ] = $post->post_title;
			}
		}

		if ( empty( $titles ) ) {
			return;
		}

		foreach ( $languages as $slug => $language ) {
			$chars = 0;
			foreach ( $titles as $title ) {
				$chars += LEADST_DB::char_count( $title );
			}

			$engine = LEADST_Engine::make_for( $chars, $slug );
			if ( ! $engine || ! $engine->is_configured() ) {
				continue;
			}

			$result = $engine->translate( array_values( $titles ), $slug, $source );
			list( $engine, $result ) = LEADST_Engine::recover_with_deepl( $engine, $result, array_values( $titles ), $slug, $source );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			$ids = array_keys( $titles );
			foreach ( $result as $i => $translated_title ) {
				if ( ! isset( $ids[ $i ] ) ) {
					continue;
				}
				$post = get_post( $ids[ $i ] );
				if ( ! $post ) {
					continue;
				}
				$this->save( $slug, $post->post_name, $translated_title, $post->post_type, $post->ID );
			}
		}

		if ( ! empty( $queue ) && ! wp_next_scheduled( 'leadst_translate_slug_batch' ) ) {
			wp_schedule_single_event( time() + 120, 'leadst_translate_slug_batch' );
		}
	}

	/**
	 * Whether any translated slug has been stored yet.
	 *
	 * The "translate URL slugs" setting only decides whether the map is used.
	 * If nothing ever filled it, the setting is on and every URL still reads
	 * in the source language, with nothing on screen explaining why.
	 *
	 * @return bool
	 */
	public static function has_any() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', LEADST_DB::slugs_table() ) );
	}

	/**
	 * Queue every published post for slug translation.
	 *
	 * @return int Number queued.
	 */
	public function queue_all_posts() {
		$ids = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'numberposts'    => 2000,
				'fields'         => 'ids',
				'suppress_filters' => false,
			)
		);

		update_option( 'leadst_slug_queue', array_map( 'intval', $ids ), false );

		if ( ! wp_next_scheduled( 'leadst_translate_slug_batch' ) ) {
			wp_schedule_single_event( time() + 30, 'leadst_translate_slug_batch' );
		}

		return count( $ids );
	}

	/**
	 * Kept for compatibility with the request filter signature.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function resolve_request( $vars ) {
		return $vars;
	}

	/**
	 * How many slugs are stored for a language.
	 *
	 * @param string $lang Language slug.
	 * @return int
	 */
	public function count_for( $lang ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE lang = %s', LEADST_DB::slugs_table(), $lang )
		);
	}
}
