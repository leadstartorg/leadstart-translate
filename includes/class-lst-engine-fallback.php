<?php
/**
 * Leadstart Translate fallback chain — build r5-a699c3f7
 *
 * Optional self-hosted fallback engine — tier 2 of the chain.
 *
 * Takes over the moment LEADST_Budget::can_spend() refuses a batch, so a site
 * can continue through an administrator-operated service. Its operating
 * costs and data-handling policies depend on the service administrator.
 * For example, it may connect to a private LibreTranslate deployment.
 *
 * Keeping the backend behind a service rather than in PHP is deliberate: the
 * choice of backend, its keys and its rate limiting all change without
 * touching the plugin, and nothing unofficial is ever called from WordPress
 * itself.
 *
 * Output lands in the same translation memory as every other tier, so a string
 * this engine produced is never sent anywhere again.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translation through a self-hosted fallback service.
 */
class LEADST_Engine_Fallback extends LEADST_Engine {

	/**
	 * @return string
	 */
	public function id() {
		return 'fallback';
	}

	/**
	 * Strings per request. The service may be translating one at a time behind
	 * a polite delay, so batches are kept small enough to finish inside the
	 * HTTP timeout.
	 */
	const MAX_SEGMENTS = 50;

	/**
	 * Characters per request.
	 */
	const MAX_CHARS = 20000;

	/**
	 * Seconds to wait for the service.
	 */
	const TIMEOUT = 120;

	/**
	 * Whether the tier is switched on and has somewhere to call.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return 1 === (int) leadst()->get( 'fallback_enabled' ) && '' !== $this->endpoint();
	}

	/**
	 * This tier has independent operating costs. It does not use the Google
	 * Cloud Translation character allowance.
	 *
	 * @return bool
	 */
	public function is_billable() {
		return false;
	}

