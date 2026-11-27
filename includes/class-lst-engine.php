<?php
/**
 * Translation engines.
 *
 * Five primary engines are available. Cloud Translation v2 is the default: an API key, Google's
 * neural model, one flat per-character rate. Gemini is the optional higher
 * quality path — it takes the whole batch as context, honors a do-not-translate
 * glossary, and can be told the register to write in. Both authenticate with a
 * plain API key, so neither needs a service-account JSON file on disk.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all translation engines.
 */
abstract class LEADST_Engine {

	/**
	 * Translate a batch of strings.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language slug.
	 * @param string   $source Source language slug.
	 * @return array|WP_Error Same-length list of translations, or an error.
	 */
	abstract public function translate( array $texts, $target, $source );

	/**
	 * Whether this engine has the credentials it needs.
	 *
	 * @return bool
	 */
	abstract public function is_configured();

	/**
	 * A short, stable identifier recorded against every string this engine
	 * translates, so the editor and the status screen can say which tier
	 * produced a given translation rather than leaving it a mystery.
	 *
	 * @return string
	 */
	public function id() {
		return 'unknown';
	}

	/**
	 * How the engine should be named on screen.
	 *
	 * @param string $id Engine identifier.
	 * @return string
	 */
	public static function label_for( $id ) {
		$labels = array(
			'google_v2' => __( 'Cloud Translation', 'leadstart-translate' ),
			'gemini'    => __( 'Gemini', 'leadstart-translate' ),
			'mistral'   => __( 'Mistral AI', 'leadstart-translate' ),
			'qwen'      => __( 'Qwen (Alibaba Cloud)', 'leadstart-translate' ),
			'deepl'     => __( 'DeepL API', 'leadstart-translate' ),
			'fallback'  => __( 'Self-hosted fallback service', 'leadstart-translate' ),
			'ai_client' => __( 'WordPress AI Client', 'leadstart-translate' ),
			'ultimate'  => __( 'Bulk LLM', 'leadstart-translate' ),
			'human'     => __( 'Hand-edited', 'leadstart-translate' ),
		);

		$id = (string) $id;

		return isset( $labels[ $id ] ) ? $labels[ $id ] : __( 'Unrecorded', 'leadstart-translate' );
	}

	/**
	 * Whether this engine counts against the Google Cloud Translation character budget.
	 *
	 * @return bool
	 */
	public function is_billable() {
		return true;
	}

	/** Whether token-priced AI calls use the shared AI character-volume guard. */
	public function uses_ai_budget() { return false; }

	/** DeepL reservations are separate from Google's and token-priced AI providers'. */
	public function uses_deepl_budget() { return false; }

