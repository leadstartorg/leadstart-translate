<?php
/**
 * WordPress AI Client engine — an optional tier of the chain.
 *
 * Translates through core's own AI Client (WordPress 7.0) rather than a
 * provider this plugin talks to directly. The site owner configures one AI
 * provider in WordPress and every plugin on the site uses it, so there is no
 * additional API key to store here and no second place to rotate it.
 *
 * Spend lands on whatever provider WordPress is pointed at, so it is metered
 * separately and never consumes the Google Translation caps.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translation through core's AI Client.
 */
class LEADST_Engine_AI_Client extends LEADST_Engine {

	/**
	 * @return string
	 */
	public function id() {
		return 'ai_client';
	}

	/**
	 * Strings per request.
	 */
	const MAX_SEGMENTS = 60;

	/**
	 * Characters per request.
	 */
	const MAX_CHARS = 30000;

	/**
	 * Seconds to allow one generation. The AI Client defaults to 30, which a
	 * sixty-string batch will outrun.
	 */
	const TIMEOUT = 120;

	/**
	 * Whether the tier is switched on and core can actually run a prompt.
	 *
	 * Deliberately local: it asks whether the function exists and whether the
	 * environment supports AI at all, both local checks. Whether a provider
	 * can serve this particular prompt is settled by generate_text(), which
	 * returns a WP_Error rather than throwing, so asking twice would just add
	 * a round trip to every call.
	 *
	 * @return bool
	 */
	public function is_configured() {
		if ( 1 !== (int) leadst()->get( 'ai_client_enabled' ) && 'ai_client' !== leadst()->get( 'engine' ) ) {
			return false;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) { return false; }

		$builder = wp_ai_client_prompt( 'test' );
		return is_object( $builder ) && method_exists( $builder, 'is_supported_for_text_generation' )
			&& $builder->is_supported_for_text_generation();
	}

	/**
	 * Provider spend is not Google spend, so it must not consume those caps.
	 *
	 * @return bool
	 */
	public function is_billable() {
		return false;
	}

	public function uses_ai_budget() { return true; }

