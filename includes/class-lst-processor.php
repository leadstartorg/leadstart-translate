<?php
/**
 * Page processor.
 *
 * Buffers the rendered page, walks the DOM, swaps every translatable text node
 * and attribute for its stored translation, and rewrites internal links to stay
 * inside the current language.
 *
 * Anything that goes wrong here returns the original HTML untouched. A
 * translation plugin must never be the reason a page fails to render.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Output buffering and DOM translation.
 */
class LEADST_Processor {

	/**
	 * Maximum strings translated synchronously during one pageview.
	 *
	 * Everything above this is queued for the background worker so a first
	 * visit to a cold page does not hang.
	 */
	const LIVE_LIMIT = 60;

	/**
	 * Tags whose text is never translated.
	 *
	 * @var string[]
	 */
	private $skip_tags = array( 'script', 'style', 'code', 'pre', 'svg', 'noscript', 'canvas', 'video', 'audio', 'iframe', 'math' );

	/**
	 * Attributes translated when present.
	 *
	 * @var string[]
	 */
	private $attributes = array( 'alt', 'title', 'placeholder', 'aria-label', 'aria-placeholder', 'data-tooltip' );

	/**
	 * Meta names and properties whose content is translated.
	 *
	 * @var string[]
	 */
	private $meta_keys = array(
		'description',
		'og:title',
		'og:description',
		'og:site_name',
		'og:image:alt',
		'twitter:title',
		'twitter:description',
		'twitter:image:alt',
	);

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Processor|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Processor
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Open the output buffer if this request should be translated.
	 *
	 * @return void
	 */
	public function start_buffer() {
		if ( ! $this->should_process() ) {
			return;
		}

		// The crawler's requests look like pageviews to PHP, so the slow
		// tiers would be refused. Uses the validated token check, not a raw
		// header read, which any visitor could forge to tie up a worker.
		if ( $this->is_warm_request() ) {
			add_filter( 'leadst_slow_tiers_allowed', '__return_true' );
		}

		ob_start( array( $this, 'process' ) );
	}

	/**
	 * Whether the current request is a candidate for translation.
	 *
	 * @return bool
	 */
	private function should_process() {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
			return false;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( ! LEADST_Router::instance()->is_translated() ) {
			return false;
		}

		if ( ! extension_loaded( 'dom' ) || ! class_exists( 'DOMDocument' ) ) {
			return false;
		}

		/**
		 * Filter whether a request gets translated at all.
		 *
		 * @param bool $process Whether to translate.
		 */
		return (bool) apply_filters( 'leadst_should_process_request', true );
	}

