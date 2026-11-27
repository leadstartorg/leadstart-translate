<?php
/**
 * Side-by-side comparison of the configured translation engines.
 *
 * Picking an engine is otherwise an act of faith: the admin screen can say
 * one tier is higher quality than another, but only the output settles it.
 * This takes one page, pulls the strings translation would send, and runs
 * them through every engine that is switched on, so the difference is on
 * screen instead of in the abstract.
 *
 * Comparing costs money — the same strings go to every engine — so nothing
 * is called until the count and the estimate have been shown and confirmed.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run the same strings through several engines and show what differs.
 */
class LEADST_Compare {

	/**
	 * Most strings compared in one run, whatever the page holds.
	 *
	 * A cap rather than a warning: one click should never be able to spend
	 * an unbounded amount, and a sample this size is enough to judge an
	 * engine by.
	 */
	const MAX_STRINGS = 60;

	/**
	 * Seconds a scan is held between the estimate and the confirmation.
	 */
	const SCAN_TTL = 900;

	/**
	 * Transient holding the pending scan.
	 */
	const SCAN_KEY = 'leadst_compare_scan';

	/**
	 * Transient holding the finished comparison.
	 */
	const RESULT_KEY = 'leadst_compare_result';

	public static function scan_key() { return self::SCAN_KEY . '_' . get_current_user_id(); }
	public static function result_key() { return self::RESULT_KEY . '_' . get_current_user_id(); }

	/**
	 * Engines that are configured right now, keyed by id.
	 *
	 * @return LEADST_Engine[]
	 */
	public static function engines() {
		$out = array();

		foreach ( array( 'LEADST_Engine_Google_V2', 'LEADST_Engine_Gemini', 'LEADST_Engine_AI_Client', 'LEADST_Engine_Mistral', 'LEADST_Engine_Qwen', 'LEADST_Engine_DeepL', 'LEADST_Engine_Fallback', 'LEADST_Engine_Ultimate' ) as $class ) {
			$engine = new $class();
			if ( $engine->is_configured() ) {
				$out[ $engine->id() ] = $engine;
			}
		}

		return $out;
	}

