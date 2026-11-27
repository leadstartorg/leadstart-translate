<?php
/**
 * Admin screens: settings, status and tools.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings and tools UI.
 */
class LEADST_Admin {

	const CAP = 'manage_options';

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Admin
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_leadst_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_leadst_run_tool', array( $this, 'handle_tool' ) );
		add_action( 'admin_post_leadst_diagnose', array( $this, 'handle_diagnose' ) );
		add_action( 'admin_post_leadst_compare', array( $this, 'handle_compare' ) );
		add_filter( 'plugin_action_links_' . LEADST_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Add the admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Translate', 'leadstart-translate' ),
			__( 'Translate', 'leadstart-translate' ),
			self::CAP,
			'leadst-settings',
			array( $this, 'render_settings' ),
			'dashicons-translation',
			76
		);

		add_submenu_page(
			'leadst-settings',
			__( 'Settings', 'leadstart-translate' ),
			__( 'Settings', 'leadstart-translate' ),
			self::CAP,
			'leadst-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			'leadst-settings',
			__( 'Status & Tools', 'leadstart-translate' ),
			__( 'Status & Tools', 'leadstart-translate' ),
			self::CAP,
			'leadst-status',
			array( $this, 'render_status' )
		);
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'leadst-' ) ) {
			return;
		}

		wp_enqueue_style( 'leadst-admin', LEADST_URL . 'assets/admin.css', array(), LEADST_VERSION );
		wp_enqueue_script( 'leadst-admin', LEADST_URL . 'assets/admin.js', array(), LEADST_VERSION, true );

		// wp_localize_script() casts every value to a string, and "0" is
		// truthy in JavaScript — which made the cost estimate read $0.00.
		wp_add_inline_script(
			'leadst-admin',
			'window.leadstAdmin = ' . wp_json_encode(
				array(
					'ratePerMillion' => (float) apply_filters( 'leadst_price_per_million_chars', 20.0 ),
					'freePerMonth'   => 500000,
					'siteChars'      => (int) LEADST_DB::source_character_estimate(),
					'engine'         => (string) leadst()->get( 'engine', 'google_v2' ),
					'i18n'           => array(
						/* translators: 1: character count, 2: dollar amount. */
						'estimate'   => __( 'Roughly %1$s characters to translate, about $%2$s at the standard rate.', 'leadstart-translate' ),
						'withinFree' => __( 'That fits inside Google\'s free monthly allowance.', 'leadstart-translate' ),
						/* translators: %s: number of months. */
						'months'     => __( 'Or no cost at all, spread over about %s months of the free allowance.', 'leadstart-translate' ),
						'noContent'  => __( 'Run the pre-fill crawl once and this will show a figure measured from your own pages.', 'leadstart-translate' ),
						'selected'   => __( 'selected', 'leadstart-translate' ),
						/* translators: %s: number of languages in the selection. */
						'selectAllConfirm' => __( 'Enable all %s languages?', 'leadstart-translate' ),
						/* translators: 1: characters, 2: estimated US dollar amount. */
						'selectAllCost' => __( 'That is about %1$s source characters and $%2$s at the example Google Cloud rate.', 'leadstart-translate' ),
						'providerEstimate' => __( 'Google Cloud cost estimates do not apply to this engine. Check its provider pricing and limits.', 'leadstart-translate' ),
						'showNewKey' => __( 'Show new', 'leadstart-translate' ),
						'hideNewKey' => __( 'Hide new', 'leadstart-translate' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Settings link on the plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=leadst-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'leadstart-translate' ) . '</a>' );
		return $links;
	}

	/**
	 * Surface the last API error and any missing configuration.
	 *
	 * @return void
	 */
	public function notices() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'leadst-' ) ) {
			return;
		}

		$error = get_transient( 'leadst_last_error' );
		if ( $error ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Last translation error:', 'leadstart-translate' ),
				esc_html( $error )
			);
		}

		$engine = LEADST_Engine::make();
		if ( ! $engine->is_configured() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'The selected translation engine is not configured. Check its key, model, provider connection, and region.', 'leadstart-translate' )
			);
		}
	}

	/**
	 * Render a credential replacement input without putting the stored secret in HTML.
	 *
	 * The "show" button applies only to a newly typed value. It never retrieves,
	 * renders or returns an already saved secret to the browser.
	 *
	 * @param string $name     Name of the stored setting.
	 * @param array  $settings Complete, server-side settings.
	 * @param string $classes  Classes for the input.
	 * @return void
	 */
	private function render_secret_input( $name, array $settings, $classes = 'regular-text' ) {
		$has_secret = isset( $settings[ $name ] ) && is_string( $settings[ $name ] ) && '' !== trim( $settings[ $name ] );
		$status_id  = 'leadst-secret-status-' . sanitize_html_class( $name );
		$placeholder = $has_secret
			? __( 'Saved credential hidden — enter a replacement', 'leadstart-translate' )
			: __( 'Enter a new credential', 'leadstart-translate' );
		$label = $has_secret
			? __( 'Configured — saved value is hidden', 'leadstart-translate' )
			: __( 'Not configured', 'leadstart-translate' );
		?>
		<span class="leadst-secret-field">
			<input type="password" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" class="<?php echo esc_attr( $classes ); ?> leadst-secret-input" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" aria-describedby="<?php echo esc_attr( $status_id ); ?>" />
			<button type="button" class="button leadst-secret-toggle" aria-controls="<?php echo esc_attr( $name ); ?>" aria-pressed="false" disabled><?php esc_html_e( 'Show new', 'leadstart-translate' ); ?></button>
			<span class="leadst-secret-status <?php echo $has_secret ? 'is-configured' : 'is-empty'; ?>" id="<?php echo esc_attr( $status_id ); ?>" role="status"><?php echo esc_html( $label ); ?></span>
		</span>
		<?php
	}

	/**
	 * Preserve existing credentials when a masked settings field is left blank.
	 * Keys may be cleared explicitly without ever rendering their values in HTML.
	 *
	 * @param array  $post Unslashed admin POST values.
	 * @param string $name Credential option.
	 * @return string
	 */
	private function save_secret( array $post, $name ) {
		if ( ! empty( $post[ 'clear_' . $name ] ) ) { return ''; }
		$value = isset( $post[ $name ] ) && is_string( $post[ $name ] )
			? trim( sanitize_text_field( $post[ $name ] ) ) : '';
		return '' !== $value ? $value : (string) leadst()->get( $name, '' );
	}

	/**
	 * Save the settings form.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leadstart-translate' ) );
		}

		check_admin_referer( 'leadst_save_settings' );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$languages = isset( $post['active_languages'] ) ? (array) $post['active_languages'] : array();
		$languages = array_values( array_filter( array_map( 'sanitize_key', $languages ), array( 'LEADST_Languages', 'exists' ) ) );

		$source    = LEADST_Languages::exists( $post['source_language'] ?? 'en' ) ? sanitize_key( $post['source_language'] ) : 'en';
		$languages = array_values( array_diff( $languages, array( $source ) ) );

		$values = array(
			'source_language'     => $source,
			'active_languages'    => $languages,
			'engine'              => in_array( $post['engine'] ?? '', array( 'google_v2', 'gemini', 'ai_client', 'mistral', 'qwen', 'deepl' ), true ) ? $post['engine'] : 'google_v2',
			'google_api_key'      => $this->save_secret( $post, 'google_api_key' ),
			'gemini_api_key'      => $this->save_secret( $post, 'gemini_api_key' ),
			'gemini_model'        => sanitize_text_field( $post['gemini_model'] ?? 'gemini-2.5-flash' ),
			'mistral_api_key'      => $this->save_secret( $post, 'mistral_api_key' ),
			'mistral_model'        => sanitize_text_field( $post['mistral_model'] ?? 'mistral-small-latest' ),
			'qwen_api_key'         => $this->save_secret( $post, 'qwen_api_key' ),
			'qwen_model'           => sanitize_text_field( $post['qwen_model'] ?? 'qwen-plus' ),
			'qwen_region'          => in_array( $post['qwen_region'] ?? '', array( 'singapore', 'virginia', 'frankfurt', 'tokyo', 'beijing', 'hongkong' ), true ) ? $post['qwen_region'] : 'singapore',
			'qwen_workspace'       => strtolower( sanitize_text_field( $post['qwen_workspace'] ?? '' ) ),
			'deepl_api_key'       => $this->save_secret( $post, 'deepl_api_key' ),
			'deepl_fallback_enabled' => empty( $post['deepl_fallback_enabled'] ) ? 0 : 1,
			'deepl_daily_char_cap' => max( 0, (int) ( $post['deepl_daily_char_cap'] ?? 50000 ) ),
			'deepl_monthly_char_cap' => max( 0, (int) ( $post['deepl_monthly_char_cap'] ?? 500000 ) ),
			'url_mode'            => in_array( $post['url_mode'] ?? '', array( 'directory', 'subdomain' ), true ) ? $post['url_mode'] : 'directory',
			'translate_slugs'     => empty( $post['translate_slugs'] ) ? 0 : 1,
			'canonical_slug_redirect' => empty( $post['canonical_slug_redirect'] ) ? 0 : 1,
			'add_hreflang'        => empty( $post['add_hreflang'] ) ? 0 : 1,
			'translate_meta'      => empty( $post['translate_meta'] ) ? 0 : 1,
			'live_translate'      => empty( $post['live_translate'] ) ? 0 : 1,
			'block_bot_translate' => empty( $post['block_bot_translate'] ) ? 0 : 1,
			'cache_pages'         => empty( $post['cache_pages'] ) ? 0 : 1,
			'switcher_floating'   => empty( $post['switcher_floating'] ) ? 0 : 1,
			'native_fonts'        => empty( $post['native_fonts'] ) ? 0 : 1,
			'rtl_support'         => empty( $post['rtl_support'] ) ? 0 : 1,
			'switcher_style'      => in_array( $post['switcher_style'] ?? '', array( 'dropdown', 'list', 'inline' ), true ) ? $post['switcher_style'] : 'dropdown',
			'daily_char_cap'      => max( 0, (int) ( $post['daily_char_cap'] ?? 0 ) ),
			'monthly_char_cap'    => max( 0, (int) ( $post['monthly_char_cap'] ?? 0 ) ),
			'ai_daily_char_cap'   => max( 0, (int) ( $post['ai_daily_char_cap'] ?? 50000 ) ),
			'ai_monthly_char_cap' => max( 0, (int) ( $post['ai_monthly_char_cap'] ?? 300000 ) ),
			'cache_ttl'           => max( 300, (int) ( $post['cache_ttl'] ?? DAY_IN_SECONDS ) ),
			'queue_batch_size'    => max( 5, min( 200, (int) ( $post['queue_batch_size'] ?? 40 ) ) ),
			'warm_batch_size'     => max( 1, min( 25, (int) ( $post['warm_batch_size'] ?? 3 ) ) ),
			'exclude_selectors'   => sanitize_textarea_field( $post['exclude_selectors'] ?? '' ),
			'exclude_paths'       => sanitize_textarea_field( $post['exclude_paths'] ?? '' ),
			'preserve_terms'      => sanitize_textarea_field( $post['preserve_terms'] ?? '' ),
			'fallback_enabled'    => empty( $post['fallback_enabled'] ) ? 0 : 1,
			'fallback_url'        => esc_url_raw( $post['fallback_url'] ?? '' ),
			'fallback_token'      => $this->save_secret( $post, 'fallback_token' ),
			'ultimate_enabled'    => empty( $post['ultimate_enabled'] ) ? 0 : 1,
			'ai_client_enabled'   => empty( $post['ai_client_enabled'] ) ? 0 : 1,
			'ultimate_provider'   => in_array( $post['ultimate_provider'] ?? '', array( 'gemini', 'openai' ), true ) ? $post['ultimate_provider'] : 'gemini',
			'ultimate_api_key'    => $this->save_secret( $post, 'ultimate_api_key' ),
			'ultimate_model'      => sanitize_text_field( $post['ultimate_model'] ?? '' ),
		);

		$was = (array) leadst()->get( 'active_languages', array() );

		leadst()->update( $values );
		LEADST_Processor::flush_cache();
		LEADST_Slugs::instance()->flush_cache();
		update_option( 'leadst_flush_needed', 1 );

		// Queue the slugs here rather than leaving it to a tool button, which
		// is how a site ends up with translated content and English URLs.
		// Also covers a map that is empty because the languages were switched
		// on before this ran, so saving the screen is enough to fix it.
		if ( $values['translate_slugs'] && $languages
			&& ( array_diff( $languages, $was ) || ! LEADST_Slugs::has_any() ) ) {
			LEADST_Slugs::instance()->queue_all_posts();
		}

		wp_safe_redirect( add_query_arg( 'leadst_saved', '1', admin_url( 'admin.php?page=leadst-settings' ) ) );
		exit;
	}

	/**
	 * Run a maintenance tool.
	 *
	 * @return void
	 */
	public function handle_tool() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leadstart-translate' ) );
		}

		check_admin_referer( 'leadst_run_tool' );

		// Most tools post a form; the per-language purge is a nonced link.
		$tool    = isset( $_REQUEST['tool'] ) ? sanitize_key( wp_unslash( $_REQUEST['tool'] ) ) : '';
		$message = '';

		switch ( $tool ) {
			case 'test_key':
				$engine = LEADST_Engine::make();
				$target = key( LEADST_Languages::active() );
				$target = $target ? $target : 'es';
				$result = $engine->translate( array( 'Hello, welcome to our website.' ), $target, leadst()->get( 'source_language', 'en' ) );

				if ( is_wp_error( $result ) ) {
					$message = __( 'Test failed: ', 'leadstart-translate' ) . $result->get_error_message();
				} else {
					$message = self::test_result_message( $engine, $target, isset( $result[0] ) ? $result[0] : '' );
				}
				break;

			case 'test_fallback':
				$fallback = new LEADST_Engine_Fallback();
				$health   = $fallback->health();

				if ( is_wp_error( $health ) ) {
					$message = __( 'Fallback service unreachable: ', 'leadstart-translate' ) . $health->get_error_message();
					break;
				}

				$target  = key( LEADST_Languages::active() );
				$target  = $target ? $target : 'es';
				$result  = $fallback->translate( array( 'Hello, welcome to our website.' ), $target, leadst()->get( 'source_language', 'en' ) );
				$backend = isset( $health['backend'] ) ? $health['backend'] : '?';

				if ( is_wp_error( $result ) ) {
					$message = __( 'Fallback test failed: ', 'leadstart-translate' ) . $result->get_error_message();
				} else {
					/* translators: 1: backend name, 2: the translated sample string. */
					$message = self::test_result_message( $fallback, $target, isset( $result[0] ) ? $result[0] : '' )
						. ' ' . sprintf( /* translators: %s: backend name. */ __( '(backend: %s)', 'leadstart-translate' ), $backend );
				}
				break;

			case 'test_ai_client':
				$ai     = new LEADST_Engine_AI_Client();
				$target = key( LEADST_Languages::active() );
				$target = $target ? $target : 'es';

				if ( ! $ai->is_configured() ) {
					$message = __( 'The WordPress AI Client tier is off, or this site has no AI provider configured.', 'leadstart-translate' );
					break;
				}

				$result = $ai->translate( array( 'Hello, welcome to our website.' ), $target, leadst()->get( 'source_language', 'en' ) );

				if ( is_wp_error( $result ) ) {
					$message = __( 'AI Client test failed: ', 'leadstart-translate' ) . $result->get_error_message();
				} else {
					$message = self::test_result_message( $ai, $target, isset( $result[0] ) ? $result[0] : '' );
				}
				break;

			case 'test_ultimate':
				$ultimate = new LEADST_Engine_Ultimate();
				$target   = key( LEADST_Languages::active() );
				$target   = $target ? $target : 'es';
				$result   = $ultimate->translate( array( 'Hello, welcome to our website.' ), $target, leadst()->get( 'source_language', 'en' ) );

				if ( is_wp_error( $result ) ) {
					$message = __( 'LLM test failed: ', 'leadstart-translate' ) . $result->get_error_message();
				} else {
					$message = self::test_result_message( $ultimate, $target, isset( $result[0] ) ? $result[0] : '' );
				}
				break;

			case 'start_warm':
				$count   = LEADST_Queue::instance()->build_warm_queue();
				/* translators: %d: number of page fetches queued. */
				$message = sprintf( __( 'Pre-fill started: %d page views queued.', 'leadstart-translate' ), $count );
				break;

			case 'stop_warm':
				LEADST_Queue::instance()->clear_warm_queue();
				$message = __( 'Pre-fill stopped.', 'leadstart-translate' );
				break;

			case 'run_now':
				$done    = LEADST_Queue::instance()->process_pending();
				$fetched = LEADST_Queue::instance()->process_warm();
				/* translators: 1: strings translated, 2: pages fetched. */
				$message = sprintf( __( 'Worker run: %1$d strings translated, %2$d pages pre-filled.', 'leadstart-translate' ), $done, $fetched );
				break;

			case 'translate_slugs':
				$count   = LEADST_Slugs::instance()->queue_all_posts();
				/* translators: %d: number of posts queued. */
				$message = sprintf( __( '%d posts queued for URL translation.', 'leadstart-translate' ), $count );
				break;

			case 'health_check':
				$health = LEADST_Router::instance()->health_check();

				if ( empty( $health ) ) {
					$message = __( 'Nothing to check — language hostnames only apply in subdomain mode.', 'leadstart-translate' );
					break;
				}

				$broken = array();
				foreach ( $health as $slug => $row ) {
					if ( empty( $row['ok'] ) ) {
						$broken[] = $slug . ' (' . $row['detail'] . ')';
					}
				}

				if ( empty( $broken ) ) {
					/* translators: %d: number of hostnames. */
					$message = sprintf( __( 'All %d language hostnames are being served by the proxy.', 'leadstart-translate' ), count( $health ) );
				} else {
					/* translators: 1: number failing, 2: details. */
					$message = sprintf( __( '%1$d language hostnames are NOT served by the proxy: %2$s', 'leadstart-translate' ), count( $broken ), implode( '; ', $broken ) );
				}
				break;

			case 'scan_conflicts':
				$found = LEADST_Conflicts::instance()->scan();
				$message = $found
					/* translators: %s: comma separated list of slugs. */
					? sprintf( __( 'Page names clashing with a language code: %s', 'leadstart-translate' ), implode( ', ', array_keys( $found ) ) )
					: __( 'No page name clashes with a language code.', 'leadstart-translate' );
				break;

			case 'flush_cache':
				LEADST_Processor::flush_cache();
				LEADST_Slugs::instance()->flush_cache();
				$message = __( 'Caches cleared.', 'leadstart-translate' );
				break;

			case 'purge_language':
				$lang = isset( $_REQUEST['lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) : '';
				if ( LEADST_Languages::exists( $lang ) ) {
					$rows = LEADST_DB::purge_language( $lang );
					LEADST_Processor::flush_cache();
					/* translators: 1: number of strings, 2: language name. */
					$message = sprintf( __( 'Deleted %1$d stored strings for %2$s.', 'leadstart-translate' ), $rows, LEADST_Languages::label( $lang, false ) );
				}
				break;

			case 'reset_usage':
				delete_option( LEADST_Budget::DAILY_OPTION );
				delete_option( LEADST_Budget::MONTHLY_OPTION );
				$message = __( 'Usage counters reset.', 'leadstart-translate' );
				break;
		}

		set_transient( 'leadst_tool_message', $message, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=leadst-status' ) );
		exit;
	}

	/**
	 * Say what a test actually did.
	 *
	 * "Test succeeded: Салам, бзиала шәаабеит" tells you nothing on a site
	 * with every language switched on -- the tool picks the first active
	 * language, and on that site it was Abkhaz. Name the engine and both
	 * languages instead.
	 *
	 * @param LEADST_Engine $engine Engine that ran.
	 * @param string      $target Target language slug.
	 * @param string      $output What came back.
	 * @return string
	 */
	private static function test_result_message( $engine, $target, $output ) {
		$source_slug = leadst()->get( 'source_language', 'en' );
		$source      = LEADST_Languages::get( $source_slug );
		$to          = LEADST_Languages::get( $target );

		return sprintf(
			/* translators: 1: engine name, 2: source language, 3: target language, 4: translated text. */
			__( '%1$s succeeded. %2$s to %3$s: %4$s', 'leadstart-translate' ),
			LEADST_Engine::label_for( $engine->id() ),
			$source ? $source['name'] : $source_slug,
			$to ? $to['name'] : $target,
			$output
		);
	}

	/**
	 * Scan a page for comparison, or run the comparison that was scanned.
	 *
	 * Two steps on purpose. The first calls nothing and only reports what a
	 * comparison would cost; the second is the confirmation, and is the only
	 * one that spends anything.
	 *
	 * @return void
	 */
	public function handle_compare() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leadstart-translate' ) );
		}

		check_admin_referer( 'leadst_compare' );

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'scan';

		delete_transient( LEADST_Compare::result_key() );

		if ( 'run' === $step ) {
			$result = LEADST_Compare::run();
		} else {
			delete_transient( LEADST_Compare::scan_key() );

			$url    = isset( $_POST['compare_url'] ) ? esc_url_raw( wp_unslash( $_POST['compare_url'] ) ) : '';
			$target = isset( $_POST['compare_lang'] ) ? sanitize_key( wp_unslash( $_POST['compare_lang'] ) ) : '';

			$result = LEADST_Compare::scan( $url, $target );
		}

		if ( is_wp_error( $result ) ) {
			set_transient( 'leadst_tool_message', $result->get_error_message(), 60 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=leadst-status#leadst-compare' ) );
		exit;
	}

	/**
	 * Trace one URL through the router and keep the report for one page load.
	 *
	 * @return void
	 */
	public function handle_diagnose() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leadstart-translate' ) );
		}

		check_admin_referer( 'leadst_diagnose' );

		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';

		if ( '' !== $path ) {
			set_transient(
				'leadst_diag_report',
				array(
					'path' => $path,
					'rows' => LEADST_Router::instance()->diagnose( $path ),
				),
				60
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=leadst-status#leadst-diagnose' ) );
		exit;
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$settings = leadst()->settings();

		// Two lists, not one. The site-language dropdown has to offer every
		// language including the current one, or it renders with nothing
		// selected and saving the form moves the site to whichever language
		// sorts first. The target grid is the same list minus the source,
		// because translating a language into itself is not a choice.
		$languages = LEADST_Languages::all();
		$targets   = $languages;
		unset( $targets[ leadst()->get( 'source_language', 'en' ) ] );

		$active  = (array) $settings['active_languages'];
		$clashes = LEADST_Conflicts::conflicts();
		?>
		<div class="wrap leadst-wrap">
			<h1><?php esc_html_e( 'Leadstart Translate', 'leadstart-translate' ); ?></h1>

			<?php if ( isset( $_GET['leadst_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'leadstart-translate' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="leadst_save_settings" />
				<?php wp_nonce_field( 'leadst_save_settings' ); ?>

				<h2 class="title"><?php esc_html_e( 'Translation engine', 'leadstart-translate' ); ?></h2>
				<p><?php esc_html_e( 'Choose one primary engine. Other engines are optional. External providers are contacted only when selected or enabled as a fallback.', 'leadstart-translate' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="leadst_engine"><?php esc_html_e( 'Primary translation engine', 'leadstart-translate' ); ?></label></th>
						<td>
							<select name="engine" id="leadst_engine">
								<option value="google_v2" <?php selected( $settings['engine'], 'google_v2' ); ?>><?php esc_html_e( 'Google Cloud Translation (Neural)', 'leadstart-translate' ); ?></option>
								<option value="gemini" <?php selected( $settings['engine'], 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'leadstart-translate' ); ?></option>
								<option value="ai_client" <?php selected( $settings['engine'], 'ai_client' ); ?>><?php esc_html_e( 'WordPress AI Client (WordPress 7.0+)', 'leadstart-translate' ); ?></option>
								<option value="mistral" <?php selected( $settings['engine'], 'mistral' ); ?>><?php esc_html_e( 'Mistral AI', 'leadstart-translate' ); ?></option>
								<option value="qwen" <?php selected( $settings['engine'], 'qwen' ); ?>><?php esc_html_e( 'Qwen (Alibaba Cloud Model Studio)', 'leadstart-translate' ); ?></option>
								<option value="deepl" <?php selected( $settings['engine'], 'deepl' ); ?>><?php esc_html_e( 'DeepL API (Free or Pro)', 'leadstart-translate' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Google Cloud, DeepL and AI providers have separate character-volume guards below. These are not currency caps: Gemini, Mistral, Qwen and WordPress AI Client may charge by tokens. Also set provider-side billing limits.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="google_api_key"><?php esc_html_e( 'Google Cloud Translation API key', 'leadstart-translate' ); ?></label></th>
						<td>
							<?php $this->render_secret_input( 'google_api_key', $settings, 'regular-text' ); ?>
							<label><input type="checkbox" name="clear_google_api_key" value="1" /> <?php esc_html_e( 'Remove key', 'leadstart-translate' ); ?></label>
							<p class="description"><?php esc_html_e( 'Create a server-side API key in Google Cloud and restrict it to the Cloud Translation API.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gemini_api_key"><?php esc_html_e( 'Gemini API key and model', 'leadstart-translate' ); ?></label></th>
						<td>
							<?php $this->render_secret_input( 'gemini_api_key', $settings, 'regular-text' ); ?>
							<label><input type="checkbox" name="clear_gemini_api_key" value="1" /> <?php esc_html_e( 'Remove key', 'leadstart-translate' ); ?></label><br />
							<input type="text" class="regular-text code" name="gemini_model" value="<?php echo esc_attr( $settings['gemini_model'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mistral_api_key"><?php esc_html_e( 'Mistral API key and model', 'leadstart-translate' ); ?></label></th>
						<td>
							<?php $this->render_secret_input( 'mistral_api_key', $settings, 'regular-text' ); ?>
							<label><input type="checkbox" name="clear_mistral_api_key" value="1" /> <?php esc_html_e( 'Remove key', 'leadstart-translate' ); ?></label><br />
							<input type="text" class="regular-text code" name="mistral_model" value="<?php echo esc_attr( $settings['mistral_model'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Uses the official Mistral chat-completions endpoint. Optional; no call is made without selecting Mistral.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="qwen_api_key"><?php esc_html_e( 'Qwen (Alibaba Cloud) API key', 'leadstart-translate' ); ?></label></th>
						<td>
							<?php $this->render_secret_input( 'qwen_api_key', $settings, 'regular-text' ); ?>
							<label><input type="checkbox" name="clear_qwen_api_key" value="1" /> <?php esc_html_e( 'Remove key', 'leadstart-translate' ); ?></label><br />
							<input type="text" class="regular-text code" name="qwen_model" value="<?php echo esc_attr( $settings['qwen_model'] ); ?>" />
							<p><label for="qwen_workspace"><?php esc_html_e( 'Model Studio workspace ID', 'leadstart-translate' ); ?></label><br />
							<input type="text" class="regular-text code" name="qwen_workspace" id="qwen_workspace" value="<?php echo esc_attr( $settings['qwen_workspace'] ); ?>" /></p>
							<p><label for="qwen_region"><?php esc_html_e( 'API region (must match your key)', 'leadstart-translate' ); ?></label><br />
							<select name="qwen_region" id="qwen_region">
								<?php foreach ( array( 'singapore' => 'Singapore', 'virginia' => 'US (Virginia)', 'frankfurt' => 'Germany (Frankfurt)', 'tokyo' => 'Japan (Tokyo)', 'beijing' => 'China (Beijing)', 'hongkong' => 'Hong Kong' ) as $region_code => $region_label ) : ?>
									<option value="<?php echo esc_attr( $region_code ); ?>" <?php selected( $settings['qwen_region'], $region_code ); ?>><?php echo esc_html( $region_label ); ?></option>
								<?php endforeach; ?>
							</select></p>
							<p class="description"><?php esc_html_e( 'Uses a fixed, region-specific Model Studio endpoint. Your workspace ID and API key must be from the same region.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="deepl_api_key"><?php esc_html_e( 'DeepL API key (optional)', 'leadstart-translate' ); ?></label></th>
						<td>
							<?php $this->render_secret_input( 'deepl_api_key', $settings, 'regular-text' ); ?>
							<label><input type="checkbox" name="clear_deepl_api_key" value="1" /> <?php esc_html_e( 'Remove key', 'leadstart-translate' ); ?></label>
							<p><label><input type="checkbox" name="deepl_fallback_enabled" value="1" <?php checked( $settings['deepl_fallback_enabled'] ); ?> /> <?php esc_html_e( 'Use DeepL as the first automatic background fallback when another primary engine fails or reaches its limit', 'leadstart-translate' ); ?></label></p>
							<p class="description"><?php esc_html_e( 'DeepL can also be selected as the primary engine above. Free API keys ending :fx use the free endpoint; Pro keys use the Pro endpoint. Only DeepL-supported languages are sent, and these requests count against separate local volume limits. Fallback is disabled by default.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WordPress AI Client fallback', 'leadstart-translate' ); ?></th>
						<td>
							<?php $ai_ready = function_exists( 'wp_ai_client_prompt' ); ?>
							<label><input type="checkbox" name="ai_client_enabled" value="1" <?php checked( $settings['ai_client_enabled'] ); ?> <?php disabled( ! $ai_ready ); ?> /> <?php esc_html_e( 'Also allow the WordPress AI Client as an optional fallback', 'leadstart-translate' ); ?></label>
							<p class="description"><?php esc_html_e( 'The primary dropdown can select the AI Client directly. It uses the provider configured at Settings → Connectors, and stores no additional key here. This checkbox enables it as a background-only fallback for other primary engines.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Optional self-hosted fallback service', 'leadstart-translate' ); ?></th>
						<td>
							<label><input type="checkbox" name="fallback_enabled" value="1" <?php checked( $settings['fallback_enabled'] ); ?> /> <?php esc_html_e( 'Use my translation service as a background fallback when the primary engine cannot be selected', 'leadstart-translate' ); ?></label>
							<p><label for="fallback_url"><?php esc_html_e( 'Service URL', 'leadstart-translate' ); ?></label><br />
							<input type="url" class="regular-text code" id="fallback_url" name="fallback_url" value="<?php echo esc_attr( $settings['fallback_url'] ); ?>" placeholder="http://127.0.0.1:8765/translate" /></p>
							<p><label for="fallback_token"><?php esc_html_e( 'Shared service token', 'leadstart-translate' ); ?></label><br />
							<?php $this->render_secret_input( 'fallback_token', $settings, 'regular-text code' ); ?>
							<label><input type="checkbox" name="clear_fallback_token" value="1" /> <?php esc_html_e( 'Remove token', 'leadstart-translate' ); ?></label></p>
							<p class="description"><?php esc_html_e( 'For a self-hosted translation service, such as LibreTranslate. Do not enable unless its endpoint is private, authenticated, and under your control.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Optional bulk-LLM fallback', 'leadstart-translate' ); ?></th>
						<td>
							<label><input type="checkbox" name="ultimate_enabled" value="1" <?php checked( $settings['ultimate_enabled'] ); ?> /> <?php esc_html_e( 'Use a bulk LLM as a background-only final fallback', 'leadstart-translate' ); ?></label>
							<p><select name="ultimate_provider">
								<option value="gemini" <?php selected( $settings['ultimate_provider'], 'gemini' ); ?>><?php esc_html_e( 'Gemini', 'leadstart-translate' ); ?></option>
								<option value="openai" <?php selected( $settings['ultimate_provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'leadstart-translate' ); ?></option>
							</select>
							<?php $this->render_secret_input( 'ultimate_api_key', $settings, 'regular-text' ); ?>
							<label><input type="checkbox" name="clear_ultimate_api_key" value="1" /> <?php esc_html_e( 'Remove key', 'leadstart-translate' ); ?></label></p>
							<p><input type="text" class="regular-text code" name="ultimate_model" value="<?php echo esc_attr( $settings['ultimate_model'] ); ?>" placeholder="<?php esc_attr_e( 'Model (blank = default)', 'leadstart-translate' ); ?>" /></p>
							<p class="description"><?php esc_html_e( 'API provider billing is separate from Google Cloud Translation character limits. Configure spending limits directly with your provider.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Languages', 'leadstart-translate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="source_language"><?php esc_html_e( 'Site language', 'leadstart-translate' ); ?></label></th>
						<td>
							<select name="source_language" id="source_language">
								<?php foreach ( $languages as $slug => $language ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['source_language'], $slug ); ?>>
										<?php echo esc_html( $language['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The language your content is written in. This one is served at the normal URLs, with no prefix.', 'leadstart-translate' ); ?></p>
							<p class="description">
								<?php
								$wp_locale   = get_locale();
								$wp_reported = get_bloginfo( 'language' );
								$wp_slug     = LEADST_Languages::site_slug();
								$wp_row      = LEADST_Languages::get( $wp_slug );

								printf(
									/* translators: 1: WordPress locale, 2: html lang value, 3: matched language name. */
									esc_html__( 'WordPress has this site set to %1$s, publishes it as lang="%2$s", and that matches %3$s. The same values are in the REST API at /wp-json/wp/v2/settings. This is what the picker defaults to on a new install, and what the site language is annotated as.', 'leadstart-translate' ),
									esc_html( $wp_locale ),
									esc_html( $wp_reported ),
									esc_html( $wp_row ? $wp_row['name'] : $wp_slug )
								);

								if ( $wp_slug !== $settings['source_language'] ) {
									echo ' ';
									esc_html_e( 'You have chosen a different source language, which is kept.', 'leadstart-translate' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Translate into', 'leadstart-translate' ); ?></th>
						<td>
							<div class="leadst-language-controls">
								<label for="leadst-language-search"><?php esc_html_e( 'Find language', 'leadstart-translate' ); ?></label>
								<input type="search" id="leadst-language-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by name or code', 'leadstart-translate' ); ?>" autocomplete="off" aria-controls="leadst-language-grid" />
								<label class="leadst-selected-only"><input type="checkbox" id="leadst-language-selected-only" /> <?php esc_html_e( 'Show selected only', 'leadstart-translate' ); ?></label>
							</div>
							<p class="leadst-bulk">
								<button type="button" class="button" id="leadst-select-all"><?php esc_html_e( 'Select all', 'leadstart-translate' ); ?></button>
								<button type="button" class="button" id="leadst-select-none"><?php esc_html_e( 'Select none', 'leadstart-translate' ); ?></button>
								<span class="leadst-count"><span id="leadst-selected-count"><?php echo count( $active ); ?></span> <?php esc_html_e( 'selected', 'leadstart-translate' ); ?></span>
							</p>
							<p class="leadst-estimate" id="leadst-estimate" role="status" aria-live="polite"></p>
							<?php $source_language = LEADST_Languages::get( $settings['source_language'] ); ?>
							<?php if ( $source_language ) : ?>
								<p class="leadst-source">
									<?php
									printf(
										/* translators: %s: the site's own language, for example English (US). */
										esc_html__( '%s is your site language. It is served at the normal URLs and is always offered in the switcher.', 'leadstart-translate' ),
										'<strong>' . esc_html( $source_language['name'] ) . '</strong>'
									);
									?>
								</p>
							<?php endif; ?>
							<div class="leadst-language-grid" id="leadst-language-grid" role="group" tabindex="0" aria-label="<?php esc_attr_e( 'Languages to translate into', 'leadstart-translate' ); ?>">
								<?php
								foreach ( $targets as $slug => $language ) :
									$clash = isset( $clashes[ $slug ] );
									?>
									<?php
									$clash_title = '';
									if ( $clash ) {
										$clash_title = sprintf(
											/* translators: 1: URL path segment, 2: comma separated page titles. */
											__( 'A page is already published at /%1$s/ (%2$s). That page is served there; this language would need a different code.', 'leadstart-translate' ),
											$slug,
											implode( ', ', wp_list_pluck( $clashes[ $slug ], 'title' ) )
										);
									}
									?>
									<label class="leadst-language<?php echo $clash ? ' leadst-language--clash' : ''; ?>"
										<?php if ( $clash ) : ?>
											title="<?php echo esc_attr( $clash_title ); ?>"
										<?php endif; ?>
									>
										<input type="checkbox" name="active_languages[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $active, true ) ); ?> />
										<span class="leadst-language__name">
											<?php echo esc_html( $language['name'] ); ?>
											<?php if ( $clash ) : ?>
												<span class="leadst-language__clash" aria-label="<?php esc_attr_e( 'Name clash with an existing page', 'leadstart-translate' ); ?>">!</span>
											<?php endif; ?>
										</span>
										<span class="leadst-language__code"><?php echo esc_html( $slug ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="leadst-language-no-results" id="leadst-language-no-results" role="status" hidden><?php esc_html_e( 'No languages match this search.', 'leadstart-translate' ); ?></p>
							<p class="description">
								<?php esc_html_e( 'Each added language increases translation volume. Google Cloud and DeepL bill by characters; AI providers may charge by tokens and have separate budgets. Review your provider limits before pre-filling many languages.', 'leadstart-translate' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'URLs and search engines', 'leadstart-translate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'URL structure', 'leadstart-translate' ); ?></th>
						<td>
							<?php $host = wp_parse_url( home_url(), PHP_URL_HOST ); ?>
							<label>
								<input type="radio" name="url_mode" value="directory" <?php checked( $settings['url_mode'], 'directory' ); ?> />
								<?php esc_html_e( 'Subdirectories', 'leadstart-translate' ); ?>
								<code><?php echo esc_html( untrailingslashit( home_url() ) ); ?>/es/about-us/</code>
							</label><br />
							<label>
								<input type="radio" name="url_mode" value="subdomain" <?php checked( $settings['url_mode'], 'subdomain' ); ?> />
								<?php esc_html_e( 'Subdomains', 'leadstart-translate' ); ?>
								<code>https://es.<?php echo esc_html( (string) $host ); ?>/about-us/</code>
							</label>
							<p class="description">
								<?php esc_html_e( 'Google treats subdirectories, subdomains and country domains as equally valid for indexing, so this is an operations decision, not an SEO one.', 'leadstart-translate' ); ?>
							</p>
							<div class="leadst-warn">
								<strong><?php esc_html_e( 'Subdomains need something in front of this site that answers for those hostnames.', 'leadstart-translate' ); ?></strong>
								<p><?php esc_html_e( 'Either hosting that routes every language hostname to this install directly, or an edge proxy such as a Cloudflare Worker. Managed platforms that attach extra domains as redirects to a primary address cannot do the first — the language host bounces visitors back to the main domain.', 'leadstart-translate' ); ?></p>
								<p><?php esc_html_e( 'Behind an edge proxy the language arrives in a signed header instead of the hostname, and nothing at all is reserved in your URL path — your page names, permalinks and rewrites are untouched. Give the proxy this token:', 'leadstart-translate' ); ?></p>
								<p><input type="text" readonly class="regular-text code" onclick="this.select()" value="<?php echo esc_attr( LEADST_Router::edge_token() ); ?>" /></p>
								<p><?php esc_html_e( 'Keep it secret and store it as an encrypted variable. Anyone holding it can ask this site to render any language.', 'leadstart-translate' ); ?></p>
								<p><?php esc_html_e( 'Cross-subdomain logins also need COOKIE_DOMAIN set in wp-config.php.', 'leadstart-translate' ); ?></p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'leadstart-translate' ); ?></th>
						<td>
							<label><input type="checkbox" name="translate_slugs" value="1" <?php checked( $settings['translate_slugs'] ); ?> /> <?php esc_html_e( 'Translate URL slugs (/es/sobre-nosotros/ instead of /es/about-us/)', 'leadstart-translate' ); ?></label><br />
							<label><input type="checkbox" name="canonical_slug_redirect" value="1" <?php checked( $settings['canonical_slug_redirect'] ); ?> /> <?php esc_html_e( 'Redirect untranslated slugs to their translated form (/es/about-us/ to /es/sobre-nosotros/)', 'leadstart-translate' ); ?></label><br />
							<label><input type="checkbox" name="add_hreflang" value="1" <?php checked( $settings['add_hreflang'] ); ?> /> <?php esc_html_e( 'Output hreflang tags and per-language sitemaps', 'leadstart-translate' ); ?></label><br />
							<label><input type="checkbox" name="translate_meta" value="1" <?php checked( $settings['translate_meta'] ); ?> /> <?php esc_html_e( 'Translate page titles, meta descriptions and social tags', 'leadstart-translate' ); ?></label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: sitemap URL. */
									esc_html__( 'Sitemap index: %s', 'leadstart-translate' ),
									'<code>' . esc_html( home_url( '/leadst-sitemap.xml' ) ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Spending limits', 'leadstart-translate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="daily_char_cap"><?php esc_html_e( 'Google Cloud daily characters', 'leadstart-translate' ); ?></label></th>
						<td>
							<input type="number" min="0" step="1000" id="daily_char_cap" name="daily_char_cap" value="<?php echo esc_attr( $settings['daily_char_cap'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( '0 means no limit. The plugin refuses to call the API once the cap is reached; nothing breaks, pages just serve untranslated strings until the next day.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="monthly_char_cap"><?php esc_html_e( 'Google Cloud monthly characters', 'leadstart-translate' ); ?></label></th>
						<td>
							<input type="number" min="0" step="10000" id="monthly_char_cap" name="monthly_char_cap" value="<?php echo esc_attr( $settings['monthly_char_cap'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'This is a local usage guard, not a provider billing guarantee. Verify current Cloud Translation prices and allowances directly with Google.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="deepl_daily_char_cap"><?php esc_html_e( 'DeepL daily characters', 'leadstart-translate' ); ?></label></th>
						<td>
							<input type="number" min="0" step="1000" id="deepl_daily_char_cap" name="deepl_daily_char_cap" value="<?php echo esc_attr( $settings['deepl_daily_char_cap'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Local daily request-volume guard; default 50,000 characters. Zero removes this guard.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="deepl_monthly_char_cap"><?php esc_html_e( 'DeepL monthly characters', 'leadstart-translate' ); ?></label></th>
						<td>
							<input type="number" min="0" step="10000" id="deepl_monthly_char_cap" name="deepl_monthly_char_cap" value="<?php echo esc_attr( $settings['deepl_monthly_char_cap'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Local monthly guard; default 500,000 characters. DeepL Free/Pro limits and billing are enforced by the provider separately.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai_daily_char_cap"><?php esc_html_e( 'AI providers daily source characters', 'leadstart-translate' ); ?></label></th>
						<td>
							<input type="number" min="0" step="1000" id="ai_daily_char_cap" name="ai_daily_char_cap" value="<?php echo esc_attr( $settings['ai_daily_char_cap'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Shared limit for Gemini, WordPress AI Client, Mistral, Qwen, and bulk LLM fallback. Default 50,000 source characters/day. Zero disables this guard.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai_monthly_char_cap"><?php esc_html_e( 'AI providers monthly source characters', 'leadstart-translate' ); ?></label></th>
						<td>
							<input type="number" min="0" step="10000" id="ai_monthly_char_cap" name="ai_monthly_char_cap" value="<?php echo esc_attr( $settings['ai_monthly_char_cap'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Shared limit for all token-priced providers. Default 300,000 source characters/month. This does not calculate tokens, costs, or guarantee provider billing limits.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Behavior', 'leadstart-translate' ); ?></th>
						<td>
							<label><input type="checkbox" name="live_translate" value="1" <?php checked( $settings['live_translate'] ); ?> /> <?php esc_html_e( 'Translate new strings during a visitor pageview', 'leadstart-translate' ); ?></label><br />
							<label><input type="checkbox" name="block_bot_translate" value="1" <?php checked( $settings['block_bot_translate'] ); ?> /> <?php esc_html_e( 'Never let a crawler trigger a paid API call (strongly recommended)', 'leadstart-translate' ); ?></label><br />
							<label><input type="checkbox" name="cache_pages" value="1" <?php checked( $settings['cache_pages'] ); ?> /> <?php esc_html_e( 'Cache translated pages', 'leadstart-translate' ); ?></label>
							<p class="description"><?php esc_html_e( 'With bot translation blocked, Googlebot only ever sees what has already been translated. Run the pre-fill on the Status screen before submitting sitemaps.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'How each language looks', 'leadstart-translate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Native presentation', 'leadstart-translate' ); ?></th>
						<td>
							<label><input type="checkbox" name="native_fonts" value="1" <?php checked( $settings['native_fonts'] ); ?> /> <?php esc_html_e( 'Load a typeface that covers the script being shown', 'leadstart-translate' ); ?></label><br />
							<label><input type="checkbox" name="rtl_support" value="1" <?php checked( $settings['rtl_support'] ); ?> /> <?php esc_html_e( 'Flip the layout for right-to-left languages', 'leadstart-translate' ); ?></label>
							<p class="description">
								<?php esc_html_e( 'This is what makes a language read as its own site rather than an English page with foreign words in it. Your theme font has no Korean, Arabic or Amharic glyphs, so the browser is already substituting something arbitrary — naming a real face replaces that fallback rather than overriding your design. Latin-script languages are left alone entirely.', 'leadstart-translate' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Every language also gets body classes — leadst-lang-es, leadst-script-arabic, leadst-rtl — so you can style any language further in your theme.', 'leadstart-translate' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="switcher_style"><?php esc_html_e( 'Switcher style', 'leadstart-translate' ); ?></label></th>
						<td>
							<select name="switcher_style" id="switcher_style">
								<option value="dropdown" <?php selected( $settings['switcher_style'], 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'leadstart-translate' ); ?></option>
								<option value="list" <?php selected( $settings['switcher_style'], 'list' ); ?>><?php esc_html_e( 'Vertical list', 'leadstart-translate' ); ?></option>
								<option value="inline" <?php selected( $settings['switcher_style'], 'inline' ); ?>><?php esc_html_e( 'Inline row', 'leadstart-translate' ); ?></option>
							</select>
							<label class="leadst-inline"><input type="checkbox" name="switcher_floating" value="1" <?php checked( $settings['switcher_floating'] ); ?> /> <?php esc_html_e( 'Always show a language switcher', 'leadstart-translate' ); ?></label>
							<p class="description"><?php esc_html_e( 'Ticked, the switcher appears on every page without you placing it. Leave it unticked to position it yourself with the shortcode [leadstart_translate] or the template tag leadst_language_switcher().', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Exclusions', 'leadstart-translate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="preserve_terms"><?php esc_html_e( 'Never translate these terms', 'leadstart-translate' ); ?></label></th>
						<td>
							<textarea id="preserve_terms" name="preserve_terms" rows="4" class="large-text code" placeholder="<?php esc_attr_e( "Your organization&#10;Your product names&#10;People's names", 'leadstart-translate' ); ?>"><?php echo esc_textarea( $settings['preserve_terms'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Organization names, product names, people. Honored by the Gemini engine.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="exclude_selectors"><?php esc_html_e( 'Skip these elements', 'leadstart-translate' ); ?></label></th>
						<td>
							<textarea id="exclude_selectors" name="exclude_selectors" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude_selectors'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Tag names (code) or single class names (.notranslate). Anything inside them is left in the original language.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="exclude_paths"><?php esc_html_e( 'Skip these paths', 'leadstart-translate' ); ?></label></th>
						<td>
							<textarea id="exclude_paths" name="exclude_paths" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude_paths'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Substring match against the request path, tested after the language prefix is removed, so write /cart/ not /es/cart/. An excluded page is still served in the right language with its links intact; only its text is left untranslated and never sent to a translation API.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="queue_batch_size"><?php esc_html_e( 'Worker batch size', 'leadstart-translate' ); ?></label></th>
						<td>
							<input type="number" min="5" max="200" id="queue_batch_size" name="queue_batch_size" value="<?php echo esc_attr( $settings['queue_batch_size'] ); ?>" />
							<label class="leadst-inline"><?php esc_html_e( 'Cache lifetime (seconds)', 'leadstart-translate' ); ?> <input type="number" min="300" step="300" name="cache_ttl" value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>" /></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="warm_batch_size"><?php esc_html_e( 'Pages per pre-fill run', 'leadstart-translate' ); ?></label></th>
						<td>
							<input type="number" min="1" max="25" id="warm_batch_size" name="warm_batch_size" value="<?php echo esc_attr( $settings['warm_batch_size'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The pre-fill crawler runs every five minutes and fetches this many pages each time. The queue holds one entry per page per language, so a site with many languages has a long queue: three pages per run is about 36 an hour. Raise this to finish sooner, at the cost of a longer-running cron tick and faster spending.', 'leadstart-translate' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'leadstart-translate' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Status and tools screen.
	 *
	 * @return void
	 */
	public function render_status() {
		$stats    = LEADST_DB::stats();
		$progress = LEADST_Queue::instance()->warm_progress();
		$active   = LEADST_Languages::active();
		$message  = get_transient( 'leadst_tool_message' );

		if ( $message ) {
			delete_transient( 'leadst_tool_message' );
		}

		$diag = get_transient( 'leadst_diag_report' );

		if ( $diag ) {
			delete_transient( 'leadst_diag_report' );
		}

		$compare_scan   = get_transient( LEADST_Compare::scan_key() );
		$compare_result = get_transient( LEADST_Compare::result_key() );

		if ( $compare_result ) {
			delete_transient( LEADST_Compare::result_key() );
		}

		$daily_cap   = (int) leadst()->get( 'daily_char_cap' );
		$monthly_cap = (int) leadst()->get( 'monthly_char_cap' );
		?>
		<div class="wrap leadst-wrap">
			<h1><?php esc_html_e( 'Status & Tools', 'leadstart-translate' ); ?></h1>

			<?php if ( $message ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Google Cloud Translation character usage', 'leadstart-translate' ); ?></h2>
			<table class="widefat striped leadst-table">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Characters today', 'leadstart-translate' ); ?></th>
						<td>
							<?php echo esc_html( number_format_i18n( LEADST_Budget::daily_used() ) ); ?>
							<?php if ( $daily_cap ) : ?>
								<span class="leadst-muted">/ <?php echo esc_html( number_format_i18n( $daily_cap ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Characters this month', 'leadstart-translate' ); ?></th>
						<td>
							<?php echo esc_html( number_format_i18n( LEADST_Budget::monthly_used() ) ); ?>
							<?php if ( $monthly_cap ) : ?>
								<span class="leadst-muted">/ <?php echo esc_html( number_format_i18n( $monthly_cap ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Characters all time', 'leadstart-translate' ); ?></th>
						<td>
							<?php echo esc_html( number_format_i18n( LEADST_Budget::total_used() ) ); ?>
							<span class="leadst-muted">
								<?php
								printf(
									/* translators: %s: dollar amount. */
									esc_html__( 'about $%s at the standard rate, less the free monthly allowance', 'leadstart-translate' ),
									esc_html( number_format( LEADST_Budget::estimate_cost( LEADST_Budget::total_used() ), 2 ) )
								);
								?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'DeepL characters today / this month', 'leadstart-translate' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( LEADST_Budget::deepl_daily_used() ) ); ?> / <?php echo esc_html( number_format_i18n( LEADST_Budget::deepl_monthly_used() ) ); ?>
							<span class="leadst-muted"><?php esc_html_e( 'Independent DeepL volume guard, not an invoice.', 'leadstart-translate' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'AI source characters today / this month', 'leadstart-translate' ); ?></th>
						<td>
							<?php echo esc_html( number_format_i18n( LEADST_Budget::ai_daily_used() ) ); ?> / <?php echo esc_html( number_format_i18n( LEADST_Budget::ai_monthly_used() ) ); ?>
							<span class="leadst-muted"><?php esc_html_e( 'Volume guard only — consult your AI provider for billed tokens and cost.', 'leadstart-translate' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Self-hosted fallback tier', 'leadstart-translate' ); ?></th>
						<td>
							<?php if ( ( new LEADST_Engine_Fallback() )->is_configured() ) : ?>
								<?php esc_html_e( 'Enabled — may run when the primary engine cannot be selected for background work. Service costs and policies are controlled by its operator.', 'leadstart-translate' ); ?>
							<?php else : ?>
								<span class="leadst-muted"><?php esc_html_e( 'Off', 'leadstart-translate' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'LLM tier characters this month', 'leadstart-translate' ); ?></th>
						<td>
							<?php $llm = LEADST_Engine_Ultimate::usage(); ?>
							<?php echo esc_html( number_format_i18n( $llm['month'] ) ); ?>
							<span class="leadst-muted">
								<?php
								printf(
									/* translators: %s: characters sent to the LLM tier all time. */
									esc_html__( 'all time: %s — billed by your LLM provider, never against the caps above', 'leadstart-translate' ),
									esc_html( number_format_i18n( $llm['total'] ) )
								);
								?>
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Pre-fill progress', 'leadstart-translate' ); ?></h2>
			<?php if ( $progress['total'] > 0 ) : ?>
				<div class="leadst-progress"><div class="leadst-progress__bar" style="width:<?php echo esc_attr( (string) $progress['percent'] ); ?>%"></div></div>
				<p>
					<?php
					printf(
						/* translators: 1: pages done, 2: total pages, 3: percentage. */
						esc_html__( '%1$s of %2$s page views crawled (%3$s%%).', 'leadstart-translate' ),
						esc_html( number_format_i18n( $progress['done'] ) ),
						esc_html( number_format_i18n( $progress['total'] ) ),
						esc_html( (string) $progress['percent'] )
					);
					?>
				</p>
				<?php if ( $progress['remaining'] > 0 ) : ?>
					<p class="leadst-muted">
						<?php
						printf(
							/* translators: 1: pages per tick, 2: human readable duration. */
							esc_html__( 'The crawler fetches %1$s pages every five minutes, so the rest would take about %2$s of steady traffic. A queue is one entry per page per language, which is why it is large. Raise "Pages per pre-fill run" in Settings, or run a real cron job, to finish sooner.', 'leadstart-translate' ),
							esc_html( number_format_i18n( $progress['per_tick'] ) ),
							esc_html( human_time_diff( time(), time() + $progress['seconds_left'] ) )
						);
						?>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<p class="leadst-muted"><?php esc_html_e( 'No pre-fill running.', 'leadstart-translate' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Languages', 'leadstart-translate' ); ?></h2>
			<table class="widefat striped leadst-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Language', 'leadstart-translate' ); ?></th>
						<th><?php esc_html_e( 'Translated', 'leadstart-translate' ); ?></th>
						<th><?php esc_html_e( 'Waiting', 'leadstart-translate' ); ?></th>
						<th><?php esc_html_e( 'Hand-edited', 'leadstart-translate' ); ?></th>
						<th><?php esc_html_e( 'Failed', 'leadstart-translate' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'leadstart-translate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $active ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No languages switched on yet.', 'leadstart-translate' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $active as $slug => $language ) : ?>
						<?php $row = isset( $stats[ $slug ] ) ? $stats[ $slug ] : array( 'translated' => 0, 'pending' => 0, 'human' => 0, 'failed' => 0 ); ?>
						<tr>
							<td><strong><?php echo esc_html( $language['name'] ); ?></strong> <span class="leadst-muted"><?php echo esc_html( $slug ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( $row['translated'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['pending'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['human'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['failed'] ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadst-editor&lang=' . $slug ) ); ?>"><?php esc_html_e( 'Edit', 'leadstart-translate' ); ?></a>
								&nbsp;|&nbsp;
								<a class="leadst-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=leadst_run_tool&tool=purge_language&lang=' . $slug ), 'leadst_run_tool' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete every stored translation for this language?', 'leadstart-translate' ) ); ?>');"><?php esc_html_e( 'Purge', 'leadstart-translate' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Tools', 'leadstart-translate' ); ?></h2>
			<div class="leadst-tools">
				<?php
				$tools = array(
					'test_key'        => __( 'Test selected engine', 'leadstart-translate' ),
					'test_fallback'   => __( 'Test the fallback engine', 'leadstart-translate' ),
					'test_ai_client'  => __( 'Test the WordPress AI Client', 'leadstart-translate' ),
					'test_ultimate'   => __( 'Test the LLM engine', 'leadstart-translate' ),
					'start_warm'      => __( 'Start pre-fill crawl', 'leadstart-translate' ),
					'run_now'         => __( 'Run the worker once now', 'leadstart-translate' ),
					'stop_warm'       => __( 'Stop pre-fill', 'leadstart-translate' ),
					'translate_slugs' => __( 'Translate all URL slugs', 'leadstart-translate' ),
					'health_check'    => __( 'Check language hostnames', 'leadstart-translate' ),
					'scan_conflicts'  => __( 'Re-check page name clashes', 'leadstart-translate' ),
					'flush_cache'     => __( 'Clear caches', 'leadstart-translate' ),
					'reset_usage'     => __( 'Reset usage counters', 'leadstart-translate' ),
				);

				foreach ( $tools as $tool => $label ) :
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="leadst_run_tool" />
						<input type="hidden" name="tool" value="<?php echo esc_attr( $tool ); ?>" />
						<?php wp_nonce_field( 'leadst_run_tool' ); ?>
						<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
					</form>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Which engine translated what', 'leadstart-translate' ); ?></h2>
			<?php $engine_counts = LEADST_DB::engine_counts(); ?>
			<?php if ( $engine_counts ) : ?>
				<table class="widefat striped leadst-table" style="max-width:640px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Engine', 'leadstart-translate' ); ?></th>
							<th><?php esc_html_e( 'Strings produced', 'leadstart-translate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $engine_counts as $engine_id => $count ) : ?>
							<tr>
								<td><?php echo esc_html( LEADST_Engine::label_for( $engine_id ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="leadst-muted"><?php esc_html_e( 'Counted from the stored translations themselves, not from which tiers are switched on. Rows translated before this plugin started recording the engine show as Unrecorded.', 'leadstart-translate' ); ?></p>
			<?php else : ?>
				<p class="leadst-muted"><?php esc_html_e( 'Nothing translated yet, so there is nothing to attribute.', 'leadstart-translate' ); ?></p>
			<?php endif; ?>

			<h2 id="leadst-compare"><?php esc_html_e( 'Compare engines', 'leadstart-translate' ); ?></h2>
			<?php $compare_engines = LEADST_Compare::engines(); ?>

			<?php if ( count( $compare_engines ) < 2 ) : ?>
				<p class="leadst-muted">
					<?php
					printf(
						/* translators: %d: number of configured engines. */
						esc_html__( 'Comparison needs two engines switched on; %d is configured. Add a second tier in Settings and this will compare them on a page of your choosing.', 'leadstart-translate' ),
						count( $compare_engines )
					);
					?>
				</p>
			<?php else : ?>
				<p class="leadst-muted">
					<?php
					$names = array();
					foreach ( array_keys( $compare_engines ) as $engine_id ) {
						$names[] = LEADST_Engine::label_for( $engine_id );
					}
					printf(
						/* translators: %s: comma separated engine names. */
						esc_html__( 'Compare the same sample using configured engines. Configured now: %s. Google estimates exclude DeepL character billing and AI-model token billing; confirm provider charges before proceeding.', 'leadstart-translate' ),
						esc_html( implode( ', ', $names ) )
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leadst-diagnose-form">
					<input type="hidden" name="action" value="leadst_compare" />
					<input type="hidden" name="step" value="scan" />
					<?php wp_nonce_field( 'leadst_compare' ); ?>
					<input type="url" name="compare_url" class="regular-text" required
						placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
						value="<?php echo esc_attr( $compare_scan ? $compare_scan['url'] : home_url( '/' ) ); ?>" />
					<select name="compare_lang">
						<?php foreach ( LEADST_Languages::active() as $slug => $language ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $compare_scan ? $compare_scan['target'] : '', $slug ); ?>>
								<?php echo esc_html( $language['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Scan this page', 'leadstart-translate' ); ?></button>
				</form>

				<?php if ( $compare_scan && ! $compare_result ) : ?>
					<?php $scan_lang = LEADST_Languages::get( $compare_scan['target'] ); ?>
					<div class="notice notice-info inline leadst-compare-estimate">
						<p>
							<?php
							printf(
								/* translators: 1: string count, 2: character count, 3: engine count, 4: language name. */
								esc_html__( '%1$s strings, %2$s characters, through %3$s engines into %4$s.', 'leadstart-translate' ),
								esc_html( number_format_i18n( count( $compare_scan['strings'] ) ) ),
								esc_html( number_format_i18n( $compare_scan['chars'] ) ),
								esc_html( number_format_i18n( count( $compare_scan['engines'] ) ) ),
								esc_html( $scan_lang ? $scan_lang['name'] : $compare_scan['target'] )
							);
							?>
							<?php if ( $compare_scan['billable'] > 0 ) : ?>
								<strong>
									<?php
									printf(
										/* translators: %s: estimated cost. */
										esc_html__( 'Estimated Google Cloud Translation charge: $%s (excluding any applicable free allowance).', 'leadstart-translate' ),
										esc_html( number_format_i18n( $compare_scan['cost'], 4 ) )
									);
									?>
								</strong>
								<?php esc_html_e( 'Every configured engine processes the sample. DeepL and AI providers may incur additional charges not included in this Google estimate.', 'leadstart-translate' ); ?>
							<?php else : ?>
								<strong><?php esc_html_e( 'No Google Cloud Translation charge is estimated. DeepL and AI engines may still incur provider charges.', 'leadstart-translate' ); ?></strong>
							<?php endif; ?>
						</p>
						<?php if ( $compare_scan['capped'] ) : ?>
							<p>
								<?php
								printf(
									/* translators: %d: maximum number of strings compared in a single request. */
									esc_html__( 'This page reached the comparison limit of %d strings. Only the sampled strings will be compared to prevent unexpected translation charges.', 'leadstart-translate' ),
									(int) LEADST_Compare::MAX_STRINGS
								);
								?>
							</p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="leadst_compare" />
							<input type="hidden" name="step" value="run" />
							<?php wp_nonce_field( 'leadst_compare' ); ?>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Translate and compare', 'leadstart-translate' ); ?></button>
						</form>
					</div>
				<?php endif; ?>

				<?php if ( $compare_result ) : ?>
					<?php $result_lang = LEADST_Languages::get( $compare_result['target'] ); ?>
					<p>
						<?php
						printf(
							/* translators: 1: language name, 2: page URL, 3: rows where engines agreed, 4: total rows. */
							esc_html__( 'Into %1$s, from %2$s. The engines agreed on %3$s of %4$s strings; the rest are marked.', 'leadstart-translate' ),
							esc_html( $result_lang ? $result_lang['name'] : $compare_result['target'] ),
							esc_html( $compare_result['url'] ),
							esc_html( number_format_i18n( $compare_result['agreed'] ) ),
							esc_html( number_format_i18n( count( $compare_result['rows'] ) ) )
						);
						?>
					</p>

					<?php if ( ! empty( $compare_result['errors'] ) ) : ?>
						<div class="notice notice-warning inline">
							<?php foreach ( $compare_result['errors'] as $failed_id => $error ) : ?>
								<p>
									<strong><?php echo esc_html( LEADST_Engine::label_for( $failed_id ) ); ?>:</strong>
									<?php echo esc_html( $error ); ?>
								</p>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<table class="widefat striped leadst-compare">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Source', 'leadstart-translate' ); ?></th>
								<?php foreach ( $compare_result['engines'] as $engine_id ) : ?>
									<th><?php echo esc_html( LEADST_Engine::label_for( $engine_id ) ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $compare_result['rows'] as $row ) : ?>
								<tr class="<?php echo $row['agree'] ? 'leadst-compare--same' : 'leadst-compare--differs'; ?>">
									<td><?php echo esc_html( $row['source'] ); ?></td>
									<?php foreach ( $compare_result['engines'] as $engine_id ) : ?>
										<td><?php echo esc_html( isset( $row['cells'][ $engine_id ] ) ? $row['cells'][ $engine_id ] : '' ); ?></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="leadst-muted"><?php esc_html_e( 'Rows the engines agreed on are dimmed. Nothing here has been saved: comparing does not change a single stored translation.', 'leadstart-translate' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<h2 id="leadst-diagnose"><?php esc_html_e( 'Why is this URL not working?', 'leadstart-translate' ); ?></h2>
			<p class="leadst-muted"><?php esc_html_e( 'Paste a translated URL that returns 404 and this will show every decision the plugin makes about it, and where it stops.', 'leadstart-translate' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leadst-diagnose-form">
				<input type="hidden" name="action" value="leadst_diagnose" />
				<?php wp_nonce_field( 'leadst_diagnose' ); ?>
				<input type="text" name="path" class="regular-text" placeholder="/fr/a-propos/" value="<?php echo $diag ? esc_attr( $diag['path'] ) : ''; ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Trace this URL', 'leadstart-translate' ); ?></button>
			</form>

			<?php if ( $diag && ! empty( $diag['rows'] ) ) : ?>
				<table class="widefat striped leadst-diagnose">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Step', 'leadstart-translate' ); ?></th>
							<th><?php esc_html_e( 'Result', 'leadstart-translate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $diag['rows'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['step'] ); ?></td>
								<td<?php echo false === $row['ok'] ? ' class="leadst-danger"' : ''; ?>><?php echo esc_html( $row['result'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Scheduled work', 'leadstart-translate' ); ?></h2>
			<p class="leadst-muted">
				<?php
				$next = wp_next_scheduled( LEADST_Queue::TICK_HOOK );
				if ( $next ) {
					printf(
						/* translators: %s: human readable time difference. */
						esc_html__( 'Next worker run in %s. WordPress cron only fires when the site receives traffic; on a quiet site, use the run-now button or a real cron job.', 'leadstart-translate' ),
						esc_html( human_time_diff( time(), $next ) )
					);
				} else {
					esc_html_e( 'The worker is not scheduled. Deactivate and reactivate the plugin to fix this.', 'leadstart-translate' );
				}
				?>
			</p>
		</div>
		<?php
	}
}