	/**
	 * The configured service URL.
	 *
	 * @return string Empty when unset or not a valid http(s) URL.
	 */
	private function endpoint() {
		$url = trim( (string) leadst()->get( 'fallback_url', '' ) );

		if ( '' === $url ) {
			return '';
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) { return ''; }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return ''; }
		// Explicit HTTP is supported only for loopback development services.
		if ( 'http' === $scheme && ! in_array( strtolower( $parts['host'] ), array( '127.0.0.1', 'localhost', '::1' ), true ) ) { return ''; }
		return $url;
	}

	/**
	 * The shared token, sent on every request.
	 *
	 * @return string
	 */
	private function token() {
		return trim( (string) leadst()->get( 'fallback_token', '' ) );
	}

	/**
	 * A sibling path on the same service, e.g. /health next to /translate.
	 *
	 * @param string $path Path to swap in.
	 * @return string
	 */
	private function sibling( $path ) {
		$url = $this->endpoint();

		if ( '' === $url ) {
			return '';
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '127.0.0.1';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return $scheme . '://' . $host . $port . '/' . ltrim( $path, '/' );
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
			return new WP_Error( 'leadst_fallback_off', __( 'The fallback engine is not configured.', 'leadstart-translate' ) );
		}

		$target_lang = LEADST_Languages::get( $target );
		if ( ! $target_lang ) {
			return new WP_Error( 'leadst_bad_lang', __( 'Unknown target language.', 'leadstart-translate' ) );
		}

		$source_lang = LEADST_Languages::get( $source );

		// Send the same codes the Cloud Translation engine uses. The service
		// maps them onto whatever its backend expects.
		$target_code = $target_lang['google'];
		$source_code = $source_lang ? $source_lang['google'] : 'en';

		$texts  = array_values( $texts );
		$output = array();

		foreach ( $this->chunk( $texts ) as $chunk ) {
			$result = $this->request( $chunk, $target_code, $source_code );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$output = array_merge( $output, $result );
		}

		if ( count( $output ) !== count( $texts ) ) {
			return new WP_Error( 'leadst_count_mismatch', __( 'The fallback service returned a different number of strings than were sent.', 'leadstart-translate' ) );
		}

		return $output;
	}

	/**
	 * Split a batch to a size the service can finish inside the timeout.
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
	 * One call to the service, with a single retry on a transient failure.
	 *
	 * A 400, 401, 403 or 404 is configuration, not weather: it is returned
	 * straight away with its status attached so the queue worker stops rather
	 * than spinning against a bad token or a wrong URL.
	 *
	 * A 422 is the service saying its backend does not serve this language
	 * pair — Some self-hosted backends cover fewer language pairs. That is
	 * permanent for this pair and routine across a large language set, so it
	 * is returned without a retry, but with a status the queue worker does
	 * NOT treat as fatal: this batch fails, every other language carries on.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $target Target language code.
	 * @param string   $source Source language code.
	 * @return array|WP_Error
	 */
	private function request( array $texts, $target, $source ) {
		$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
		$token   = $this->token();

		if ( '' !== $token ) {
			$headers['X-WPTR-Fallback-Token'] = $token;
		}

		$args = array(
			'timeout' => self::TIMEOUT,
			'redirection' => 0,
			'sslverify' => true,
			'headers' => $headers,
			'body'    => wp_json_encode(
				array(
					'texts'  => $texts,
					'source' => $source,
					'target' => $target,
				),
				JSON_UNESCAPED_UNICODE
			),
		);

		$attempts = 0;

		while ( $attempts < 2 ) {
			$attempts++;

			$response = 'http' === wp_parse_url( $this->endpoint(), PHP_URL_SCHEME )
				? wp_remote_post( $this->endpoint(), $args )
				: wp_safe_remote_post( $this->endpoint(), $args );

			if ( is_wp_error( $response ) ) {
				if ( 2 === $attempts ) {
					return $response;
				}
				$this->backoff( $attempts );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$json = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && is_array( $json ) && isset( $json['translations'] ) && is_array( $json['translations'] ) ) {
				if ( count( $json['translations'] ) !== count( $texts ) ) {
					return new WP_Error(
						'leadst_count_mismatch',
						__( 'The fallback service returned a different number of strings than were sent.', 'leadstart-translate' ),
						array( 'status' => $code )
					);
				}

				return array_map( 'strval', $json['translations'] );
			}

			$message = '';

			if ( is_array( $json ) ) {
				if ( isset( $json['detail'] ) && is_string( $json['detail'] ) ) {
					$message = $json['detail'];
				} elseif ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
					$message = $json['error'];
				}
			}

			if ( '' === $message ) {
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'The fallback service answered with HTTP %d.', 'leadstart-translate' ), $code );
			}

			// Configuration errors and unsupported language pairs are final.
			// Anything else gets one retry.
			if ( ! in_array( $code, array( 400, 401, 403, 404, 422 ), true ) && 1 === $attempts ) {
				$this->backoff( 2 );
				continue;
			}

			return new WP_Error( 'leadst_fallback_error', $message, array( 'status' => $code ) );
		}

		return new WP_Error( 'leadst_fallback_error', __( 'The fallback service could not be reached.', 'leadstart-translate' ) );
	}

	/**
	 * Ask the service whether it is up and which backend it is using.
	 *
	 * @return array|WP_Error Decoded /health body, or an error.
	 */
	public function health() {
		$url = $this->sibling( 'health' );

		if ( '' === $url ) {
			return new WP_Error( 'leadst_fallback_off', __( 'No fallback service URL is set.', 'leadstart-translate' ) );
		}

		$headers = array();
		$token   = $this->token();

		if ( '' !== $token ) {
			$headers['X-WPTR-Fallback-Token'] = $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'redirection' => 0,
				'sslverify' => true,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $json ) ) {
			return new WP_Error(
				'leadst_fallback_error',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'The fallback service answered with HTTP %d.', 'leadstart-translate' ), $code ),
				array( 'status' => $code )
			);
		}

		return $json;
	}
}
