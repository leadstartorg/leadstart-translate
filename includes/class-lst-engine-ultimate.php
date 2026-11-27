<?php
/**
 * Leadstart Translate fallback chain — build r5-a699c3f7
 *
 * Ultimate fallback engine — bulk translation by LLM.
 *
 * The final optional background tier, after the selected engine, self-hosted
 * fallback service and WordPress AI Client (if enabled). Gemini Flash-Lite or GPT-4o mini translate
 * for a fraction of the Translation API's rate, which makes a heavy first fill
 * affordable at scale.
 *
 * Output is stored in the same translation memory as everything else, so even
 * this tier runs only once per unique phrase per language.
 *
 * Usage is metered separately (leadst_ultimate_usage) and never counts against
 * the Translation API caps.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bulk LLM translation (Gemini or OpenAI).
 */
class LEADST_Engine_Ultimate extends LEADST_Engine {

	/**
	 * @return string
	 */
	public function id() {
		return 'ultimate';
	}

	const MAX_SEGMENTS = 100;
	const MAX_CHARS    = 30000;

	/**
	 * Whether the tier is switched on with a key and model present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return 1 === (int) leadst()->get( 'ultimate_enabled' )
			&& '' !== trim( $this->key() )
			&& '' !== trim( $this->model() );
	}

	/**
	 * LLM spend is not Google-spend: it must never consume the Translation API
	 * caps. It is metered separately via record_usage().
	 *
	 * @return bool
	 */
	public function is_billable() {
		return false;
	}

	public function uses_ai_budget() { return true; }

	/**
	 * Which provider to call.
	 *
	 * @return string gemini or openai.
	 */
	private function provider() {
		return 'openai' === (string) leadst()->get( 'ultimate_provider', 'gemini' ) ? 'openai' : 'gemini';
	}

	/**
	 * The API key.
	 *
	 * @return string
	 */
	private function key() {
		return trim( (string) leadst()->get( 'ultimate_api_key', '' ) );
	}

	/**
	 * The model, with a sensible default per provider.
	 *
	 * Free text on purpose: any newer model drops in without a
	 * code change.
	 *
	 * @return string
	 */
	private function model() {
		$model = trim( (string) leadst()->get( 'ultimate_model', '' ) );

		if ( '' !== $model ) {
			return $model;
		}

		return 'openai' === $this->provider() ? 'gpt-4o-mini' : 'gemini-2.5-flash-lite';
	}

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
			return new WP_Error( 'leadst_ultimate_off', __( 'The ultimate engine is not configured.', 'leadstart-translate' ) );
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

		foreach ( $this->chunk( $texts ) as $chunk ) {
			$result = 'openai' === $this->provider()
				? $this->request_openai( $chunk, $target_name, $source_name )
				: $this->request_gemini( $chunk, $target_name, $source_name );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$output = array_merge( $output, $result );
		}

		if ( count( $output ) !== count( $texts ) ) {
			return new WP_Error( 'leadst_count_mismatch', __( 'The LLM returned a different number of strings than were sent.', 'leadstart-translate' ) );
		}

		$this->record_usage( $chars );