	/**
	 * The engine a batch of this size is allowed to use, in tier order.
	 *
	 * Selected primary engine when it is configured and within any applicable
	 * Google Cloud character cap; then optional background-only fallback tiers. Null when no
	 * tier is available, in which case the strings stay queued and are
	 * retried later — exactly what already happened at the cap.
	 *
	 * A tier that is switched on but not actually configured is skipped
	 * rather than returned, so a blank fallback URL cannot swallow the chain
	 * and stop the LLM tier from ever being reached.
	 *
	 * Tiers 2 and 3 are SLOW. The fallback service translates one string at a
	 * time behind a polite delay; a fifty-string batch is twenty seconds at
	 * best and the HTTP timeout is two minutes. That is fine in cron and
	 * unacceptable in front of a visitor, so by default they are offered only
	 * to background work. A visitor past the cap sees source-language text
	 * and the strings stay queued, which is the behavior the cap already had.
	 *
	 * If the pre-fill crawler reaches this code by requesting its own URLs
	 * over HTTP rather than from cron, hook leadst_slow_tiers_allowed so those
	 * requests count as background. See MANUAL-EDITS.md.
	 *
	 * @param int $chars Characters about to be sent.
	 * @return LEADST_Engine|null
	 */
	public static function make_for( $chars, $target = '' ) {
		$primary = self::make();

		if ( $primary->is_configured()
			&& ( ! $primary->uses_deepl_budget() || '' === $target || $primary->supports_target( $target ) )
			&& ( $primary->is_billable() ? LEADST_Budget::reserve( (int) $chars ) : ( $primary->uses_ai_budget() ? LEADST_Budget::reserve_ai( (int) $chars ) : ( $primary->uses_deepl_budget() ? LEADST_Budget::reserve_deepl( (int) $chars ) : true ) ) ) ) {
			return $primary;
		}

		$background = ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'WP_CLI' ) && WP_CLI );

		/**
	 * Whether the slow fallback tiers may run in this request.
	 *
	 * @param bool $background True in cron and WP-CLI.
	 * @param int  $chars      Characters about to be sent.
	 */
		if ( ! apply_filters( 'leadst_slow_tiers_allowed', $background, $chars ) ) {
			return null;
		}

		// DeepL is the first opt-in background fallback when another engine is primary.
		$deepl = new LEADST_Engine_DeepL();
		if ( 'deepl' !== $primary->id() && leadst()->get( 'deepl_fallback_enabled' )
			&& $deepl->is_configured() && ( '' === $target || $deepl->supports_target( $target ) )
			&& LEADST_Budget::reserve_deepl( (int) $chars ) ) {
			return $deepl;
		}

		$fallback = new LEADST_Engine_Fallback();

		if ( $fallback->is_configured() ) {
			return $fallback;
		}

		$ai_client = new LEADST_Engine_AI_Client();

		if ( $ai_client->is_configured() && LEADST_Budget::reserve_ai( (int) $chars ) ) {
			return $ai_client;
		}

		$ultimate = new LEADST_Engine_Ultimate();

		if ( $ultimate->is_configured() && LEADST_Budget::reserve_ai( (int) $chars ) ) {
			return $ultimate;
		}

		return null;
	}

	/**
	 * When an already-selected primary fails, try the opt-in DeepL fallback
	 * once in background work. Its usage is reserved independently and calls
	 * are never executed automatically during ordinary visitor pageviews.
	 *
	 * @return array{0:LEADST_Engine,1:array|WP_Error}
	 */
	public static function recover_with_deepl( $engine, $result, array $texts, $target, $source ) {
		if ( ! is_wp_error( $result ) || 'deepl' === $engine->id() || ! leadst()->get( 'deepl_fallback_enabled' ) ) {
			return array( $engine, $result );
		}
		$background = ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI );
		$chars = 0;
		foreach ( $texts as $text ) { $chars += LEADST_DB::char_count( $text ); }
		if ( ! apply_filters( 'leadst_slow_tiers_allowed', $background, $chars ) ) { return array( $engine, $result ); }
		$deepl = new LEADST_Engine_DeepL();
		if ( ! $deepl->is_configured() || ! $deepl->supports_target( $target ) || ! LEADST_Budget::reserve_deepl( $chars ) ) {
			return array( $engine, $result );
		}
		$translated = $deepl->translate( $texts, $target, $source );
		return is_wp_error( $translated ) ? array( $engine, $result ) : array( $deepl, $translated );
	}

	/**
	 * Build the configured engine.
	 *
	 * @param string|null $which Force a specific engine.
	 * @return LEADST_Engine
	 */
	public static function make( $which = null ) {
		$which = $which ? $which : leadst()->get( 'engine', 'google_v2' );

		switch ( $which ) {
			case 'gemini': return new LEADST_Engine_Gemini();
			case 'ai_client': return new LEADST_Engine_AI_Client();
			case 'mistral': return new LEADST_Engine_Mistral();
			case 'qwen': return new LEADST_Engine_Qwen();
			case 'deepl': return new LEADST_Engine_DeepL();
			default: return new LEADST_Engine_Google_V2();
		}
	}

	/**
	 * Google returns HTML entities even for plain-text requests.
	 *
	 * @param string $text Raw API output.
	 * @return string
	 */
	protected function decode( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Pause between retries — but only where nobody is waiting.
	 *
	 * A visitor must never be held for several seconds because Google returned a
	 * 429. During a pageview the retry happens immediately, and if it fails the
	 * string is simply left for the background worker, which can afford to wait.
	 *
	 * @param int $seconds Seconds to pause.
	 * @return void
	 */
	protected function backoff( $seconds ) {
		$background = ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'WP_CLI' ) && WP_CLI );

		if ( $background ) {
			sleep( max( 1, (int) $seconds ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_sleep -- background worker only, never during a pageview.
		}
	}

	/**
	 * Terms that must survive translation untouched.
	 *
	 * @return string[]
	 */
	protected function preserved_terms() {
		$raw   = (string) leadst()->get( 'preserve_terms', '' );
		$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
		return array_values( $lines );
	}
}

