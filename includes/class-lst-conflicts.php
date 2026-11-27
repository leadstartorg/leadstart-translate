<?php
/**
 * Slug collisions between page names and language codes.
 *
 * Serving languages from the path means the first segment of every URL is
 * shared between two namespaces: your pages and the language codes. Most
 * language codes are two letters that nobody would name a page, but a handful
 * are ordinary English words or abbreviations — /it/ for Information
 * Technology, /my/ for a member area, /ca/ for California, /no/, /is/, /la/.
 *
 * This is the one real advantage subdomains have over subdirectories, because
 * a hostname does not share a namespace with anything. Since a plugin cannot
 * conjure subdomains on hosting that will not serve them, the collision is
 * handled here instead, in three parts:
 *
 *   1. New pages cannot take a language code as a top-level slug. WordPress
 *      appends a suffix, exactly as it does for any other reserved word.
 *   2. Collisions that already exist are found, recorded, and reported.
 *   3. At request time a recorded collision resolves in favor of the page, so
 *      content is never silently orphaned by switching a language on.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects, prevents and resolves language/slug collisions.
 */
class LEADST_Conflicts {

	const OPTION = 'leadst_slug_conflicts';

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Conflicts|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Conflicts
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		// Subdomain mode reserves nothing in the path, so none of this applies.
		if ( ! self::applies() ) {
			return;
		}

		add_filter( 'wp_unique_post_slug_is_bad_hierarchical_slug', array( $this, 'is_bad_hierarchical_slug' ), 10, 4 );
		add_filter( 'wp_unique_post_slug_is_bad_flat_slug', array( $this, 'is_bad_flat_slug' ), 10, 3 );

		add_action( 'save_post', array( $this, 'schedule_rescan' ), 20 );
		add_action( 'deleted_post', array( $this, 'schedule_rescan' ) );
		add_action( 'leadst_rescan_conflicts', array( $this, 'scan' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'notice' ) );
		}
	}

	/**
	 * Whether path/language collisions are possible at all.
	 *
	 * @return bool
	 */
	public static function applies() {
		return 'directory' === LEADST_Router::instance()->url_mode();
	}

	/**
	 * Every language code the plugin could ever serve from the path.
	 *
	 * All languages are reserved, not just the active ones — switching a
	 * language on later must never break a page that already exists.
	 *
	 * @return string[]
	 */
	public static function reserved() {
		return LEADST_Languages::slugs();
	}

	/**
	 * Whether a slug would collide with a language code.
	 *
	 * @param string $slug Slug to test.
	 * @return bool
	 */
	public static function is_reserved( $slug ) {
		return LEADST_Languages::exists( strtolower( (string) $slug ) );
	}

	/**
	 * Block a language code as a top-level page slug.
	 *
	 * @param bool   $is_bad      Whether the slug is already considered bad.
	 * @param string $slug        The slug.
	 * @param string $post_type   Post type.
	 * @param int    $post_parent Parent id.
	 * @return bool
	 */
	public function is_bad_hierarchical_slug( $is_bad, $slug, $post_type, $post_parent ) {
		unset( $post_type );

		if ( $is_bad ) {
			return true;
		}

		// Only the first path segment is ever read as a language, so a child
		// page can be called anything at all.
		if ( (int) $post_parent !== 0 ) {
			return false;
		}

		return self::is_reserved( $slug );
	}

	/**
	 * Block a language code as a post slug when posts sit at the site root.
	 *
	 * @param bool   $is_bad    Whether the slug is already considered bad.
	 * @param string $slug      The slug.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_bad_flat_slug( $is_bad, $slug, $post_type ) {
		unset( $post_type );

		if ( $is_bad ) {
			return true;
		}

		if ( ! $this->posts_are_top_level() ) {
			return false;
		}

		return self::is_reserved( $slug );
	}

	/**
	 * Whether the permalink structure puts post slugs at the site root.
	 *
	 * With /%postname%/ a post slug is a first path segment and can collide.
	 * With /%year%/%monthnum%/%postname%/ it never can.
	 *
	 * @return bool
	 */
	private function posts_are_top_level() {
		$structure = (string) get_option( 'permalink_structure', '' );

		if ( '' === $structure ) {
			return false;
		}

		$first = strtok( ltrim( $structure, '/' ), '/' );

		return '%postname%' === $first || '%pagename%' === $first;
	}

	/**
	 * Queue a rescan rather than running one on every save.
	 *
	 * @return void
	 */
	public function schedule_rescan() {
		if ( ! wp_next_scheduled( 'leadst_rescan_conflicts' ) ) {
			wp_schedule_single_event( time() + 30, 'leadst_rescan_conflicts' );
		}
	}

	/**
	 * Find every existing top-level slug that matches a language code.
	 *
	 * @return array Slug => array of human-readable descriptions.
	 */
	public function scan() {
		$reserved = self::reserved();
		if ( empty( $reserved ) ) {
			update_option( self::OPTION, array(), false );
			return array();
		}

		$types = array( 'page' );
		if ( $this->posts_are_top_level() ) {
			$types[] = 'post';
		}

		// get_posts() rather than a hand-built query: post_name__in does the
		// IN list, so there is no generated placeholder string, nothing
		// interpolated into SQL, and the result goes through the object
		// cache like any other post query.
		$posts = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'post_parent'      => 0,
				'post_name__in'    => $reserved,
				'numberposts'      => count( $reserved ) * 2,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		$found = array();

		foreach ( (array) $posts as $post ) {
			$found[ $post->post_name ][] = array(
				'id'    => (int) $post->ID,
				'title' => $post->post_title,
				'type'  => $post->post_type,
			);
		}

		update_option( self::OPTION, $found, false );

		return $found;
	}

	/**
	 * Recorded collisions.
	 *
	 * @return array Slug => list of colliding objects.
	 */
	public static function conflicts() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether a path segment is claimed by real content rather than a language.
	 *
	 * Read on every front-end request, so it is a single option lookup and an
	 * array test — no query.
	 *
	 * @param string $segment First path segment.
	 * @return bool
	 */
	public static function is_claimed_by_content( $segment ) {
		if ( ! self::applies() ) {
			return false;
		}

		$conflicts = self::conflicts();

		return isset( $conflicts[ strtolower( (string) $segment ) ] );
	}

	/**
	 * Collisions that actually matter right now — those on active languages.
	 *
	 * A collision on a language that is switched off is harmless, but still
	 * worth showing before that language is switched on.
	 *
	 * @return array Slug => list of colliding objects.
	 */
	public static function active_conflicts() {
		$active = LEADST_Languages::active();

		return array_intersect_key( self::conflicts(), $active );
	}

	/**
	 * Warn in the admin when a collision is live.
	 *
	 * @return void
	 */
	public function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$live = self::active_conflicts();

		if ( empty( $live ) ) {
			return;
		}

		$items = array();
		foreach ( $live as $slug => $objects ) {
			$titles = wp_list_pluck( $objects, 'title' );
			$items[] = sprintf(
				'<li><code>/%1$s/</code> &mdash; %2$s (%3$s)</li>',
				esc_html( $slug ),
				esc_html( implode( ', ', $titles ) ),
				esc_html( LEADST_Languages::label( $slug, false ) )
			);
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px">%s</ul><p>%s</p></div>',
			esc_html__( 'Leadstart Translate: a page name is the same as a language code.', 'leadstart-translate' ),
			implode( '', $items ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'The page is being served, and that language is not reachable at this address. Rename the page, or switch that language off.', 'leadstart-translate' )
		);
	}
}