	/**
	 * Fetch a page and work out what comparing it would involve.
	 *
	 * Calls no translation API. The result is stored so the confirmation
	 * step translates exactly the strings that were costed, rather than
	 * re-fetching a page that may have changed in between.
	 *
	 * @param string $url    Page to scan, on this site.
	 * @param string $target Target language slug.
	 * @return array|WP_Error
	 */
	public static function scan( $url, $target ) {
		$url = esc_url_raw( trim( (string) $url ) );

		if ( '' === $url ) {
			return new WP_Error( 'leadst_compare_no_url', __( 'Enter the address of a page to compare.', 'leadstart-translate' ) );
		}

		if ( ! LEADST_Router::instance()->is_internal( $url ) ) {
			return new WP_Error(
				'leadst_compare_external',
				__( 'That address is not on this site. Comparison only reads pages from this install.', 'leadstart-translate' )
			);
		}

		if ( ! LEADST_Languages::get( $target ) ) {
			return new WP_Error( 'leadst_compare_bad_lang', __( 'Choose a language to compare into.', 'leadstart-translate' ) );
		}

		$engines = self::engines();

		if ( count( $engines ) < 2 ) {
			return new WP_Error(
				'leadst_compare_one_engine',
				__( 'Only one engine is configured, so there is nothing to compare it against. Switch on a second tier in Settings first.', 'leadstart-translate' )
			);
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 2,
				'user-agent'  => 'LeadstartTranslate/' . LEADST_VERSION . ' (compare)',
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'leadst_compare_http',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'That page answered with HTTP %d, so there is nothing to read.', 'leadstart-translate' ),
					$code
				)
			);
		}

		$strings = LEADST_Processor::instance()->extract_strings(
			wp_remote_retrieve_body( $response ),
			self::MAX_STRINGS
		);

		if ( empty( $strings ) ) {
			return new WP_Error(
				'leadst_compare_empty',
				__( 'No translatable text was found on that page.', 'leadstart-translate' )
			);
		}

		$chars = 0;
		foreach ( $strings as $text ) {
			$chars += LEADST_DB::char_count( $text );
		}

		$billable = 0;
		foreach ( $engines as $engine ) {
			if ( $engine->is_billable() ) {
				$billable++;
			}
		}

		$rate = (float) apply_filters( 'leadst_price_per_million_chars', 20.0 );

		$scan = array(
			'url'      => $url,
			'target'   => $target,
			'strings'  => $strings,
			'chars'    => $chars,
			'engines'  => array_keys( $engines ),
			'billable' => $billable,
			// Each engine sees every string, so the metered volume is the
			// page's characters once per billable tier.
			'cost'     => ( $chars * $billable / 1000000 ) * $rate,
			'capped'   => count( $strings ) >= self::MAX_STRINGS,
		);

		set_transient( self::scan_key(), $scan, self::SCAN_TTL );

		return $scan;
	}

	/**
	 * Translate the scanned strings through every configured engine.
	 *
	 * One engine failing is reported against that engine and the rest of the
	 * comparison still runs: a broken tier is exactly what you might be
	 * trying to find out about.
	 *
	 * @return array|WP_Error
	 */
	public static function run() {
		$scan = get_transient( self::scan_key() );

		if ( ! is_array( $scan ) || empty( $scan['strings'] ) ) {
			return new WP_Error(
				'leadst_compare_expired',
				__( 'That scan has expired. Scan the page again.', 'leadstart-translate' )
			);
		}

		$engines = self::engines();
		$source  = leadst()->get( 'source_language', 'en' );
		$target  = $scan['target'];

		$columns = array();
		$errors  = array();

		// Comparison is a deliberate, admin-initiated call: the slow tiers
		// are allowed here even though this is not cron.
		add_filter( 'leadst_slow_tiers_allowed', '__return_true', 99 );

		foreach ( $engines as $id => $engine ) {
			if ( ( $engine->is_billable() && ! LEADST_Budget::reserve( (int) $scan['chars'] ) )
				|| ( $engine->uses_ai_budget() && ! LEADST_Budget::reserve_ai( (int) $scan['chars'] ) )
				|| ( $engine->uses_deepl_budget() && ! LEADST_Budget::reserve_deepl( (int) $scan['chars'] ) ) ) {
				$errors[ $id ] = __( 'This engine reached its configured character-volume guard before comparison.', 'leadstart-translate' );
				continue;
			}
			$result = $engine->translate( $scan['strings'], $target, $source );

			if ( is_wp_error( $result ) ) {
				$errors[ $id ] = $result->get_error_message();
				continue;
			}

			$columns[ $id ] = array_values( (array) $result );
		}

		remove_filter( 'leadst_slow_tiers_allowed', '__return_true', 99 );

		if ( empty( $columns ) ) {
			return new WP_Error(
				'leadst_compare_all_failed',
				__( 'Every engine failed: ', 'leadstart-translate' ) . implode( ' | ', $errors )
			);
		}

		$rows = array();

		foreach ( $scan['strings'] as $i => $source_text ) {
			$cells  = array();
			$values = array();

			foreach ( $columns as $id => $translations ) {
				$value       = isset( $translations[ $i ] ) ? (string) $translations[ $i ] : '';
				$cells[ $id ] = $value;

				if ( '' !== $value ) {
					$values[] = $value;
				}
			}

			$rows[] = array(
				'source' => $source_text,
				'cells'  => $cells,
				// Agreement is worth seeing at a glance: it is the rows where
				// engines disagree that are worth your attention.
				'agree'  => count( array_unique( $values ) ) <= 1,
			);
		}

		$result = array(
			'url'     => $scan['url'],
			'target'  => $target,
			'source'  => $source,
			'engines' => array_keys( $columns ),
			'errors'  => $errors,
			'rows'    => $rows,
			'agreed'  => count(
				array_filter(
					$rows,
					function ( $row ) {
						return $row['agree'];
					}
				)
			),
			'ran_at'  => time(),
		);

		delete_transient( self::scan_key() );
		set_transient( self::result_key(), $result, self::SCAN_TTL );

		return $result;
	}
}