/**
 * Cloud Translation v2, authenticated with an API key.
 */
class LEADST_Engine_Google_V2 extends LEADST_Engine {

	/**
	 * @return string
	 */
	public function id() {
		return 'google_v2';
	}

	const ENDPOINT     = 'https://translation.googleapis.com/language/translate/v2';
	const MAX_SEGMENTS = 100;
	const MAX_CHARS    = 18000;

	/**
	 * Whether an API key is present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) leadst()->get( 'google_api_key', '' ) );
	}

	/**
	 * Translate a batch.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language slug.
	 * @param string   $source Source language slug.
	 * @return array|WP_Error
	 */
	public function translate( array $texts, $target, $source ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'leadst_no_key', __( 'No Google Cloud Translation API key is saved.', 'leadstart-translate' ) );
		}

		$target_lang = LEADST_Languages::get( $target );
		$source_lang = LEADST_Languages::get( $source );

		if ( ! $target_lang ) {
			return new WP_Error( 'leadst_bad_lang', __( 'Unknown target language.', 'leadstart-translate' ) );
		}

		$texts  = array_values( $texts );
		$output = array();

		foreach ( $this->chunk( $texts ) as $chunk ) {
			$result = $this->request( $chunk, $target_lang['google'], $source_lang ? $source_lang['google'] : 'en' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$output = array_merge( $output, $result );
		}

		if ( count( $output ) !== count( $texts ) ) {
			return new WP_Error(
				'leadst_count_mismatch',
				__( 'The API returned a different number of strings than were sent.', 'leadstart-translate' )
			);
		}

		return $output;
	}

	/**
	 * Split a batch so no request exceeds the segment or character limit.
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
	 * One HTTP call, with a short retry on transient failures.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Google target code.
	 * @param string   $source Google source code.
	 * @return array|WP_Error
	 */
	protected function request( array $texts, $target, $source ) {
		$key  = trim( (string) leadst()->get( 'google_api_key', '' ) );
		$body = array(
			'q'      => $texts,
			'target' => $target,
			'source' => $source,
			'format' => 'text',
			'model'  => 'nmt',
		);

		$attempts = 0;
		$last     = null;

		while ( $attempts < 3 ) {
			$attempts++;

			$response = wp_safe_remote_post(
				add_query_arg( 'key', rawurlencode( $key ), self::ENDPOINT ),
				array(
					'timeout' => 45,
					'redirection' => 0,
					'sslverify' => true,
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last = $response;
				$this->backoff( $attempts );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$json = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && isset( $json['data']['translations'] ) ) {
				$out = array();
				foreach ( $json['data']['translations'] as $item ) {
					$out[] = $this->decode( isset( $item['translatedText'] ) ? $item['translatedText'] : '' );
				}
				return $out;
			}

			$message = isset( $json['error']['message'] )
				? $json['error']['message']
				: sprintf( 'HTTP %d', $code );

			// 429 and 5xx are worth retrying; 400/403 are configuration errors.
			if ( 429 === $code || $code >= 500 ) {
				$last = new WP_Error( 'leadst_api_retry', $message );
				$this->backoff( $attempts * 2 );
				continue;
			}

			return new WP_Error( 'leadst_api_error', $message, array( 'status' => $code ) );
		}

		return $last ? $last : new WP_Error( 'leadst_api_error', __( 'Translation request failed.', 'leadstart-translate' ) );
	}
}

/**
 * Gemini, used as a translation engine.
 */
class LEADST_Engine_Gemini extends LEADST_Engine {

	/**
	 * @return string
	 */
	public function id() {
		return 'gemini';
	}

	/** Gemini charges per token, not Google Cloud Translation characters. */
	public function is_billable() { return false; }

	public function uses_ai_budget() { return true; }

	// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Deliberate user-key Gemini model/JSON translation adapter alongside the independent WordPress AI Client engine.
	const ENDPOINT     = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
	const MAX_SEGMENTS = 60;
	const MAX_CHARS    = 12000;

