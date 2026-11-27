<?php
/**
 * Search engine signals.
 *
 * Indexable translations need three things: a real URL per language (the
 * router), reciprocal hreflang annotations, and a sitemap that lists them. This
 * class supplies the last two, plus a self-referencing canonical for each
 * translated page.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * hreflang output and per-language sitemaps.
 */
class LEADST_SEO {

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_SEO|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_SEO
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register rewrite rules and filters.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( $this, 'register_sitemap_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_sitemap' ), 1 );
		add_filter( 'robots_txt', array( $this, 'filter_robots' ), 10, 2 );
	}

	/**
	 * Rewrite rules for the language sitemaps.
	 *
	 * @return void
	 */
	public function register_sitemap_rules() {
		add_rewrite_rule( '^leadst-sitemap\.xml$', 'index.php?leadst_sitemap=index', 'top' );
		// Compatibility with sitemap URLs from earlier builds (lst-sitemap*, wptr-sitemap*).
		add_rewrite_rule( '^lst-sitemap\.xml$', 'index.php?leadst_sitemap=index', 'top' );
		add_rewrite_rule( '^lst-sitemap-([a-z-]+)-([1-9][0-9]*)\.xml$', 'index.php?leadst_sitemap=$matches[1]&leadst_sitemap_page=$matches[2]', 'top' );
		add_rewrite_rule( '^lst-sitemap-([a-z-]+)\.xml$', 'index.php?leadst_sitemap=$matches[1]', 'top' );
		add_rewrite_rule( '^wptr-sitemap\.xml$', 'index.php?leadst_sitemap=index', 'top' );
		add_rewrite_rule( '^wptr-sitemap-([a-z-]+)-([1-9][0-9]*)\.xml$', 'index.php?leadst_sitemap=$matches[1]&leadst_sitemap_page=$matches[2]', 'top' );
		add_rewrite_rule( '^wptr-sitemap-([a-z-]+)\.xml$', 'index.php?leadst_sitemap=$matches[1]', 'top' );
		add_rewrite_rule( '^leadst-sitemap-([a-z-]+)-([1-9][0-9]*)\.xml$', 'index.php?leadst_sitemap=$matches[1]&leadst_sitemap_page=$matches[2]', 'top' );
		add_rewrite_rule( '^leadst-sitemap-([a-z-]+)\.xml$', 'index.php?leadst_sitemap=$matches[1]', 'top' );
	}

	/**
	 * Expose the sitemap query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'leadst_sitemap';
		$vars[] = 'leadst_sitemap_page';
		return $vars;
	}

	/**
	 * hreflang, canonical and og:locale for the current page.
	 *
	 * @return void
	 */
	public function output_head_tags() {
		if ( ! leadst()->get( 'add_hreflang' ) ) {
			return;
		}

		if ( is_404() || is_search() || is_admin() ) {
			return;
		}

		$router    = LEADST_Router::instance();
		$languages = LEADST_Languages::active_with_source();
		$source    = leadst()->get( 'source_language', 'en' );

		if ( count( $languages ) < 2 ) {
			return;
		}

		$current_url = $router->current_url();

		echo "\n<!-- Leadstart Translate -->\n";

		foreach ( $languages as $slug => $language ) {
			printf(
				"<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n",
				esc_attr( $slug === $source ? LEADST_Languages::site_hreflang() : $language['hreflang'] ),
				esc_url( $router->translate_url( $slug, $current_url ) )
			);
		}

		printf(
			"<link rel=\"alternate\" hreflang=\"x-default\" href=\"%s\" />\n",
			esc_url( $router->translate_url( $source, $current_url ) )
		);

		$current = LEADST_Languages::get( $router->current() );
		if ( $current ) {
			$current_tag = $router->current() === $source
				? LEADST_Languages::site_hreflang()
				: $current['hreflang'];
			printf(
				"<meta property=\"og:locale\" content=\"%s\" />\n",
				esc_attr( str_replace( '-', '_', $current_tag ) )
			);
		}

		echo "<!-- /Leadstart Translate -->\n";
	}

	/**
	 * Render a sitemap when one was requested.
	 *
	 * @return void
	 */
	public function maybe_render_sitemap() {
		$requested = get_query_var( 'leadst_sitemap' );

		if ( ! $requested ) {
			return;
		}

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		if ( 'index' === $requested ) {
			$this->render_index();
		} elseif ( LEADST_Languages::exists( $requested ) ) {
			$this->render_language_sitemap( $requested, max( 1, (int) get_query_var( 'leadst_sitemap_page', 1 ) ) );
		} else {
			status_header( 404 );
		}

		exit;
	}

	/**
	 * Sitemap index listing one sitemap per language.
	 *
	 * @return void
	 */
	private function render_index() {
		$languages = LEADST_Languages::active_with_source();
		$types = (array) apply_filters( 'leadst_sitemap_post_types', array( 'post', 'page' ) );
		$query = new WP_Query( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => false, 'suppress_filters' => false ) );
		$pages = max( 1, (int) ceil( (int) $query->found_posts / 500 ) );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( array_keys( $languages ) as $slug ) {
			for ( $i = 1; $i <= $pages; $i++ ) {
				$part = 1 === $i ? '' : '-' . $i;
				printf( "\t<sitemap><loc>%s</loc></sitemap>\n", esc_url( home_url( '/leadst-sitemap-' . $slug . $part . '.xml' ) ) );
			}
		}
		echo '</sitemapindex>';
	}

	/**
	 * URL set for one language, with hreflang alternates on every entry.
	 *
	 * @param string $lang Language slug.
	 * @return void
	 */
	private function render_language_sitemap( $lang, $page = 1 ) {
		$router = LEADST_Router::instance();
		$languages = LEADST_Languages::active_with_source();
		$types = (array) apply_filters( 'leadst_sitemap_post_types', array( 'post', 'page' ) );
		$query = new WP_Query( array(
			'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => 500,
			'paged' => max( 1, (int) $page ), 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
			'no_found_rows' => true, 'suppress_filters' => false,
		) );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
		$entries = array();
		if ( 1 === (int) $page ) { $entries[ home_url( '/' ) ] = gmdate( 'c' ); }
		foreach ( (array) $query->posts as $id ) { $entries[ get_permalink( $id ) ] = get_post_modified_time( 'c', true, $id ); }
		foreach ( $entries as $url => $modified ) {
			printf( "\t<url>\n\t\t<loc>%s</loc>\n", esc_url( $router->translate_url( $lang, $url ) ) );
			foreach ( $languages as $slug => $language ) {
				printf( "\t\t<xhtml:link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n", esc_attr( $language['hreflang'] ), esc_url( $router->translate_url( $slug, $url ) ) );
			}
			printf( "\t\t<lastmod>%s</lastmod>\n\t</url>\n", esc_html( $modified ) );
		}
		echo '</urlset>';
	}

	/**
	 * Advertise the sitemap index in robots.txt.
	 *
	 * @param string $output Robots.txt body.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function filter_robots( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		return $output . "\nSitemap: " . home_url( '/leadst-sitemap.xml' ) . "\n";
	}
}