	/**
	 * Translate a batch.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language slug.
	 * @param string   $source Source language slug.
	 * @return array|WP_Error Same-length list of translations, or an error.
	 */
	public function translate( array $texts, $target, $source ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'leadst_ai_client_off',
				__( 'The WordPress AI Client tier is not available on this site.', 'leadstart-translate' )
			);
		}

		$target_lang = LEADST_Languages::get( $target );
		if ( ! $target_lang ) {
			return new WP_Error( 'leadst_bad_lang', __( 'Unknown target language.', 'leadstart-translate' ) );
		}

		$source_lang = LEADST_Languages::get( $source );
		$texts       = array_values( $texts );
		$output      = array();
		$chars       = 0;

		foreach ( $texts as $text ) {
			$chars += LEADST_DB::char_count( $text );
		}

		$target_name = $target_lang['name'];
		$source_name = $source_lang ? $source_lang['name'] : 'English';

		add_filter( 'wp_ai_client_default_request_timeout', array( $this, 'filter_timeout' ) );

		foreach ( $this->chunk( $texts ) as $chunk ) {
			$result = $this->request( $chunk, $target_name, $source_name );

			if ( is_wp_error( $result ) ) {
				remove_filter( 'wp_ai_client_default_request_timeout', array( $this, 'filter_timeout' ) );
				return $result;
			}

			$output = array_merge( $output, $result );
		}

		remove_filter( 'wp_ai_client_default_request_timeout', array( $this, 'filter_timeout' ) );

		if ( count( $output ) !== count( $texts ) ) {
			return new WP_Error(
				'leadst_count_mismatch',
				__( 'The AI Client returned a different number of strings than were sent.', 'leadstart-translate' )
			);
		}

		$this->record_usage( $chars );

		return $output;
	}

	/**
	 * Raise the AI Client's request timeout for our batches.
	 *
	 * @param float $timeout Default timeout in seconds.
	 * @return float
	 */
	public function filter_timeout( $timeout ) {
		return max( (float) $timeout, (float) self::TIMEOUT );
	}

	/**
	 * One generation call.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language name.
	 * @param string   $source Source language name.
	 * @return array|WP_Error
	 */
	private function request( array $texts, $target, $source ) {
		$builder = wp_ai_client_prompt( $this->prompt( $texts, $target, $source ) );

		// Generating methods return WP_Error rather than throwing; a builder
		// already in an error state hands that same error back here.
		$text = $builder->generate_text();

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		if ( ! is_string( $text ) ) {
			$text = is_object( $text ) && method_exists( $text, '__toString' ) ? (string) $text : '';
		}

		$parsed = $this->decode_list( $text );

		if ( ! is_array( $parsed ) || count( $parsed ) !== count( $texts ) ) {
			return new WP_Error(
				'leadst_bad_json',
				__( 'The AI Client returned output that could not be matched to the input.', 'leadstart-translate' )
			);
		}

		return array_map( 'strval', $parsed );
	}

	/**
	 * Pull a JSON array of strings out of a model's reply.
	 *
	 * Unlike the direct Gemini call there is no response schema to force the
	 * shape, because the provider behind the AI Client is whatever the site
	 * chose. Models commonly wrap JSON in a fenced block or add a sentence
	 * either side, so the outermost array is extracted before decoding.
	 *
	 * @param string $text Raw reply.
	 * @return array|null
	 */
	private function decode_list( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return null;
		}

		$parsed = json_decode( $text, true );

		if ( is_array( $parsed ) ) {
			return $this->flatten( $parsed );
		}

		$start = strpos( $text, '[' );
		$end   = strrpos( $text, ']' );

		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$parsed = json_decode( substr( $text, $start, $end - $start + 1 ), true );

		return is_array( $parsed ) ? $this->flatten( $parsed ) : null;
	}

	/**
	 * Accept a bare array, or one wrapped in a single-key object.
	 *
	 * Providers asked for JSON sometimes answer {"translations": [...]}.
	 *
	 * @param array $parsed Decoded JSON.
	 * @return array|null
	 */
	private function flatten( array $parsed ) {
		if ( $parsed === array_values( $parsed ) ) {
			return $parsed;
		}

		if ( 1 === count( $parsed ) ) {
			$inner = reset( $parsed );
			if ( is_array( $inner ) && $inner === array_values( $inner ) ) {
				return $inner;
			}
		}

		return null;
	}

	/**
	 * Split a batch to a comfortable prompt size.
	 *
	 * @param string[] $texts Source strings.
	 * @return array List of chunks.
	 */
	protected function chunk( array $texts ) {
		$chunks  = array();
		$current = array();
		$chars   = 0;

		foreach ( $texts as $text ) {
			$len = LEADST_DB::char_count( $text );

			if ( $current && ( count( $current ) >= self::MAX_SEGMENTS || ( $chars + $len ) > self::MAX_CHARS ) ) {
				$chunks[] = $current;
				$current  = array();
				$chars    = 0;
			}

			$current[] = $text;
			$chars    += $len;
		}

		if ( $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * The instruction sent to the model.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language name.
	 * @param string   $source Source language name.
	 * @return string
	 */
	private function prompt( array $texts, $target, $source ) {
		$preserve = $this->preserved_terms();
		$glossary = $preserve
			? 'Leave these exactly as written, untranslated: ' . implode( ', ', $preserve ) . ".\n"
			: '';

		return "You are translating strings from a website from {$source} into {$target}.\n"
			. "Translate each element of the JSON array independently. Do not merge, split, reorder, or add elements.\n"
			. "Preserve punctuation, capitalization style, and placeholders such as %s, {name}, or [shortcode].\n"
			. "Return proper nouns, numbers, URLs and email addresses unchanged.\n"
			. $glossary
			. "Return only a JSON array of strings, the same length as the input, in the same order.\n"
			. 'Do not wrap the array in an object and do not add any commentary.' . "\n"
			. 'Input array: ' . wp_json_encode( $texts, JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Meter characters sent through the AI Client, separately from Google.
	 *
	 * @param int $chars Characters sent.
	 * @return void
	 */
	private function record_usage( $chars ) {
		$month = current_time( 'Y-m' );
		$data  = get_option( 'leadst_ai_client_usage', array() );

		if ( ! is_array( $data ) || ! isset( $data['month'] ) || $data['month'] !== $month ) {
			$data = array(
				'month' => $month,
				'chars' => 0,
				'total' => isset( $data['total'] ) ? (int) $data['total'] : 0,
			);
		}

		$data['chars'] += (int) $chars;
		$data['total']  = ( isset( $data['total'] ) ? (int) $data['total'] : 0 ) + (int) $chars;

		update_option( 'leadst_ai_client_usage', $data, false );
	}
}
