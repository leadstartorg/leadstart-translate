<?php
/**
 * Translation memory storage.
 *
 * Every unique source string is stored once per language. That is what makes
 * pageviews free: a visitor read is a database lookup, never an API call.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data access layer for strings and slugs.
 */
class LEADST_DB {

	const STATUS_PENDING = 0;
	const STATUS_MACHINE = 1;
	const STATUS_HUMAN   = 2;
	const STATUS_FAILED  = 3;

	/**
	 * Strings table name.
	 *
	 * @return string
	 */
	public static function storage_namespace() {
		// 'wptr' and 'lst' are legacy table namespaces kept only for existing installs.
		$stored = get_option( 'leadst_storage_namespace', 'leadst' );
		return in_array( $stored, array( 'wptr', 'lst' ), true ) ? $stored : 'leadst';
	}

	public static function warm_table() {
		global $wpdb;
		return $wpdb->prefix . self::storage_namespace() . '_warm_jobs';
	}

	public static function usage_table() {
		global $wpdb;
		return $wpdb->prefix . self::storage_namespace() . '_usage';
	}

	public static function strings_table() {
		global $wpdb;
		return $wpdb->prefix . self::storage_namespace() . '_strings';
	}

	/**
	 * Slugs table name.
	 *
	 * @return string
	 */
	public static function slugs_table() {
		global $wpdb;
		return $wpdb->prefix . self::storage_namespace() . '_slugs';
	}