	/**
	 * Whether this request's text may be sent for translation.
	 *
	 * Separate from should_process() on purpose. An excluded path still has
	 * its links rewritten and its lang attribute set, because a visitor who
	 * reaches the cart in Balinese and finds every link pointing back at the
	 * bare English path has been dropped out of the language mid-session.
	 * What exclusion means is that the page's own text -- order totals,
	 * addresses, whatever the customer typed -- is never sent to an API.
	 *
	 * @return bool
	 */
	private function text_translation_allowed() {
		$path = LEADST_Router::instance()->stripped_path();

		foreach ( $this->excluded_paths() as $needle ) {
			if ( '' !== $needle && false !== stripos( $path, $needle ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Paths that are never translated.
	 *
	 * @return string[]
	 */
	private function excluded_paths() {
		$raw = (string) leadst()->get( 'exclude_paths', '' );
		return array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
	}

	/**
	 * CSS-ish selectors marking subtrees to leave alone.
	 *
	 * Only element names and single class names are supported, which covers the
	 * .notranslate convention without needing a selector engine.
	 *
	 * @return array Array with 'tags' and 'classes'.
	 */
	private function exclusions() {
		static $parsed = null;
		static $for    = null;

		$raw = (string) leadst()->get( 'exclude_selectors', '' );

		if ( null !== $parsed && $for === $raw ) {
			return $parsed;
		}

		$for     = $raw;
		$lines   = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|,/', $raw ) ) );
		$tags    = array();
		$classes = array( 'notranslate', 'skiptranslate' );

		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, '.' ) ) {
				$classes[] = substr( $line, 1 );
			} elseif ( preg_match( '/^[a-z0-9-]+$/i', $line ) ) {
				$tags[] = strtolower( $line );
			}
		}

		$parsed = array(
			'tags'    => array_unique( array_merge( $this->skip_tags, $tags ) ),
			'classes' => array_unique( $classes ),
		);

		return $parsed;
	}

	/**
	 * Buffer callback.
	 *
	 * @param string $html Rendered page.
	 * @return string
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 200 ) {
			return $html;
		}

		// Only translate full HTML documents.
		if ( false === stripos( $html, '<html' ) ) {
			return $html;
		}

		// Memory exhaustion mid-parse is fatal and uncatchable, so check
		// headroom first and serve untranslated rather than blank.
		if ( ! $this->has_memory_headroom( $html ) ) {
			return $html;
		}

		try {
			$cached = $this->cache_get( $html );
			if ( null !== $cached ) {
				return $cached;
			}

			$result = $this->translate_document( $html );

			// Sanity check: a mangled parse is worse than an untranslated page.
			if ( ! is_string( $result ) || strlen( $result ) < ( strlen( $html ) * 0.5 ) ) {
				return $html;
			}

			$this->cache_set( $html, $result );

			return $result;
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Leadstart Translate: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return $html;
		}
	}

	/**
	 * Whether there is enough memory left to build a DOM tree for this page.
	 *
	 * libxml holds a parsed document in memory at several times the size of the
	 * source string, and the re-serialized copy exists alongside it for a
	 * moment. The multiplier below is deliberately pessimistic: being wrong in
	 * the cautious direction costs one untranslated pageview, while being wrong
	 * in the other direction costs a white screen.
	 *
	 * @param string $html Rendered page.
	 * @return bool
	 */
	private function has_memory_headroom( $html ) {
		$limit = $this->memory_limit_bytes();

		// No limit configured, or an unreadable one — nothing to check against.
		if ( $limit <= 0 ) {
			return true;
		}

		$needed = strlen( $html ) * (int) apply_filters( 'leadst_dom_memory_multiplier', 12 );
		$free   = $limit - memory_get_usage( true );

		if ( $free > $needed ) {
			return true;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Leadstart Translate: skipped translating a %d byte page, %d bytes of memory free.',
					strlen( $html ),
					max( 0, $free )
				)
			);
		}