		return $output;
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
	 * Prompt shared by both providers.
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
			. 'Input array: ' . wp_json_encode( $texts, JSON_UNESCAPED_UNICODE );
	}

	/**
	 * One Gemini generateContent call.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language name.
	 * @param string   $source Source language name.
	 * @return array|WP_Error
	 */
	private function request_gemini( array $texts, $target, $source ) {
		$body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $this->prompt( $texts, $target, $source ) ) ),
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

		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Optional administrator-configured Gemini fallback with provider-specific JSON response schema.
		$url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model() ) . ':generateContent';
		$json = $this->dispatch(
			$url,
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'   => 'application/json; charset=utf-8',
					'x-goog-api-key' => $this->key(),
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$text   = isset( $json['candidates'][0]['content']['parts'][0]['text'] ) ? $json['candidates'][0]['content']['parts'][0]['text'] : '';
		$parsed = json_decode( $text, true );

		if ( ! is_array( $parsed ) || count( $parsed ) !== count( $texts ) ) {
			return new WP_Error( 'leadst_bad_json', __( 'Gemini returned output that could not be matched to the input.', 'leadstart-translate' ) );
		}

		return array_map( 'strval', $parsed );
	}

	/**
	 * One OpenAI chat completion call.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language name.
	 * @param string   $source Source language name.
	 * @return array|WP_Error
	 */
	private function request_openai( array $texts, $target, $source ) {
		$body = array(
			'model'           => $this->model(),
			'temperature'     => 0.1,
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'You are a translation engine. The user gives a JSON array of strings and a target language. Reply ONLY with a JSON object: {"translations": [...]} - one translation per input element, same length, same order.',
				),
				array(
					'role'    => 'user',
					'content' => 'Target language: ' . $target . "\n" . $this->prompt( $texts, $target, $source ),
				),
			),
		);

		$json = $this->dispatch(
			// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Optional administrator-configured OpenAI fallback with explicit response format; core AI Client remains an alternative.
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $this->key(),
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$content = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';
		$parsed  = json_decode( $content, true );

		if ( ! is_array( $parsed ) || ! isset( $parsed['translations'] ) || ! is_array( $parsed['translations'] ) || count( $parsed['translations'] ) !== count( $texts ) ) {
			return new WP_Error( 'leadst_bad_json', __( 'The LLM returned output that could not be matched to the input.', 'leadstart-translate' ) );
		}

		return array_map( 'strval', $parsed['translations'] );
	}

	/**
	 * One HTTP call with a single retry on rate limits and server errors.
	 *
	 * @param string $url  Endpoint.
	 * @param array  $args wp_remote_post arguments.
	 * @return array|WP_Error Decoded JSON body, or an error.
	 */
	private function dispatch( $url, array $args ) {
		$attempts = 0;

		while ( $attempts < 2 ) {
			$attempts++;

			$args['redirection'] = 0;
			$args['sslverify'] = true;
			$response = wp_safe_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				if ( 2 === $attempts ) {
					return $response;
				}
				$this->backoff( $attempts );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$json = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && is_array( $json ) ) {
				return $json;
			}

			$message = isset( $json['error']['message'] )
				? $json['error']['message']
				: sprintf( 'HTTP %d', $code );

			if ( ( 429 === $code || $code >= 500 ) && 1 === $attempts ) {
				$this->backoff( 3 );
				continue;
			}

			return new WP_Error( 'leadst_llm_error', $message, array( 'status' => $code ) );
		}

		return new WP_Error( 'leadst_llm_error', __( 'LLM translation request failed.', 'leadstart-translate' ) );
	}

	/**
	 * Meter this tier's own spend.
	 *
	 * @param int $chars Characters sent.
	 * @return void
	 */
	private function record_usage( $chars ) {
		$month = current_time( 'Y-m' );
		$data  = get_option( 'leadst_ultimate_usage', array() );

		if ( ! is_array( $data ) || ! isset( $data['month'] ) || $data['month'] !== $month ) {
			$data = array(
				'month' => $month,
				'chars' => 0,
				'total' => isset( $data['total'] ) ? (int) $data['total'] : 0,
			);
		}

		$data['chars'] += (int) $chars;
		$data['total']  = ( isset( $data['total'] ) ? (int) $data['total'] : 0 ) + (int) $chars;

		update_option( 'leadst_ultimate_usage', $data, false );
	}

	/**
	 * Characters sent to this tier, for the Status screen.
	 *
	 * @return array Array with 'month' and 'total'.
	 */
	public static function usage() {
		$data  = get_option( 'leadst_ultimate_usage', array() );
		$month = current_time( 'Y-m' );

		return array(
			'month' => ( is_array( $data ) && isset( $data['month'] ) && $data['month'] === $month ) ? (int) $data['chars'] : 0,
			'total' => ( is_array( $data ) && isset( $data['total'] ) ) ? (int) $data['total'] : 0,
		);
	}
}
