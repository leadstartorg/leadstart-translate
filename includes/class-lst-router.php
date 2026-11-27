<?php
/**
 * URL routing for language-prefixed paths.
 *
 * WordPress.com does not expose .htaccess or nginx config, so this cannot be
 * done with server rewrites the way GTranslate's paid add-on does. Instead the
 * language segment is stripped from REQUEST_URI before WordPress parses the
 * request, and WordPress then routes the remaining path exactly as it would
 * have done anyway. Every language URL is a real, crawlable URL served by this
 * install — no proxy, no third-party hostname.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects, strips and rebuilds language URLs.
 */
class LEADST_Router {

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Router|null
	 */
	private static $instance = null;

	/**
	 * Current language slug.
	 *
	 * @var string
	 */
	private $current = '';

	/**
	 * Path after the language prefix has been removed, with leading slash.
	 *
	 * @var string
	 */
	private $stripped_path = '/';

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * A redirect waiting for init, when wp_safe_redirect() exists.
	 *
	 * @var array|null
	 */
	private $pending_redirect = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Router
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Detect the language and rewrite the request.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted  = true;
		$this->current = leadst()->get( 'source_language', 'en' );

		if ( $this->should_skip() ) {
			return;
		}

		if ( 'subdomain' === $this->url_mode() ) {
			$this->boot_subdomain();
			return;
		}