		return false;
	}

	/**
	 * The PHP memory limit in bytes.
	 *
	 * @return int Zero when unlimited or unreadable.
	 */
	private function memory_limit_bytes() {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}

		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (int) $raw;

		switch ( $unit ) {
			case 'g':
				return $value * 1024 * 1024 * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}

	/**
	 * Parse, translate and re-serialize the document.
	 *
	 * @param string $html Rendered page.
	 * @return string
	 */
	private function translate_document( $html ) {
		$lang   = LEADST_Router::instance()->current();
		$source = leadst()->get( 'source_language', 'en' );

		$previous = libxml_use_internal_errors( true );

		$dom = new DOMDocument( '1.0', 'UTF-8' );
		$dom->preserveWhiteSpace = true;
		$dom->formatOutput       = false;

		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $html;
		}

		// Drop the encoding hint we injected.
		foreach ( iterator_to_array( $dom->childNodes ) as $node ) {
			if ( XML_PI_NODE === $node->nodeType ) {
				$dom->removeChild( $node );
			}
		}

		$xpath = new DOMXPath( $dom );

		$text_nodes = $this->collect_text_nodes( $xpath );
		$attr_nodes = $this->collect_attribute_nodes( $xpath );

		if ( $this->text_translation_allowed() ) {
			$sources = array();
			foreach ( $text_nodes as $node ) {
				$sources[] = $node->nodeValue;
			}
			foreach ( $attr_nodes as $attr ) {
				$sources[] = $attr->value;
			}

			$sources = array_values( array_unique( array_filter( array_map( array( 'LEADST_DB', 'normalize' ), $sources ), array( $this, 'is_translatable' ) ) ) );

			$translations = $this->resolve( $sources, $lang, $source );

			foreach ( $text_nodes as $node ) {
				$key = LEADST_DB::normalize( $node->nodeValue );
				if ( isset( $translations[ $key ] ) && '' !== $translations[ $key ] ) {
					$node->nodeValue = $this->preserve_spacing( $node->nodeValue, $translations[ $key ] );
				}
			}

			foreach ( $attr_nodes as $attr ) {
				$key = LEADST_DB::normalize( $attr->value );
				if ( isset( $translations[ $key ] ) && '' !== $translations[ $key ] ) {
					$attr->value = htmlspecialchars( $translations[ $key ], ENT_QUOTES, 'UTF-8' );
				}
			}
		}

		$this->rewrite_links( $xpath, $lang );
		$this->set_document_language( $dom, $xpath, $lang );

		$out = $dom->saveHTML();

		return is_string( $out ) && '' !== $out ? $out : $html;
	}

	/**
	 * The translatable strings in a page, as translation itself would see them.
	 *
	 * Deliberately runs the same collectors, the same exclusion rules and the
	 * same is_translatable() test the live path uses. A comparison built on a
	 * different idea of what counts as a string would be comparing engines on
	 * text the plugin never actually sends them.
	 *
	 * @param string $html  Rendered page.
	 * @param int    $limit Most strings to return; 0 for all of them.
	 * @return string[] Unique, normalized source strings in document order.
	 */
	public function extract_strings( $html, $limit = 0 ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return array();
		}

		$previous = libxml_use_internal_errors( true );

		$dom = new DOMDocument( '1.0', 'UTF-8' );
		$dom->preserveWhiteSpace = true;
		$dom->formatOutput       = false;

		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array();
		}

		$xpath   = new DOMXPath( $dom );
		$sources = array();

		foreach ( $this->collect_text_nodes( $xpath ) as $node ) {
			$sources[] = $node->nodeValue;
		}

		foreach ( $this->collect_attribute_nodes( $xpath ) as $attr ) {
			$sources[] = $attr->value;
		}

		$sources = array_values(
			array_unique(
				array_filter(
					array_map( array( 'LEADST_DB', 'normalize' ), $sources ),
					array( $this, 'is_translatable' )
				)
			)
		);

		$limit = (int) $limit;

		return $limit > 0 ? array_slice( $sources, 0, $limit ) : $sources;
	}

	/**
	 * Every text node that should be translated.
	 *
	 * @param DOMXPath $xpath Document xpath.
	 * @return DOMNode[]
	 */
	private function collect_text_nodes( DOMXPath $xpath ) {
		$exclusions = $this->exclusions();
		$nodes      = array();

		$found = $xpath->query( '//text()[normalize-space(.) != ""]' );
		if ( ! $found ) {
			return $nodes;
		}

		foreach ( $found as $node ) {
			if ( $this->is_excluded( $node, $exclusions ) ) {
				continue;
			}

			if ( ! $this->is_translatable( $node->nodeValue ) ) {
				continue;
			}

			$nodes[] = $node;
		}

		return $nodes;
	}

	/**
	 * Every attribute value that should be translated.
	 *
	 * @param DOMXPath $xpath Document xpath.
	 * @return DOMAttr[]
	 */
	private function collect_attribute_nodes( DOMXPath $xpath ) {
		$exclusions = $this->exclusions();
		$nodes      = array();

		$queries = array();
		foreach ( $this->attributes as $attribute ) {
			$queries[] = '//@' . $attribute;
		}

		// Submit buttons carry their label in value.
		$queries[] = '//input[@type="submit" or @type="button" or @type="reset"]/@value';

		foreach ( $queries as $query ) {
			$found = $xpath->query( $query );
			if ( ! $found ) {
				continue;
			}

			foreach ( $found as $attr ) {
				if ( $this->is_excluded( $attr->ownerElement, $exclusions ) ) {
					continue;
				}
				if ( ! $this->is_translatable( $attr->value ) ) {
					continue;
				}
				$nodes[] = $attr;
			}
		}

		if ( leadst()->get( 'translate_meta' ) ) {
			foreach ( $this->meta_keys as $key ) {
				$found = $xpath->query( sprintf( '//meta[@name="%1$s" or @property="%1$s"]/@content', $key ) );
				if ( ! $found ) {
					continue;
				}
				foreach ( $found as $attr ) {
					if ( $this->is_translatable( $attr->value ) ) {
						$nodes[] = $attr;
					}
				}
			}
		}

		return $nodes;
	}

	/**
	 * Whether a node sits inside an excluded subtree.
	 *
	 * @param DOMNode|null $node       Node to test.
	 * @param array        $exclusions Tags and classes to skip.
	 * @return bool
	 */
	private function is_excluded( $node, array $exclusions ) {
		$current = $node;
		$depth   = 0;

		while ( $current && $depth < 60 ) {
			if ( XML_ELEMENT_NODE === $current->nodeType ) {
				$tag = strtolower( $current->nodeName );

				if ( in_array( $tag, $exclusions['tags'], true ) ) {
					return true;
				}

				if ( $current->hasAttribute( 'translate' ) && 'no' === strtolower( $current->getAttribute( 'translate' ) ) ) {
					return true;
				}

				if ( $current->hasAttribute( 'data-no-translate' ) ) {
					return true;
				}

				if ( $current->hasAttribute( 'class' ) ) {
					$classes = preg_split( '/\s+/', strtolower( $current->getAttribute( 'class' ) ) );
					foreach ( $exclusions['classes'] as $skip ) {
						if ( in_array( strtolower( $skip ), (array) $classes, true ) ) {
							return true;
						}
					}
				}
			}

			$current = $current->parentNode;
			$depth++;
		}

		return false;
	}

	/**
	 * Whether a string is worth sending to a translation API.
	 *
	 * @param string $text Candidate string.
	 * @return bool
	 */
	public function is_translatable( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return false;
		}

		// Pure numbers, punctuation, currency and dates.
		if ( ! preg_match( '/\p{L}{2,}/u', $text ) ) {
			return false;
		}

		// URLs, emails and template placeholders.
		if ( preg_match( '#^(https?://|//|mailto:|tel:|\{\{|\[\[)#i', $text ) ) {
			return false;
		}

		if ( is_email( $text ) ) {
			return false;
		}

		if ( LEADST_DB::char_count( $text ) > 5000 ) {
			return false;
		}

		/**
		 * Filter whether a specific string is translatable.
		 *
		 * @param bool   $translatable Result.
		 * @param string $text         The string.
		 */
		return (bool) apply_filters( 'leadst_is_translatable', true, $text );
	}

	/**
	 * Look translations up, translating misses when that is allowed.
	 *
	 * @param string[] $sources Normalized source strings.
	 * @param string   $lang    Target language.
	 * @param string   $source  Source language.
	 * @return array Source string => translation.
	 */
	private function resolve( array $sources, $lang, $source ) {
		if ( empty( $sources ) ) {
			return array();
		}

		$by_hash = array();
		foreach ( $sources as $text ) {
			$by_hash[ LEADST_DB::hash( $text ) ] = $text;
		}

		$found  = LEADST_DB::get_many( array_keys( $by_hash ), $lang );
		$result = array();

		foreach ( $found as $hash => $translated ) {
			if ( isset( $by_hash[ $hash ] ) ) {
				$result[ $by_hash[ $hash ] ] = $translated;
			}
		}

		$missing = array();
		foreach ( $by_hash as $hash => $text ) {
			if ( ! isset( $found[ $hash ] ) ) {
				$missing[] = $text;
			}
		}

		if ( empty( $missing ) ) {
			return $result;
		}

		// Always record misses so the background worker can pick them up.
		LEADST_DB::queue_strings( $missing, $lang );

		if ( ! $this->may_translate_live() ) {
			return $result;
		}

		$batch = array_slice( $missing, 0, self::LIVE_LIMIT );
		$chars = 0;
		foreach ( $batch as $text ) {
			$chars += LEADST_DB::char_count( $text );
		}

		$engine = LEADST_Engine::make_for( $chars, $lang );
		if ( ! $engine || ! $engine->is_configured() ) {
			return $result;
		}

		$translated = $engine->translate( $batch, $lang, $source );
		list( $engine, $translated ) = LEADST_Engine::recover_with_deepl( $engine, $translated, $batch, $lang, $source );

		if ( is_wp_error( $translated ) ) {
			set_transient( 'leadst_last_error', $translated->get_error_message(), HOUR_IN_SECONDS );
			return $result;
		}

		foreach ( $batch as $i => $text ) {
			if ( ! isset( $translated[ $i ] ) ) {
				continue;
			}
			LEADST_DB::save( $text, $lang, $translated[ $i ], LEADST_DB::STATUS_MACHINE, $engine->id() );
			$result[ $text ] = $translated[ $i ];
		}

		return $result;
	}

	/**
	 * Whether this request is allowed to call the API.
	 *
	 * @return bool
	 */
	private function may_translate_live() {
		// The crawler is our own request and bound by the same caps.
		if ( $this->is_warm_request() ) {
			return true;
		}

		if ( ! leadst()->get( 'live_translate' ) ) {
			return false;
		}

		// A crawler must never trigger paid translation. Googlebot walking a
		// hundred language trees would bill the entire site in one afternoon.
		if ( leadst()->get( 'block_bot_translate' ) && LEADST_Budget::is_bot() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether this request came from the plugin's own pre-fill crawler.
	 *
	 * @return bool
	 */
	private function is_warm_request() {
		if ( empty( $_SERVER['HTTP_X_LST_WARM'] ) ) {
			return false;
		}

		$sent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LST_WARM'] ) );

		return hash_equals( LEADST_Queue::warm_token(), $sent );
	}

	/**
	 * Keep the original leading and trailing whitespace around a translation.
	 *
	 * @param string $original   Original node value.
	 * @param string $translated Translated text.
	 * @return string
	 */
	private function preserve_spacing( $original, $translated ) {
		preg_match( '/^(\s*)/u', $original, $lead );
		preg_match( '/(\s*)$/u', $original, $trail );

		return ( isset( $lead[1] ) ? $lead[1] : '' ) . $translated . ( isset( $trail[1] ) ? $trail[1] : '' );
	}

	/**
	 * Point internal links and forms at the current language.
	 *
	 * @param DOMXPath $xpath Document xpath.
	 * @param string   $lang  Language slug.
	 * @return void
	 */
	private function rewrite_links( DOMXPath $xpath, $lang ) {
		$router = LEADST_Router::instance();

		$targets = array(
			'//a/@href'                     => true,
			'//form/@action'                => true,
			'//link[@rel="canonical"]/@href' => true,
			'//meta[@property="og:url"]/@content' => true,
		);

		foreach ( $targets as $query => $unused ) {
			$found = $xpath->query( $query );
			if ( ! $found ) {
				continue;
			}

			foreach ( $found as $attr ) {
				$value = trim( $attr->value );

				if ( '' === $value || ! $router->is_internal( $value ) ) {
					continue;
				}

				// Leave the language switcher's own links alone; they are built
				// with explicit target languages already.
				$owner = $attr->ownerElement;
				if ( $owner && $owner->hasAttribute( 'data-lst-lang' ) ) {
					continue;
				}

				$attr->value = htmlspecialchars( $router->translate_url( $lang, $value ), ENT_QUOTES, 'UTF-8' );
			}
		}
	}

	/**
	 * Update the html element's lang and dir attributes.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath Document xpath.
	 * @param string      $lang  Language slug.
	 * @return void
	 */
	private function set_document_language( DOMDocument $dom, DOMXPath $xpath, $lang ) {
		unset( $dom );

		$language = LEADST_Languages::get( $lang );
		if ( ! $language ) {
			return;
		}

		$html = $xpath->query( '//html' );
		if ( ! $html || 0 === $html->length ) {
			return;
		}

		$element = $html->item( 0 );
		$element->setAttribute( 'lang', $language['hreflang'] );
		$element->setAttribute( 'dir', $language['rtl'] ? 'rtl' : 'ltr' );
	}

	/**
	 * Cache key for the current translated page.
	 *
	 * @return string
	 */
	private function cache_key() {
		$lang = LEADST_Router::instance()->current();

		return 'leadst_page_' . md5(
			self::cache_version( $lang ) . '|' .
			$lang . '|' .
			LEADST_Router::instance()->current_url() . '|' .
			( is_user_logged_in() ? 'auth' : 'anon' )
		);
	}

	/**
	 * The cache generation for a language.
	 *
	 * Combines a global counter with a per-language one, so correcting a single
	 * Spanish string can invalidate Spanish pages without discarding every
	 * other language's cache at the same time.
	 *
	 * @param string $lang Language slug.
	 * @return string
	 */
	private static function cache_version( $lang ) {
		$global = (int) get_option( 'leadst_cache_version', 1 );
		$per    = get_option( 'leadst_cache_version_lang', array() );
		$per    = is_array( $per ) ? $per : array();

		return $global . '.' . ( isset( $per[ $lang ] ) ? (int) $per[ $lang ] : 1 );
	}

	/**
	 * Read a cached translated page.
	 *
	 * @param string $html Source HTML, used only for the fingerprint.
	 * @return string|null
	 */
	private function cache_get( $html ) {
		// The crawler needs a real render each pass, or a half-translated
		// page comes back from cache and the rest is never picked up.
		if ( ! leadst()->get( 'cache_pages' ) || is_user_logged_in() || $this->is_warm_request() ) {
			return null;
		}

		$cached = get_transient( $this->cache_key() );

		if ( is_array( $cached ) && isset( $cached['src'], $cached['out'] ) && $cached['src'] === md5( $html ) ) {
			return $cached['out'];
		}

		return null;
	}

	/**
	 * Store a translated page.
	 *
	 * @param string $html   Source HTML.
	 * @param string $result Translated HTML.
	 * @return void
	 */
	private function cache_set( $html, $result ) {
		if ( ! leadst()->get( 'cache_pages' ) || is_user_logged_in() || $this->is_warm_request() ) {
			return;
		}

		set_transient(
			$this->cache_key(),
			array(
				'src' => md5( $html ),
				'out' => $result,
			),
			max( 300, (int) leadst()->get( 'cache_ttl', DAY_IN_SECONDS ) )
		);
	}

	/**
	 * Invalidate cached pages.
	 *
	 * @param string $lang Language to invalidate, or empty for all of them.
	 * @return void
	 */
	public static function flush_cache( $lang = '' ) {
		if ( '' === $lang || ! LEADST_Languages::exists( $lang ) ) {
			update_option( 'leadst_cache_version', (int) get_option( 'leadst_cache_version', 1 ) + 1, false );
			return;
		}

		$per = get_option( 'leadst_cache_version_lang', array() );
		$per = is_array( $per ) ? $per : array();

		$per[ $lang ] = ( isset( $per[ $lang ] ) ? (int) $per[ $lang ] : 1 ) + 1;

		update_option( 'leadst_cache_version_lang', $per, false );
	}
}
