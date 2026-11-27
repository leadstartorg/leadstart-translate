<?php
/**
 * DeepL API translation engine, opt-in as either primary or background fallback.
 * Fixed provider hosts; does not accept arbitrary request URLs or follow redirects.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

class LEADST_Engine_DeepL extends LEADST_Engine {
	const MAX_TEXTS = 50;
	const MAX_BODY_BYTES = 110000; // Keep beneath DeepL's 128 KiB API request bound.

	public function id() { return 'deepl'; }
	public function is_billable() { return false; } // DeepL has its own counters, not Google's.
	public function uses_deepl_budget() { return true; }

	public function is_configured() {
		return '' !== trim( (string) leadst()->get( 'deepl_api_key', '' ) );
	}

	private function api_key() {
		return trim( (string) leadst()->get( 'deepl_api_key', '' ) );
	}

	public function endpoint() {
		$key = $this->api_key();
		return substr( $key, -3 ) === ':fx' ? 'https://api-free.deepl.com/v2/' : 'https://api.deepl.com/v2/';
	}

	/** Convert WordPress language IDs to target codes without assuming 194 supported pairs. */
	public static function target_code( $lang ) {
		$code = strtolower( str_replace( '_', '-', trim( (string) $lang ) ) );
		$mapped = array(
			'zh' => 'ZH-HANS', 'zh-cn' => 'ZH-HANS', 'zh-hans' => 'ZH-HANS',
			'zh-tw' => 'ZH-HANT', 'zh-hant' => 'ZH-HANT', 'pt' => 'PT-PT',
			'en' => 'EN-US', 'no' => 'NB', 'nb' => 'NB',
		);
		if ( isset( $mapped[ $code ] ) ) { return $mapped[ $code ]; }
		if ( preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,5})?$/', $code ) ) {
			return strtoupper( $code );
		}
		return '';
	}

	/** Source codes exclude regional variants unsupported for source_lang. */
	private static function source_code( $lang ) {
		$code = strtolower( str_replace( '_', '-', (string) $lang ) );
		$base = explode( '-', $code )[0];
		if ( 'no' === $base ) { return 'NB'; }
		return preg_match( '/^[a-z]{2,3}$/', $base ) ? strtoupper( $base ) : '';
	}

	/** Query provider's authoritative target list; cache by key fingerprint for one day. */
	private function supported_codes() {
		if ( ! $this->is_configured() ) { return array(); }
		$cache_key = 'leadst_deepl_lang_' . substr( hash( 'sha256', $this->api_key() ), 0, 20 );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) { return $cached; }
		$response = wp_remote_get( $this->endpoint() . 'languages?type=target', array(
			'timeout' => 12,
			'redirection' => 0,
			'sslverify' => true,
			'headers' => array( 'Authorization' => 'DeepL-Auth-Key ' . $this->api_key() ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return array(); }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) { return array(); }
		$codes = array();
		foreach ( $data as $row ) {
			if ( is_array( $row ) && isset( $row['language'] ) && preg_match( '/^[A-Z]{2,3}(?:-[A-Z0-9]+)?$/', $row['language'] ) ) {
				$codes[] = $row['language'];
			}
		}
		if ( $codes ) { set_transient( $cache_key, $codes, DAY_IN_SECONDS ); }
		return $codes;
	}

	public function supports_target( $target ) {
		$code = self::target_code( $target );
		return '' !== $code && in_array( $code, $this->supported_codes(), true );
	}

	public function translate( array $texts, $target, $source ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'leadst_deepl_unconfigured', __( 'Configure a DeepL API key before using DeepL.', 'leadstart-translate' ) );
		}
		$code = self::target_code( $target );
		if ( ! LEADST_Languages::exists( $target ) || ! $this->supports_target( $target ) ) {
			return new WP_Error( 'leadst_deepl_unsupported', __( 'DeepL does not currently offer this target language with the configured API key.', 'leadstart-translate' ) );
		}
		if ( ! $texts ) { return array(); }
		$all = array();
		$chunk = array();
		$bytes = 0;
		foreach ( $texts as $text ) {
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				return new WP_Error( 'leadst_deepl_input', __( 'DeepL input must be nonempty text.', 'leadstart-translate' ) );
			}
			$len = strlen( wp_json_encode( $text ) );
			if ( $len > self::MAX_BODY_BYTES - 1024 ) {
				return new WP_Error( 'leadst_deepl_too_large', __( 'One text segment exceeds the DeepL request size limit.', 'leadstart-translate' ) );
			}
			if ( $chunk && ( count( $chunk ) >= self::MAX_TEXTS || $bytes + $len > self::MAX_BODY_BYTES - 1024 ) ) {
				$translated = $this->request( $chunk, $code, self::source_code( $source ) );
				if ( is_wp_error( $translated ) ) { return $translated; }
				$all = array_merge( $all, $translated );
				$chunk = array(); $bytes = 0;
			}
			$chunk[] = $text; $bytes += $len;
		}
		if ( $chunk ) {
			$translated = $this->request( $chunk, $code, self::source_code( $source ) );
			if ( is_wp_error( $translated ) ) { return $translated; }
			$all = array_merge( $all, $translated );
		}
		return count( $all ) === count( $texts ) ? $all : new WP_Error( 'leadst_deepl_mismatch', __( 'DeepL returned a different number of translations.', 'leadstart-translate' ) );
	}

	private function request( array $texts, $target_code, $source_code ) {
		$payload = array( 'text' => array_values( $texts ), 'target_lang' => $target_code, 'preserve_formatting' => true );
		if ( $source_code ) { $payload['source_lang'] = $source_code; }
		$response = wp_remote_post( $this->endpoint() . 'translate', array(
			'timeout' => 30, 'redirection' => 0, 'sslverify' => true,
			'headers' => array(
				'Authorization' => 'DeepL-Auth-Key ' . $this->api_key(),
				'Content-Type' => 'application/json', 'Accept' => 'application/json',
			),
			'body' => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $response ) ) { return $response; }
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'leadst_deepl_http_error', sprintf(
				/* translators: %d: HTTP status code from DeepL API. */
				__( 'DeepL translation API returned HTTP %d.', 'leadstart-translate' ), $status
			), array( 'status' => $status ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['translations'] ) || ! is_array( $data['translations'] ) || count( $data['translations'] ) !== count( $texts ) ) {
			return new WP_Error( 'leadst_deepl_response', __( 'DeepL returned an unexpected translation response.', 'leadstart-translate' ) );
		}
		$out = array();
		foreach ( $data['translations'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['text'] ) || ! is_string( $row['text'] ) || '' === trim( $row['text'] ) ) {
				return new WP_Error( 'leadst_deepl_response', __( 'DeepL returned an invalid translation.', 'leadstart-translate' ) );
			}
			$out[] = $row['text'];
		}
		return $out;
	}
}
