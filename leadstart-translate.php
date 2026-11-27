<?php
/**
 * Plugin Name:       Leadstart Translate
 * Plugin URI:        https://wordpress.org/plugins/leadstart-translate/
 * Description:       Multilingual WordPress with selectable Google Cloud, Gemini, WordPress AI Client, Mistral, Qwen, or DeepL translation. Database-backed translation memory, indexable language URLs, translated slugs, hreflang, and an editor for correcting machine output. No plugin subscription; third-party translation APIs may incur charges.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Leadstart Media, Inc.
 * Author URI:        https://leadstart.org/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leadstart-translate
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

define( 'LEADST_VERSION', '1.0.0' );
define( 'LEADST_FILE', __FILE__ );
define( 'LEADST_PATH', plugin_dir_path( __FILE__ ) );
define( 'LEADST_URL', plugin_dir_url( __FILE__ ) );
define( 'LEADST_BASENAME', plugin_basename( __FILE__ ) );
define( 'LEADST_DB_VERSION', 6 );

require_once LEADST_PATH . 'includes/class-lst-languages.php';
require_once LEADST_PATH . 'includes/class-lst-db.php';
require_once LEADST_PATH . 'includes/class-lst-budget.php';
require_once LEADST_PATH . 'includes/class-lst-engine.php';
require_once LEADST_PATH . 'includes/class-lst-engine-fallback.php';
require_once LEADST_PATH . 'includes/class-lst-engine-ultimate.php';
require_once LEADST_PATH . 'includes/class-lst-engine-ai-client.php';
require_once LEADST_PATH . 'includes/class-lst-engine-chat.php';
require_once LEADST_PATH . 'includes/class-lst-engine-deepl.php';
require_once LEADST_PATH . 'includes/class-lst-compare.php';
require_once LEADST_PATH . 'includes/class-lst-router.php';
require_once LEADST_PATH . 'includes/class-lst-processor.php';
require_once LEADST_PATH . 'includes/class-lst-slugs.php';
require_once LEADST_PATH . 'includes/class-lst-seo.php';
require_once LEADST_PATH . 'includes/class-lst-queue.php';
require_once LEADST_PATH . 'includes/class-lst-switcher.php';
require_once LEADST_PATH . 'includes/class-lst-presentation.php';
require_once LEADST_PATH . 'includes/class-lst-conflicts.php';

if ( is_admin() ) {
	require_once LEADST_PATH . 'admin/class-lst-admin.php';
	require_once LEADST_PATH . 'admin/class-lst-editor.php';
}

/**
 * Central settings access and plugin bootstrap.
 */
final class LEADST_Plugin {

	const OPTION = 'leadst_settings';

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Cached settings array.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up the plugin.
	 */
	private function __construct() {
		// Assign the singleton before anything else: the router boots from this
		// constructor and calls leadst() while doing so.
		self::$instance = $this;

		LEADST_Router::instance()->boot();

		LEADST_SEO::instance()->boot();

		add_action( 'init', array( $this, 'maybe_upgrade' ) );
		add_action( 'init', array( LEADST_Queue::instance(), 'boot' ) );
		add_action( 'init', array( LEADST_Switcher::instance(), 'boot' ) );
		add_action( 'init', array( LEADST_Slugs::instance(), 'boot' ) );
		add_action( 'init', array( LEADST_Presentation::instance(), 'boot' ) );
		add_action( 'init', array( LEADST_Conflicts::instance(), 'boot' ) );

		add_action( 'template_redirect', array( LEADST_Processor::instance(), 'start_buffer' ), 0 );
		add_action( 'wp_head', array( LEADST_SEO::instance(), 'output_head_tags' ), 1 );

		if ( is_admin() ) {
			LEADST_Admin::instance()->boot();
			LEADST_Editor::instance()->boot();
		}

		register_activation_hook( LEADST_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( LEADST_FILE, array( __CLASS__, 'deactivate' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'source_language'      => LEADST_Languages::site_slug(),
			'active_languages'     => array(),
			'engine'               => 'google_v2',
			'google_api_key'       => '',
			'gemini_api_key'       => '',
			'gemini_model'         => 'gemini-2.5-flash',
			'mistral_api_key'      => '',
			'mistral_model'        => 'mistral-small-latest',
			'qwen_api_key'         => '',
			'qwen_model'           => 'qwen-plus',
			'qwen_region'          => 'singapore',
			'qwen_workspace'       => '',
			'deepl_api_key'        => '',
			'deepl_fallback_enabled' => 0,
			'deepl_daily_char_cap' => 50000,
			'deepl_monthly_char_cap' => 500000,
			'fallback_enabled'     => 0,
			'fallback_url'         => '',
			'fallback_token'       => '',
			'ultimate_enabled'     => 0,
			'ultimate_provider'    => 'gemini',
			'ultimate_api_key'     => '',
			'ultimate_model'       => '',
			'ai_client_enabled'    => 0,
			'url_mode'             => 'directory',
			'translate_slugs'      => 1,
			'canonical_slug_redirect' => 1,
			'add_hreflang'         => 1,
			'translate_meta'       => 1,
			'switcher_style'       => 'dropdown',
			'switcher_floating'    => 0,
			'native_fonts'         => 0,
			'rtl_support'          => 1,
			'daily_char_cap'       => 200000,
			'monthly_char_cap'     => 500000,
			'ai_daily_char_cap'    => 50000,
			'ai_monthly_char_cap'  => 300000,
			'block_bot_translate'  => 1,
			'live_translate'       => 1,
			'cache_pages'          => 1,
			'cache_ttl'            => 86400,
			'exclude_selectors'    => ".notranslate\n.skiptranslate\ncode\npre\n.woocommerce-customer-details\n.woocommerce-order-overview\n.wc-block-components-address-card\n.woocommerce-Address",
			'exclude_paths'        => "/wp-json/\n/wp-admin/",
			'preserve_terms'       => "",
			'queue_batch_size'     => 40,
			'warm_batch_size'      => 3,
			'db_version'           => 0,
		);
	}

	/**
	 * Get all settings, merged over defaults.
	 *
	 * @return array
	 */
	public function settings() {
		if ( null === $this->settings ) {
			$stored         = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->settings;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Persist a set of settings.
	 *
	 * @param array $values Values to merge in.
	 * @return void
	 */
	public function update( array $values ) {
		$settings = array_merge( $this->settings(), $values );
		update_option( self::OPTION, $settings );
		$this->settings = $settings;
	}

	/**
	 * Create tables on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		LEADST_DB::install();
		LEADST_Queue::schedule();
		LEADST_Conflicts::instance()->scan();
		update_option( 'leadst_flush_needed', 1 );

		if ( leadst()->get( 'translate_slugs' ) && LEADST_Languages::active() ) {
			LEADST_Slugs::instance()->queue_all_posts();
		}
	}

	/**
	 * Clear scheduled work on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		LEADST_Queue::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Run table upgrades when the DB version moves.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( (int) get_option( 'leadst_db_version', 0 ) !== LEADST_DB_VERSION ) {
			LEADST_DB::install();
			update_option( 'leadst_db_version', LEADST_DB_VERSION );
		}

		if ( get_option( 'leadst_flush_needed' ) ) {
			flush_rewrite_rules();
			delete_option( 'leadst_flush_needed' );
		}
	}

}

/**
 * Shorthand accessor.
 *
 * @return LEADST_Plugin
 */
function leadst() {
	return LEADST_Plugin::instance();
}

require_once LEADST_PATH . 'includes/class-lst-migration.php';

leadst_migrate_from_lst();
leadst_adopt_legacy_wptr();
leadst();