	/**
	 * Whether an API key is present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) leadst()->get( 'gemini_api_key', '' ) );
	}

	/**
	 * Translate a batch.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language slug.
	 * @param string   $source Source language slug.
	 * @return array|WP_Error
	 */
	public function translate( array $texts, $target, $source ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'leadst_no_key', __( 'No Gemini API key is saved.', 'leadstart-translate' ) );
		}

		$target_lang = LEADST_Languages::get( $target );
		if ( ! $target_lang ) {
			return new WP_Error( 'leadst_bad_lang', __( 'Unknown target language.', 'leadstart-translate' ) );
		}

		$source_lang = LEADST_Languages::get( $source );
		$texts       = array_values( $texts );
		$output      = array();

		foreach ( $this->chunk( $texts ) as $chunk ) {
			$result = $this->request(
				$chunk,
				$target_lang['name'],
				$source_lang ? $source_lang['name'] : 'English'
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$output = array_merge( $output, $result );
		}

		if ( count( $output ) !== count( $texts ) ) {
			return new WP_Error( 'leadst_count_mismatch', __( 'Gemini returned the wrong number of strings.', 'leadstart-translate' ) );
		}

		return $output;
	}

	/**
	 * Split a batch to stay inside a comfortable prompt size.
	 *
	 * @param string[] $texts Source strings.
	 * @return array
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
	 * One generateContent call.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language name.
	 * @param string   $source Source language name.
	 * @return array|WP_Error
	 */
	protected function request( array $texts, $target, $source ) {
		$key   = trim( (string) leadst()->get( 'gemini_api_key', '' ) );
		$model = (string) leadst()->get( 'gemini_model', 'gemini-2.5-flash' );

		$preserve = $this->preserved_terms();
		$glossary = $preserve
			? "Leave these exactly as written, untranslated: " . implode( ', ', $preserve ) . ".\n"
			: '';

		$instructions = "You are translating strings from a website from {$source} into {$target}.\n"
			. "Return a JSON array of strings, the same length as the input array, in the same order.\n"
			. "Translate each element independently. Do not merge, split, reorder, or add elements.\n"
			. "Preserve leading and trailing whitespace, punctuation, capitalization style, and any\n"
			. "placeholders such as %s, {name}, or [shortcode]. If an element is a proper noun, a number,\n"
			. "a URL, or an email address, return it unchanged.\n"
			. $glossary
			. "Input array:\n" . wp_json_encode( array_values( $texts ), JSON_UNESCAPED_UNICODE );

		$body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $instructions ) ),
				),
			),
			'generationConfig' => array(
				'temperature'      => 0.1,
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'  => 'ARRAY',
					'items' => array( 'type' => 'STRING' ),
				),
			),
		);

		$url      = sprintf( self::ENDPOINT, rawurlencode( $model ) );
		$attempts = 0;
		$last     = null;

		while ( $attempts < 3 ) {
			$attempts++;

			$response = wp_safe_remote_post(
				$url,
				array(
					'timeout' => 90,
					'redirection' => 0,
					'sslverify' => true,
					'headers' => array(
						'Content-Type'   => 'application/json; charset=utf-8',
						'x-goog-api-key' => $key,
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last = $response;
				$this->backoff( $attempts );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$json = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code ) {
				$text = isset( $json['candidates'][0]['content']['parts'][0]['text'] )
					? $json['candidates'][0]['content']['parts'][0]['text']
					: '';

				$parsed = json_decode( $text, true );

				if ( is_array( $parsed ) && count( $parsed ) === count( $texts ) ) {
					return array_map( 'strval', $parsed );
				}

				$last = new WP_Error( 'leadst_bad_json', __( 'Gemini returned output that could not be matched to the input.', 'leadstart-translate' ) );
				$this->backoff( $attempts );
				continue;
			}

			$message = isset( $json['error']['message'] ) ? $json['error']['message'] : sprintf( 'HTTP %d', $code );

			if ( 429 === $code || $code >= 500 ) {
				$last = new WP_Error( 'leadst_api_retry', $message );
				$this->backoff( $attempts * 2 );
				continue;
			}

			return new WP_Error( 'leadst_api_error', $message, array( 'status' => $code ) );
		}

		return $last ? $last : new WP_Error( 'leadst_api_error', __( 'Translation request failed.', 'leadstart-translate' ) );
	}
}