	/**
	 * Create or update the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$strings = self::strings_table();
		$slugs   = self::slugs_table();

		$sql = "CREATE TABLE {$strings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_hash CHAR(32) NOT NULL,
			lang VARCHAR(12) NOT NULL,
			source_text LONGTEXT NOT NULL,
			translated_text LONGTEXT NULL,
			status TINYINT NOT NULL DEFAULT 0,
			engine VARCHAR(32) NOT NULL DEFAULT '',
			char_count INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
			PRIMARY KEY  (id),
			UNIQUE KEY hash_lang (source_hash, lang),
			KEY engine_lang (engine, lang),
			KEY status_lang (status, lang),
			KEY lang_idx (lang)
		) {$charset};";

		dbDelta( $sql );

		$sql = "CREATE TABLE {$slugs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(32) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			lang VARCHAR(12) NOT NULL,
			original_slug VARCHAR(150) NOT NULL,
			translated_slug VARCHAR(150) NOT NULL,
			status TINYINT NOT NULL DEFAULT 1,
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
			PRIMARY KEY  (id),
			UNIQUE KEY lang_original (lang, original_slug),
			KEY lookup (lang, translated_slug),
			KEY object_ref (object_type, object_id)
		) {$charset};";

		dbDelta( $sql );
		// Durable pre-fill jobs replace an unbounded serialized wp_options array.
		$warm = self::warm_table();
		$sql = "CREATE TABLE {$warm} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lang VARCHAR(12) NOT NULL,
			url_hash CHAR(32) NOT NULL,
			url TEXT NOT NULL,
			status TINYINT UNSIGNED NOT NULL DEFAULT 0,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			claim_token CHAR(32) NOT NULL DEFAULT '',
			claimed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lang_url (lang, url_hash),
			KEY status_id (status, id),
			KEY claim_token (claim_token)
		) ENGINE=InnoDB {$charset};";
		dbDelta( $sql );

		// Upgrade an in-progress legacy crawl without dropping its queued URLs.
		$legacy_jobs = get_option( 'leadst_warm_queue', array() );
		if ( is_array( $legacy_jobs ) && $legacy_jobs ) {
			LEADST_DB::enqueue_warm_jobs( $legacy_jobs );
			// Retain the legacy option as a recovery backup until the upgrade is validated.
			// It is not used by the new worker and can be deleted after staging verification.
		}

		// Spending rows are locked transactionally while reserving billable calls.
		// InnoDB is required for row locks to protect simultaneous pageviews.
		$usage = self::usage_table();
		$sql = "CREATE TABLE {$usage} (
			period_key VARCHAR(32) NOT NULL,
			chars BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (period_key)
		) ENGINE=InnoDB {$charset};";
		dbDelta( $sql );

		// Preserve existing daily, monthly and lifetime counters on upgrade.
		$daily = get_option( 'leadst_usage_daily', array() );
		$month = get_option( 'leadst_usage_monthly', array() );
		$old_total = (int) get_option( 'leadst_usage_total', 0 );
		$seed = array( 'total' => $old_total );
		if ( is_array( $daily ) && ! empty( $daily['date'] ) && isset( $daily['chars'] ) ) {
			$seed[ 'day:' . sanitize_text_field( $daily['date'] ) ] = max( 0, (int) $daily['chars'] );
		}
		if ( is_array( $month ) && ! empty( $month['month'] ) && isset( $month['chars'] ) ) {
			$seed[ 'month:' . sanitize_text_field( $month['month'] ) ] = max( 0, (int) $month['chars'] );
		}
		foreach ( $seed as $key => $value ) {
			// WordPress' CRUD API handles escaping and identifier quoting. A
			// duplicate primary key deliberately leaves live counters intact.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-time migration seed into a private, indexed usage table, not a request-time read.
			$wpdb->insert(
				$usage,
				array( 'period_key' => $key, 'chars' => $value ),
				array( '%s', '%d' )
			);
		}

		update_option( 'leadst_db_version', LEADST_DB_VERSION );
	}

	/**
	 * Normalize a string before hashing so trivial whitespace differences do
	 * not create duplicate rows.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( $text );
	}

	/**
	 * Hash for a source string.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function hash( $text ) {
		return md5( self::normalize( $text ) );
	}

	/**
	 * Fetch translations for many hashes at once.
	 *
	 * @param string[] $hashes Source hashes.
	 * @param string   $lang   Language slug.
	 * @return array Hash => translated string (only rows that have one).
	 */
	public static function get_many( array $hashes, $lang ) {
		global $wpdb;
		$hashes = array_values( array_unique( array_filter( $hashes, static function ( $hash ) {
			return is_string( $hash ) && 1 === preg_match( '/^[0-9a-f]{32}$/i', $hash );
		} ) ) );
		if ( ! $hashes ) {
			return array();
		}
		$out   = array();
		$table = self::strings_table();

		// Fixed-size prepared IN lists avoid SQL string interpolation without
		// degrading this hot page-rendering path into one query per hash.
		foreach ( array_chunk( $hashes, 25 ) as $chunk ) {
			$chunk = array_pad( $chunk, 25, $chunk[0] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed live translation lookups; correctness requires the current manual edit, not a stale cached result.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source_hash, translated_text FROM %i WHERE lang = %s AND source_hash IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) AND translated_text IS NOT NULL AND translated_text <> ''",
					$table,
					$lang,
					$chunk[0],
					$chunk[1],
					$chunk[2],
					$chunk[3],
					$chunk[4],
					$chunk[5],
					$chunk[6],
					$chunk[7],
					$chunk[8],
					$chunk[9],
					$chunk[10],
					$chunk[11],
					$chunk[12],
					$chunk[13],
					$chunk[14],
					$chunk[15],
					$chunk[16],
					$chunk[17],
					$chunk[18],
					$chunk[19],
					$chunk[20],
					$chunk[21],
					$chunk[22],
					$chunk[23],
					$chunk[24]
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$out[ $row['source_hash'] ] = $row['translated_text'];
			}
		}
		return $out;
	}

	/**
	 * Record source strings that have no translation yet.
	 *
	 * Uses INSERT IGNORE so repeated pageviews cost nothing and never overwrite a
	 * translation that already exists.
	 *
	 * @param string[] $texts Source strings.
	 * @param string   $lang  Language slug.
	 * @return int Rows inserted.
	 */
	public static function queue_strings( array $texts, $lang ) {
		global $wpdb;

		$texts = array_unique( array_filter( array_map( array( __CLASS__, 'normalize' ), $texts ) ) );
		if ( empty( $texts ) ) {
			return 0;
		}

		$table = self::strings_table();
		$now   = current_time( 'mysql' );
		$count = 0;
		$valid = array();
		foreach ( $texts as $text ) {
			$valid[] = array(
				'hash'  => self::hash( $text ),
				'text'  => $text,
				'chars' => self::char_count( $text ),
			);
		}

		// Fixed ten-row prepared statements retain batch throughput without
		// interpolated placeholder fragments. Duplicate padding is harmless:
		// the unique source_hash/lang key and INSERT IGNORE discard copies.
		foreach ( array_chunk( $valid, 10 ) as $batch ) {
			$batch = array_pad( $batch, 10, $batch[0] );
			// Custom translation-memory table: atomic INSERT IGNORE preserves
			// existing human translations on repeat pageviews.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom indexed translation table; values are prepared and individual writes invalidate summary caches.
			$result = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (source_hash, lang, source_text, translated_text, status, char_count, updated_at) VALUES
						(%s, %s, %s, NULL, 0, %d, %s),
						(%s, %s, %s, NULL, 0, %d, %s),
						(%s, %s, %s, NULL, 0, %d, %s),
						(%s, %s, %s, NULL, 0, %d, %s),
						(%s, %s, %s, NULL, 0, %d, %s),
						(%s, %s, %s, NULL, 0, %d, %s),
						(%s, %s, %s, NULL, 0, %d, %s),
						(%s, %s, %s, NULL, 0, %d, %s),
						(%s, %s, %s, NULL, 0, %d, %s),
						(%s, %s, %s, NULL, 0, %d, %s)',
					$table,
					$batch[0]['hash'],
					$lang,
					$batch[0]['text'],
					$batch[0]['chars'],
					$now,
					$batch[1]['hash'],
					$lang,
					$batch[1]['text'],
					$batch[1]['chars'],
					$now,
					$batch[2]['hash'],
					$lang,
					$batch[2]['text'],
					$batch[2]['chars'],
					$now,
					$batch[3]['hash'],
					$lang,
					$batch[3]['text'],
					$batch[3]['chars'],
					$now,
					$batch[4]['hash'],
					$lang,
					$batch[4]['text'],
					$batch[4]['chars'],
					$now,
					$batch[5]['hash'],
					$lang,
					$batch[5]['text'],
					$batch[5]['chars'],
					$now,
					$batch[6]['hash'],
					$lang,
					$batch[6]['text'],
					$batch[6]['chars'],
					$now,
					$batch[7]['hash'],
					$lang,
					$batch[7]['text'],
					$batch[7]['chars'],
					$now,
					$batch[8]['hash'],
					$lang,
					$batch[8]['text'],
					$batch[8]['chars'],
					$now,
					$batch[9]['hash'],
					$lang,
					$batch[9]['text'],
					$batch[9]['chars'],
					$now
				)
			);
			if ( false !== $result ) {
				$count += (int) $result;
			}
		}

		if ( $count > 0 ) {
			self::invalidate_summary_caches();
		}
		return $count;
	}

	/**
	 * Store a finished translation.
	 *
	 * A row already marked as human-edited is never overwritten by machine
	 * output.
	 *
	 * @param string $source     Source string.
	 * @param string $lang       Language slug.
	 * @param string $translated Translated string.
	 * @param int    $status     Status constant.
	 * @return void
	 */
	public static function save( $source, $lang, $translated, $status = self::STATUS_MACHINE, $engine = '' ) {
		global $wpdb;

		$source = self::normalize( $source );
		$hash   = self::hash( $source );
		$table  = self::strings_table();


		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i
					(source_hash, lang, source_text, translated_text, status, engine, char_count, updated_at)
				 VALUES (%s, %s, %s, %s, %d, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE
					translated_text = IF(status = 2 AND VALUES(status) <> 2, translated_text, VALUES(translated_text)),
					engine = IF(status = 2 AND VALUES(status) <> 2, engine, VALUES(engine)),
					updated_at = IF(status = 2 AND VALUES(status) <> 2, updated_at, VALUES(updated_at)),
					status = IF(status = 2 AND VALUES(status) <> 2, status, VALUES(status))",
				$table,
				$hash,
				$lang,
				$source,
				$translated,
				$status,
				self::STATUS_HUMAN === (int) $status ? 'human' : (string) $engine,
				self::char_count( $source ),
				current_time( 'mysql' )
			)
		);
		self::invalidate_summary_caches();
	}

	/**
	 * Pull a batch of untranslated strings for the background worker.
	 *
	 * @param string $lang  Language slug, or empty for any.
	 * @param int    $limit Maximum rows.
	 * @return array List of rows with id, lang, source_text.
	 */
	public static function get_pending( $lang = '', $limit = 40 ) {
		global $wpdb;
		$table = self::strings_table();
		$limit = max( 1, min( 200, (int) $limit ) );

		// Pending jobs must be read live; stale object caches could cause
		// repeated provider requests or delays in manual corrections.
		if ( '' !== $lang ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time unprocessed jobs from indexed custom table, must not cache.
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, lang, source_text FROM %i WHERE status = 0 AND lang = %s ORDER BY id ASC LIMIT %d',
					$table,
					$lang,
					$limit
				),
				ARRAY_A
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time unprocessed jobs from indexed custom table, must not cache.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, lang, source_text FROM %i WHERE status = 0 ORDER BY id ASC LIMIT %d',
				$table,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Mark rows as failed so a permanent API error does not loop forever.
	 *
	 * @param int[] $ids Row ids.
	 * @return void
	 */
	public static function mark_failed( array $ids ) {
		global $wpdb;
		$table = self::strings_table();
		$ids = array_unique( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$now = current_time( 'mysql' );
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom translation-memory status write; aggregate cache invalidated after loop.
			$wpdb->update(
				$table,
				array( 'status' => self::STATUS_FAILED, 'updated_at' => $now ),
				array( 'id' => $id, 'status' => self::STATUS_PENDING ),
				array( '%d', '%s' ),
				array( '%d', '%d' )
			);
		}
		self::invalidate_summary_caches();
	}

	/**
	 * How many stored strings each tier produced.
	 *
	 * This is the answer to "is it actually using the AI provider, or is it
	 * still going to Google?" -- counted from the rows themselves rather
	 * than from which tiers happen to be switched on.
	 *
	 * @return array Engine id => count, most used first.
	 */
	/** Clear cached aggregate results when the translation memory changes. */
	private static function invalidate_summary_caches() {
		delete_transient( 'leadst_source_chars' );
		wp_cache_delete( 'engine_counts', 'leadstart_translate' );
		wp_cache_delete( 'translation_stats', 'leadstart_translate' );
	}

	public static function engine_counts() {
		global $wpdb;

		$cached = wp_cache_get( 'engine_counts', 'leadstart_translate' );
		if ( false !== $cached ) {
			return $cached;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached aggregate query against custom indexed table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT engine, COUNT(*) AS n FROM %i WHERE translated_text IS NOT NULL GROUP BY engine ORDER BY n DESC',
				self::strings_table()
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['engine'] ] = (int) $row['n'];
		}

		wp_cache_set( 'engine_counts', $out, 'leadstart_translate', 30 );
		return $out;
	}

	public static function stats() {
		global $wpdb;

		$cached = wp_cache_get( 'translation_stats', 'leadstart_translate' );
		if ( false !== $cached ) {
			return $cached;
		}
		$table = self::strings_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached aggregate query against custom indexed table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT lang, status, COUNT(*) AS n FROM %i GROUP BY lang, status', $table ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$lang = $row['lang'];
			if ( ! isset( $out[ $lang ] ) ) {
				$out[ $lang ] = array(
					'total'      => 0,
					'translated' => 0,
					'pending'    => 0,
					'human'      => 0,
					'failed'     => 0,
				);
			}

			$n                     = (int) $row['n'];
			$out[ $lang ]['total'] += $n;

			switch ( (int) $row['status'] ) {
				case self::STATUS_PENDING:
					$out[ $lang ]['pending'] += $n;
					break;
				case self::STATUS_MACHINE:
					$out[ $lang ]['translated'] += $n;
					break;
				case self::STATUS_HUMAN:
					$out[ $lang ]['translated'] += $n;
					$out[ $lang ]['human']      += $n;
					break;
				case self::STATUS_FAILED:
					$out[ $lang ]['failed'] += $n;
					break;
			}
		}

		wp_cache_set( 'translation_stats', $out, 'leadstart_translate', 30 );
		return $out;
	}

	/**
	 * How many characters of source text the site holds.
	 *
	 * Measured from the source strings already discovered, so it is only as
	 * complete as the crawl. That is honest — it is a real measurement of this
	 * site rather than a guess, and it sharpens as the pre-fill progresses.
	 *
	 * @return int Zero before anything has been discovered.
	 */
	public static function source_character_estimate() {
		$cached = get_transient( 'leadst_source_chars' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = self::strings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE( SUM( char_count ), 0 ) FROM (
					SELECT source_hash, MAX( char_count ) AS char_count FROM %i GROUP BY source_hash
				 ) AS unique_sources',
				$table
			)
		);

		set_transient( 'leadst_source_chars', $total, HOUR_IN_SECONDS );

		return $total;
	}

	/**
	 * Count characters the way Google bills them: Unicode code points.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function char_count( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}


	/** Insert durable crawl jobs in bounded SQL batches. */
	public static function enqueue_warm_jobs( array $jobs ) {
		global $wpdb;
		$table = self::warm_table();
		$count = 0;
		$valid = array();
		foreach ( $jobs as $item ) {
			if ( ! is_array( $item ) || empty( $item['lang'] ) || empty( $item['url'] ) ) {
				continue;
			}
			$lang = sanitize_key( $item['lang'] );
			$url  = esc_url_raw( $item['url'] );
			if ( ! LEADST_Languages::exists( $lang ) || ! LEADST_Router::instance()->is_internal( $url ) ) {
				continue;
			}
			$valid[] = array(
				'lang'     => $lang,
				'url_hash' => md5( $url ),
				'url'      => $url,
			);
		}

		// A fixed ten-row statement is easier for Plugin Check to audit than
		// a dynamically concatenated list of placeholder tuples.
		foreach ( array_chunk( $valid, 10 ) as $batch ) {
			$batch = array_pad( $batch, 10, $batch[0] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic INSERT IGNORE into unique indexed custom job table; cache invalidated below.
			$result = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (lang, url_hash, url) VALUES
						(%s, %s, %s),
						(%s, %s, %s),
						(%s, %s, %s),
						(%s, %s, %s),
						(%s, %s, %s),
						(%s, %s, %s),
						(%s, %s, %s),
						(%s, %s, %s),
						(%s, %s, %s),
						(%s, %s, %s)',
					$table,
					$batch[0]['lang'],
					$batch[0]['url_hash'],
					$batch[0]['url'],
					$batch[1]['lang'],
					$batch[1]['url_hash'],
					$batch[1]['url'],
					$batch[2]['lang'],
					$batch[2]['url_hash'],
					$batch[2]['url'],
					$batch[3]['lang'],
					$batch[3]['url_hash'],
					$batch[3]['url'],
					$batch[4]['lang'],
					$batch[4]['url_hash'],
					$batch[4]['url'],
					$batch[5]['lang'],
					$batch[5]['url_hash'],
					$batch[5]['url'],
					$batch[6]['lang'],
					$batch[6]['url_hash'],
					$batch[6]['url'],
					$batch[7]['lang'],
					$batch[7]['url_hash'],
					$batch[7]['url'],
					$batch[8]['lang'],
					$batch[8]['url_hash'],
					$batch[8]['url'],
					$batch[9]['lang'],
					$batch[9]['url_hash'],
					$batch[9]['url']
				)
			);
			if ( false !== $result ) {
				$count += (int) $result;
			}
		}
		if ( $count > 0 ) {
			wp_cache_delete( 'warm_progress', 'leadstart_translate' );
		}
		return $count;
	}

	/**
	 * Empty the durable pre-fill queue and invalidate progress reporting.
	 * No WordPress CRUD method supports deleting every row in a custom table.
	 *
	 * @return bool Whether the deletion succeeded.
	 */
	public static function clear_warm_jobs() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-initiated full-table purge; prepared identifier, no cacheable query result.
		$ok = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::warm_table() ) );
		if ( false !== $ok ) {
			wp_cache_delete( 'warm_progress', 'leadstart_translate' );
		}
		return false !== $ok;
	}

	/**
	 * Delete every stored translation for a language.
	 *
	 * @param string $lang Language slug.
	 * @return int Rows removed.
	 */
	public static function purge_language( $lang ) {
		global $wpdb;

		$strings = self::strings_table();
		$slugs   = self::slugs_table();

		// WordPress CRUD APIs handle values and table identifiers here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit language purge of plugin-owned translation rows; aggregate cache invalidated below.
		$n = $wpdb->delete( $strings, array( 'lang' => $lang ), array( '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Purges matching plugin-owned slug rows alongside strings; cache invalidation follows.
		$wpdb->delete( $slugs, array( 'lang' => $lang ), array( '%s' ) );

		self::invalidate_summary_caches();
		return false === $n ? 0 : (int) $n;
	}
}