		$this->boot_directory();
	}

	/**
	 * Which URL structure is configured.
	 *
	 * @return string directory or subdomain.
	 */
	public function url_mode() {
		return 'subdomain' === leadst()->get( 'url_mode', 'directory' ) ? 'subdomain' : 'directory';
	}

	/**
	 * Language-in-the-path routing: example.org/es/about-us/.
	 *
	 * @return void
	 */
	private function boot_directory() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$parts = wp_parse_url( $uri );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? $parts['query'] : '';

		$base = $this->base_path();
		if ( '/' !== $base && 0 === strpos( $path, $base ) ) {
			$relative = substr( $path, strlen( $base ) );
		} else {
			$relative = ltrim( $path, '/' );
		}

		$segments = explode( '/', trim( (string) $relative, '/' ) );
		$first    = isset( $segments[0] ) ? strtolower( $segments[0] ) : '';

		if ( '' === $first || ! LEADST_Languages::exists( $first ) ) {
			$this->stripped_path = $path;
			return;
		}

		// A real page at /it/ or /my/ wins over the language code, so
		// enabling a language can never orphan existing content.
		if ( LEADST_Conflicts::is_claimed_by_content( $first ) ) {
			$this->stripped_path = $path;
			return;
		}

		$source = leadst()->get( 'source_language', 'en' );

		// A request for the source language under a prefix is a duplicate URL.
		// Send it to the canonical unprefixed path once, permanently.
		if ( $first === $source ) {
			array_shift( $segments );
			$target = '/' . ltrim( $base . implode( '/', $segments ), '/' );
			$target = preg_replace( '#/+#', '/', $target );

			if ( $query ) {
				$target .= '?' . $query;
			}

			$this->send_redirect( $target );
			return;
		}

		$active = LEADST_Languages::active();
		if ( ! isset( $active[ $first ] ) ) {
			// A known language that is not switched on: let WordPress 404 it.
			$this->stripped_path = $path;
			return;
		}

		$this->current = $first;
		array_shift( $segments );

		$remainder = implode( '/', $segments );
		$incoming  = $remainder;

		// The path still holds translated slugs. Map them back to the originals
		// before WordPress parses the request, so core routing never sees them.
		if ( '' !== $remainder && leadst()->get( 'translate_slugs' ) ) {
			$remainder = LEADST_Slugs::instance()->restore_path( $remainder, $this->current );

			// Arrived on the untranslated slug: finish on the translated one.
			$this->maybe_redirect_to_canonical_slug( $incoming, $remainder, $base, $query, $path );
		}

		$new_path = $base . $remainder;
		$new_path = '/' . ltrim( $new_path, '/' );

		// Keep the trailing slash convention of the original request.
		if ( '/' !== $new_path && '/' === substr( $path, -1 ) && '/' !== substr( $new_path, -1 ) ) {
			$new_path .= '/';
		}

		$this->stripped_path = $new_path;

		$_SERVER['REQUEST_URI'] = $new_path . ( $query ? '?' . $query : '' );

		if ( isset( $_SERVER['PATH_INFO'] ) ) {
			$_SERVER['PATH_INFO'] = $new_path;
		}

		if ( ! defined( 'LEADST_CURRENT_LANG' ) ) {
			define( 'LEADST_CURRENT_LANG', $this->current );
		}

		add_filter( 'locale', array( $this, 'filter_locale' ), 99 );
	}

	/**
	 * Redirect an untranslated slug to its translated form.
	 *
	 * Only fires when the round trip is stable — the canonical form must map
	 * back to exactly the path we already resolved. That check is what makes a
	 * redirect loop impossible: if the two slug maps ever disagree, no redirect
	 * is issued and the page simply serves at the URL that was asked for.
	 *
	 * @param string $incoming  Path as requested, after the language segment.
	 * @param string $restored  Same path with original slugs.
	 * @param string $base      Site base path.
	 * @param string $query     Query string without the leading question mark.
	 * @param string $full_path The complete request path, for slash convention.
	 * @return void
	 */
	private function maybe_redirect_to_canonical_slug( $incoming, $restored, $base, $query, $full_path ) {
		if ( ! leadst()->get( 'canonical_slug_redirect' ) ) {
			return;
		}

		if ( '' === trim( (string) $restored, '/' ) ) {
			return;
		}

		$slugs     = LEADST_Slugs::instance();
		$canonical = $slugs->translate_path( $restored, $this->current );

		// Already on the canonical form, or nothing translated for this path.
		if ( $canonical === $incoming ) {
			return;
		}

		// Loop guard: the canonical form must restore back to the same path.
		if ( $slugs->restore_path( $canonical, $this->current ) !== $restored ) {
			return;
		}

		$target = '/' . ltrim( $base . $this->current . '/' . ltrim( $canonical, '/' ), '/' );

		if ( '/' === substr( $full_path, -1 ) && '/' !== substr( $target, -1 ) ) {
			$target .= '/';
		}

		$target = preg_replace( '#/+#', '/', $target );

		if ( $query ) {
			$target .= '?' . $query;
		}

		$this->send_redirect( $target );
	}

	/**
	 * Queue a redirect from the router.
	 *
	 * The router runs before pluggable.php is loaded, so wp_safe_redirect() does
	 * not exist yet at this point. Rather than reaching for a raw header() call,
	 * the target is held and sent on init, where the safe wrapper is available
	 * and the host allowlist applies.
	 *
	 * @param string $target Relative target path.
	 * @param int    $status HTTP status code.
	 * @return void
	 */
	private function send_redirect( $target, $status = 301 ) {
		$target = str_replace( array( "\r", "\n", "\0" ), '', (string) $target );

		// Refuse anything that is not a plain relative path.
		if ( '' === $target || 0 !== strpos( $target, '/' ) || 0 === strpos( $target, '//' ) ) {
			return;
		}

		/**
		 * Filter the router's redirect target. Return an empty string to cancel.
		 *
		 * @param string $target Relative target path.
		 * @param int    $status HTTP status code.
		 */
		$target = (string) apply_filters( 'leadst_redirect_target', $target, $status );

		if ( '' === $target ) {
			return;
		}

		$this->pending_redirect = array(
			'target' => $target,
			'status' => (int) $status,
		);

		add_action( 'init', array( $this, 'fire_pending_redirect' ), 1 );
	}

	/**
	 * Send a queued redirect.
	 *
	 * @return void
	 */
	public function fire_pending_redirect() {
		if ( ! $this->pending_redirect ) {
			return;
		}

		$redirect               = $this->pending_redirect;
		$this->pending_redirect = null;

		wp_safe_redirect( $redirect['target'], $redirect['status'] );
		exit;
	}

	/**
	 * Language-in-the-hostname routing: es.example.org/about-us/.
	 *
	 * This needs things a plugin cannot provide on its own — a DNS record and a
	 * TLS certificate covering every language hostname, and a server that routes
	 * all of them to this one install. It is off by default for that reason.
	 *
	 * @return void
	 */
	private function boot_subdomain() {
		// Behind a proxy the language arrives in a signed header, not the
		// hostname, so check that first.
		$lang = $this->edge_language();

		if ( $lang ) {
			// Translated HTML is about to be rendered at the bare path. Any
			// page cache would store it there and serve it to the next
			// visitor in the wrong language. Opt out before anything renders.
			$this->protect_edge_response();
		}

		if ( ! $lang ) {
			$lang = $this->host_language();
		}

		if ( ! $lang ) {
			$uri                 = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$parts               = wp_parse_url( $uri );
			$this->stripped_path = isset( $parts['path'] ) ? $parts['path'] : '/';
			return;
		}

		$active = LEADST_Languages::active();
		if ( ! isset( $active[ $lang ] ) ) {
			return;
		}

		$this->current = $lang;

		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$parts = wp_parse_url( $uri );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? $parts['query'] : '';

		$base     = $this->base_path();
		$relative = ( '/' !== $base && 0 === strpos( $path, $base ) )
			? substr( $path, strlen( $base ) )
			: ltrim( $path, '/' );

		// The hostname carries the language; the path still carries translated
		// slugs, so those are mapped back exactly as in directory mode.
		if ( '' !== trim( (string) $relative, '/' ) && leadst()->get( 'translate_slugs' ) ) {
			$relative = LEADST_Slugs::instance()->restore_path( $relative, $lang );
		}

		$new_path = '/' . ltrim( $base . $relative, '/' );

		if ( '/' !== $new_path && '/' === substr( $path, -1 ) && '/' !== substr( $new_path, -1 ) ) {
			$new_path .= '/';
		}

		$this->stripped_path    = $new_path;
		$_SERVER['REQUEST_URI'] = $new_path . ( $query ? '?' . $query : '' );

		if ( isset( $_SERVER['PATH_INFO'] ) ) {
			$_SERVER['PATH_INFO'] = $new_path;
		}

		if ( ! defined( 'LEADST_CURRENT_LANG' ) ) {
			define( 'LEADST_CURRENT_LANG', $this->current );
		}

		add_filter( 'locale', array( $this, 'filter_locale' ), 99 );

		// Core's canonical redirect would bounce every language host back
		// to the main domain.
		add_action(
			'template_redirect',
			function () {
				remove_action( 'template_redirect', 'redirect_canonical' );
			},
			0
		);
		add_filter( 'redirect_canonical', '__return_false', 99 );
	}

	/**
	 * The shared secret between this site and the edge proxy.
	 *
	 * @return string
	 */
	public static function edge_token() {
		$token = get_option( 'leadst_edge_token' );

		if ( ! $token ) {
			$token = wp_generate_password( 40, false, false );
			update_option( 'leadst_edge_token', $token, false );
		}

		return $token;
	}

	/**
	 * The language named by a signed header from the edge proxy.
	 *
	 * The signature matters. Without it any visitor could send
	 * X-WPTR-Lang and be served a language they did not ask for at a URL that
	 * says otherwise, which would confuse both people and search engines.
	 *
	 * @return string Empty when no valid header is present.
	 */
	public function edge_language() {
		if ( empty( $_SERVER['HTTP_X_LST_LANG'] ) || empty( $_SERVER['HTTP_X_LST_EDGE_TOKEN'] ) ) {
			return '';
		}

		$sent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LST_EDGE_TOKEN'] ) );

		// wp_generate_password() lives in pluggable.php, which has not loaded
		// yet — read the stored token directly and treat "not set up" as "no".
		$token = (string) get_option( 'leadst_edge_token', '' );

		if ( '' === $token || ! hash_equals( $token, $sent ) ) {
			return '';
		}

		$lang = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LST_LANG'] ) ) );

		return LEADST_Languages::exists( $lang ) ? $lang : '';
	}

	/**
	 * Check that each language hostname is actually being served.
	 *
	 * With a CNAME pointing at the origin, a broken or removed edge proxy does
	 * not fail loudly — the request reaches the origin with a hostname it does
	 * not recognize and is usually redirected to the primary domain. A visitor
	 * shrugs; a search engine records every language URL redirecting to the
	 * source language. This turns that silent state into a visible one.
	 *
	 * @return array Language slug => array( ok, detail ).
	 */
	public function health_check() {
		$results = array();
		$source  = leadst()->get( 'source_language', 'en' );

		if ( 'subdomain' !== $this->url_mode() ) {
			return $results;
		}

		foreach ( LEADST_Languages::active() as $slug => $language ) {
			$url = $this->translate_url( $slug, home_url( '/' ) );

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'sslverify'   => true,
					'user-agent'  => 'LeadstartTranslate/' . LEADST_VERSION . ' (health check)',
				)
			);

			if ( is_wp_error( $response ) ) {
				$results[ $slug ] = array(
					'ok'     => false,
					'detail' => $response->get_error_message(),
				);
				continue;
			}

			$code   = (int) wp_remote_retrieve_response_code( $response );
			$served = wp_remote_retrieve_header( $response, 'x-lst-language' );

			if ( $served === $slug ) {
				$results[ $slug ] = array(
					'ok'     => true,
					/* translators: %d: HTTP status code. */
					'detail' => sprintf( __( 'Served by the proxy (HTTP %d).', 'leadstart-translate' ), $code ),
				);
				continue;
			}

			if ( $code >= 300 && $code < 400 ) {
				$results[ $slug ] = array(
					'ok'     => false,
					'detail' => __( 'Redirected away — the proxy is not answering for this hostname. Search engines seeing this would drop the language.', 'leadstart-translate' ),
				);
				continue;
			}

			$results[ $slug ] = array(
				'ok'     => false,
				/* translators: %d: HTTP status code. */
				'detail' => sprintf( __( 'Answered with HTTP %d but not by the proxy. Check the Worker route and that the DNS record is proxied.', 'leadstart-translate' ), $code ),
			);
		}

		unset( $source );

		return $results;
	}

	/**
	 * Keep a language-varied response out of every cache that might store it
	 * under the bare path.
	 *
	 * Three mechanisms, because caches honor different things:
	 *
	 *  - DONOTCACHEPAGE is the de facto constant respected by WP Rocket, W3
	 *    Total Cache, WP Super Cache, LiteSpeed Cache and Batcache. Most
	 *    WordPress page caches ignore Vary entirely, so this is the one that
	 *    actually does the work on a typical site.
	 *  - Vary is correct HTTP and is honored by well-behaved reverse proxies
	 *    and CDNs.
	 *  - Cache-Control is the blunt instrument for anything that respects
	 *    neither.
	 *
	 * The edge in front of this is unaffected: each language hostname is its
	 * own cache key there, so the translated page is still cached where it
	 * should be — at the proxy, per hostname.
	 *
	 * @return void
	 */
	private function protect_edge_response() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the name every page cache looks for; prefixing it would disable the protection.
			define( 'DONOTCACHEPAGE', true );
		}

		add_action(
			'send_headers',
			static function () {
				if ( headers_sent() ) {
					return;
				}

				header( 'Vary: X-WPTR-Lang', false );
				header( 'Cache-Control: private, no-store, max-age=0' );
			},
			1
		);
	}

	/**
	 * The site's own hostname, with any language label removed.
	 *
	 * @return string
	 */
	public function base_host() {
		static $host = null;

		if ( null !== $host ) {
			return $host;
		}

		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$bits = explode( '.', $home );

		// If WordPress itself is configured on a language host, strip the label
		// so every other language is built from the same root.
		if ( count( $bits ) > 2 && LEADST_Languages::exists( $bits[0] ) ) {
			array_shift( $bits );
		}

		$host = implode( '.', $bits );
		return $host;
	}

	/**
	 * The language named by the requested hostname, if any.
	 *
	 * @return string Empty when the host carries no language label.
	 */
	public function host_language() {
		if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}

		$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
		$host = preg_replace( '/:\d+$/', '', $host );

		return $this->language_from_host( $host );
	}

	/**
	 * Extract a language label from a hostname belonging to this site.
	 *
	 * @param string $host Hostname without port.
	 * @return string Empty when it is not a language host for this site.
	 */
	private function language_from_host( $host ) {
		$base = $this->base_host();

		if ( '' === $host || $host === $base ) {
			return '';
		}

		$suffix = '.' . $base;
		if ( substr( $host, -strlen( $suffix ) ) !== $suffix ) {
			return '';
		}

		$label = substr( $host, 0, -strlen( $suffix ) );

		// Only a single label directly in front of the base domain counts.
		if ( '' === $label || false !== strpos( $label, '.' ) ) {
			return '';
		}

		return LEADST_Languages::exists( $label ) ? strtolower( $label ) : '';
	}

	/**
	 * Requests that must never be rewritten.
	 *
	 * @return bool
	 */
	private function should_skip() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return true;
		}

		$uri = strtolower( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );

		$skip = array(
			'/wp-admin',
			'/wp-login.php',
			'/wp-cron.php',
			'/wp-json',
			'/xmlrpc.php',
			'/wp-content/',
			'/wp-includes/',
			'/.well-known/',
		);

		foreach ( $skip as $needle ) {
			if ( false !== strpos( $uri, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Path portion of the site's home URL, always with a trailing slash.
	 *
	 * @return string
	 */
	public function base_path() {
		static $base = null;

		if ( null === $base ) {
			$parts = wp_parse_url( home_url( '/' ) );
			$base  = isset( $parts['path'] ) ? $parts['path'] : '/';
			$base  = '/' . trim( $base, '/' );
			$base  = rtrim( $base, '/' ) . '/';
		}

		return $base;
	}

	/**
	 * The language being served.
	 *
	 * @return string
	 */
	public function current() {
		return $this->current ? $this->current : leadst()->get( 'source_language', 'en' );
	}

	/**
	 * Whether the current request is a translated view.
	 *
	 * @return bool
	 */
	public function is_translated() {
		return $this->current() !== leadst()->get( 'source_language', 'en' );
	}

	/**
	 * Path with the language prefix removed.
	 *
	 * @return string
	 */
	public function stripped_path() {
		return $this->stripped_path;
	}

	/**
	 * Map the language onto a WordPress locale so core strings, date formats
	 * and text direction follow the translated page.
	 *
	 * @param string $locale Current locale.
	 * @return string
	 */
	public function filter_locale( $locale ) {
		$map = array(
			'zh-cn' => 'zh_CN',
			'zh-tw' => 'zh_TW',
			'pt'    => 'pt_PT',
			'he'    => 'he_IL',
			'ja'    => 'ja',
			'ko'    => 'ko_KR',
			'en'    => 'en_US',
			'es'    => 'es_ES',
			'fr'    => 'fr_FR',
			'de'    => 'de_DE',
			'ar'    => 'ar',
			'vi'    => 'vi',
			'ru'    => 'ru_RU',
		);

		$slug = $this->current();

		/**
		 * Filter the WordPress locale chosen for a language slug.
		 *
		 * @param string $locale Locale.
		 * @param string $slug   Language slug.
		 */
		return apply_filters(
			'leadst_locale_for_language',
			isset( $map[ $slug ] ) ? $map[ $slug ] : $locale,
			$slug
		);
	}

	/**
	 * Build the URL of a given page in a given language.
	 *
	 * @param string      $lang Language slug.
	 * @param string|null $url  Absolute URL to convert. Defaults to the current request.
	 * @return string
	 */
	public function translate_url( $lang, $url = null ) {
		$source = leadst()->get( 'source_language', 'en' );

		if ( null === $url ) {
			$url = $this->current_url();
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : ( is_ssl() ? 'https' : 'http' );
		$host   = isset( $parts['host'] ) ? $parts['host'] : wp_parse_url( home_url(), PHP_URL_HOST );
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$frag   = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		$base       = $this->base_path();
		$subdomains = ( 'subdomain' === $this->url_mode() );
		$relative   = ( '/' !== $base && 0 === strpos( $path, $base ) )
			? substr( $path, strlen( $base ) )
			: ltrim( $path, '/' );

		$segments = array_values( array_filter( explode( '/', trim( (string) $relative, '/' ) ), 'strlen' ) );

		// Strip the language already on the URL so this is idempotent:
		// the hostname in subdomain mode, the first segment otherwise.
		$from = $source;

		if ( $subdomains ) {
			$found = $this->language_from_host( strtolower( (string) $host ) );
			if ( $found ) {
				$from = $found;
			}
		} elseif ( isset( $segments[0] ) && LEADST_Languages::exists( strtolower( $segments[0] ) ) ) {
			$from = strtolower( array_shift( $segments ) );
		}

		$path_no_lang = implode( '/', $segments );

		if ( leadst()->get( 'translate_slugs' ) ) {
			// Restore to source slugs first, or /es/sobre-nosotros/ switched
			// to English yields /sobre-nosotros/, which does not resolve.
			if ( $from !== $source ) {
				$path_no_lang = LEADST_Slugs::instance()->restore_path( $path_no_lang, $from );
			}

			$path_no_lang = LEADST_Slugs::instance()->translate_path( $path_no_lang, $lang );
		}

		if ( $subdomains ) {
			$host  = ( $lang === $source ) ? $this->base_host() : $lang . '.' . $this->base_host();
			$final = $base . $path_no_lang;
		} else {
			$prefix = ( $lang === $source ) ? '' : $lang . '/';
			$final  = $base . $prefix . $path_no_lang;
		}

		if ( '' !== $path_no_lang && '/' !== substr( $final, -1 ) && ! preg_match( '/\.[a-z0-9]{2,5}$/i', $final ) ) {
			$final .= '/';
		}

		return $scheme . '://' . $host . $final . $query . $frag;
	}

	/**
	 * Absolute URL of the current request, language prefix intact.
	 *
	 * @return string
	 */
	public function current_url() {
		$path   = $this->stripped_path;
		$lang   = $this->current();
		$base   = $this->base_path();
		$source = leadst()->get( 'source_language', 'en' );

		$relative = ( '/' !== $base && 0 === strpos( $path, $base ) )
			? substr( $path, strlen( $base ) )
			: ltrim( $path, '/' );

		if ( 'subdomain' === $this->url_mode() ) {
			$scheme = is_ssl() ? 'https' : 'http';
			$host   = ( $lang === $source ) ? $this->base_host() : $lang . '.' . $this->base_host();

			return $scheme . '://' . $host . $base . $relative;
		}

		$prefix = ( $lang === $source ) ? '' : $lang . '/';

		return home_url( '/' . ltrim( $prefix . $relative, '/' ) );
	}

	/**
	 * Whether a URL belongs to this site and is safe to prefix.
	 *
	 * @param string $url Any href value.
	 * @return bool
	 */
	public function is_internal( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return false;
		}

		if ( preg_match( '#^(mailto:|tel:|javascript:|data:|sms:|\#)#i', $url ) ) {
			return false;
		}

		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$parts     = wp_parse_url( $url );

		if ( isset( $parts['host'] ) ) {
			$host = strtolower( $parts['host'] );

			// In subdomain mode a language host is still this site.
			$known = ( $host === $home_host )
				|| ( $host === $this->base_host() )
				|| ( 'subdomain' === $this->url_mode() && '' !== $this->language_from_host( $host ) );

			if ( ! $known ) {
				return false;
			}
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';

		$blocked = array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-content/', '/wp-includes/', '/xmlrpc.php', '/feed' );
		foreach ( $blocked as $needle ) {
			if ( false !== stripos( $path, $needle ) ) {
				return false;
			}
		}

		// Direct file links keep their canonical path.
		if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|pdf|zip|docx?|xlsx?|pptx?|mp[34]|css|js|ico|woff2?|ttf)$/i', $path ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Explain, step by step, what this router does with a given path.
	 *
	 * Mirrors boot_directory() but changes nothing: every decision is made by
	 * calling the same function the live request calls, so the report cannot
	 * drift away from the behavior it describes.
	 *
	 * @param string $path Path as typed, with or without the site base.
	 * @return array List of array( step, result, ok ) rows.
	 */
	public function diagnose( $path ) {
		$rows = array();

		$add = function ( $step, $result, $ok = null ) use ( &$rows ) {
			$rows[] = array(
				'step'   => $step,
				'result' => $result,
				'ok'     => $ok,
			);
		};

		$parts = wp_parse_url( $path );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? $parts['query'] : '';

		$add( __( 'URL mode', 'leadstart-translate' ), $this->url_mode() );

		if ( 'subdomain' === $this->url_mode() ) {
			$add(
				__( 'Path routing', 'leadstart-translate' ),
				__( 'Not used — in subdomain mode the language comes from the hostname. Use "Check language hostnames" instead.', 'leadstart-translate' ),
				null
			);
			return $rows;
		}

		$base = $this->base_path();
		$add( __( 'Site base path', 'leadstart-translate' ), $base );

		if ( '/' !== $base && 0 === strpos( $path, $base ) ) {
			$relative = substr( $path, strlen( $base ) );
		} else {
			$relative = ltrim( $path, '/' );
		}

		$segments = explode( '/', trim( (string) $relative, '/' ) );
		$first    = isset( $segments[0] ) ? strtolower( $segments[0] ) : '';

		$add( __( 'First path segment', 'leadstart-translate' ), '' === $first ? '(none)' : $first );

		if ( '' === $first || ! LEADST_Languages::exists( $first ) ) {
			$add(
				__( 'Is it a language code?', 'leadstart-translate' ),
				__( 'No. WordPress handles this URL untouched.', 'leadstart-translate' ),
				false
			);
			return $rows;
		}

		$add( __( 'Is it a language code?', 'leadstart-translate' ), __( 'Yes', 'leadstart-translate' ), true );

		if ( LEADST_Conflicts::is_claimed_by_content( $first ) ) {
			$add(
				__( 'Clash with real content', 'leadstart-translate' ),
				/* translators: %s: path segment. */
				sprintf( __( 'A published page is named "%s", so it wins. The language prefix is ignored.', 'leadstart-translate' ), $first ),
				false
			);
			return $rows;
		}

		$source = leadst()->get( 'source_language', 'en' );

		if ( $first === $source ) {
			$add(
				__( 'Source language prefix', 'leadstart-translate' ),
				__( 'This is the site language, so the request is redirected to the unprefixed path.', 'leadstart-translate' ),
				false
			);
			return $rows;
		}

		$active = LEADST_Languages::active();

		if ( ! isset( $active[ $first ] ) ) {
			$add(
				__( 'Is the language switched on?', 'leadstart-translate' ),
				__( 'No. Tick it in Settings, or WordPress will keep returning 404.', 'leadstart-translate' ),
				false
			);
			return $rows;
		}

		$add( __( 'Is the language switched on?', 'leadstart-translate' ), __( 'Yes', 'leadstart-translate' ), true );

		array_shift( $segments );
		$remainder = implode( '/', $segments );

		$add( __( 'Path after the language', 'leadstart-translate' ), '' === $remainder ? '(front page)' : $remainder );

		if ( ! leadst()->get( 'translate_slugs' ) ) {
			$add(
				__( 'Translate URL slugs', 'leadstart-translate' ),
				__( 'Off. The path is passed through as typed.', 'leadstart-translate' ),
				null
			);
		} elseif ( '' !== $remainder ) {
			$stored = LEADST_Slugs::instance()->count_for( $first );

			$add(
				__( 'Slugs stored for this language', 'leadstart-translate' ),
				number_format_i18n( $stored ),
				$stored > 0
			);

			if ( ! $stored ) {
				$add(
					__( 'Why it 404s', 'leadstart-translate' ),
					__( 'No slug has been translated for this language yet, so the translated URL maps to nothing. Run "Translate all URL slugs", then the worker.', 'leadstart-translate' ),
					false
				);
			}

			$restored = LEADST_Slugs::instance()->restore_path( $remainder, $first );

			$add(
				__( 'Mapped back to source slugs', 'leadstart-translate' ),
				$restored,
				$restored !== $remainder
			);

			if ( $stored && $restored === $remainder ) {
				$missing = array();
				foreach ( array_filter( explode( '/', trim( $remainder, '/' ) ), 'strlen' ) as $segment ) {
					$missing[] = rawurldecode( $segment );
				}

				$add(
					__( 'Why it 404s', 'leadstart-translate' ),
					/* translators: %s: comma separated list of URL segments. */
					sprintf( __( 'No stored slug matches: %s. That slug was never translated, or the map was built before the page existed. Re-run "Translate all URL slugs".', 'leadstart-translate' ), implode( ', ', $missing ) ),
					false
				);
			}

			$remainder = $restored;
		}

		$new_path = '/' . ltrim( $base . $remainder, '/' );

		if ( '/' !== $new_path && '/' === substr( $path, -1 ) && '/' !== substr( $new_path, -1 ) ) {
			$new_path .= '/';
		}

		$add( __( 'WordPress is asked for', 'leadstart-translate' ), $new_path . ( $query ? '?' . $query : '' ) );

		$post_id = url_to_postid( home_url( $new_path ) );

		if ( $post_id ) {
			$add(
				__( 'Content found there', 'leadstart-translate' ),
				/* translators: 1: post title, 2: post ID. */
				sprintf( __( '%1$s (#%2$d)', 'leadstart-translate' ), get_the_title( $post_id ), $post_id ),
				true
			);
		} else {
			$home = untrailingslashit( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

			if ( untrailingslashit( $new_path ) === $home ) {
				$add( __( 'Content found there', 'leadstart-translate' ), __( 'The front page', 'leadstart-translate' ), true );
			} else {
				$add(
					__( 'Content found there', 'leadstart-translate' ),
					__( 'Nothing. WordPress will return 404 for this path even without the plugin — check the page exists and is published.', 'leadstart-translate' ),
					false
				);
			}
		}

		return $rows;
	}
}
