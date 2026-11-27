<?php
/**
 * Language switcher.
 *
 * Renders as a shortcode, a widget, a block-editor-friendly shortcode block, or
 * a floating corner control. Every link carries data-lst-lang so the page
 * processor leaves it alone — switcher links are already absolute and correct.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Switcher rendering.
 */
class LEADST_Switcher {

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Switcher|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Switcher
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the shortcode and assets.
	 *
	 * @return void
	 */
	public function boot() {
		add_shortcode( 'leadstart_translate', array( $this, 'shortcode' ) );

		add_shortcode( 'leadst_switcher', array( $this, 'shortcode' ) );
		// Compatibility for shortcodes saved while the WPTR branch was installed.
		add_shortcode( 'wptr_switcher', array( $this, 'shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		if ( leadst()->get( 'switcher_floating' ) ) {
			add_action( 'wp_footer', array( $this, 'render_floating' ), 100 );
		}
	}

	/**
	 * Enqueue the switcher stylesheet.
	 *
	 * @return void
	 */
	public function enqueue() {
		wp_register_style( 'leadst-switcher', LEADST_URL . 'assets/switcher.css', array(), LEADST_VERSION );
		wp_enqueue_style( 'leadst-switcher' );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'style'  => leadst()->get( 'switcher_style', 'dropdown' ),
				'native' => 'yes',
				'label'  => '',
			),
			(array) $atts,
			'leadstart_translate'
		);

		return $this->render( $atts['style'], 'yes' === $atts['native'], $atts['label'] );
	}

	/**
	 * Build the switcher markup.
	 *
	 * @param string $style  dropdown, list or inline.
	 * @param bool   $native Use native language names.
	 * @param string $label  Optional visible label.
	 * @return string
	 */
	public function render( $style = 'dropdown', $native = true, $label = '' ) {
		$languages = LEADST_Languages::active_with_source();

		if ( count( $languages ) < 2 ) {
			return '';
		}

		$router  = LEADST_Router::instance();
		$current = $router->current();
		$id      = 'leadst-switcher-' . wp_rand( 1000, 9999 );

		$items = array();
		foreach ( $languages as $slug => $language ) {
			$items[ $slug ] = array(
				'url'    => $router->translate_url( $slug ),
				'label'  => $native ? $language['native'] : $language['name'],
				'name'   => $language['name'],
				'active' => ( $slug === $current ),
			);
		}

		ob_start();

		if ( 'dropdown' === $style ) {
			?>
			<div class="leadst-switcher leadst-switcher--dropdown">
				<?php if ( $label ) : ?>
					<label class="leadst-switcher__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<?php endif; ?>
				<select
					id="<?php echo esc_attr( $id ); ?>"
					class="leadst-switcher__select"
					aria-label="<?php esc_attr_e( 'Choose a language', 'leadstart-translate' ); ?>"
					onchange="if(this.value){window.location.href=this.value;}"
				>
					<?php foreach ( $items as $slug => $item ) : ?>
						<option
							value="<?php echo esc_url( $item['url'] ); ?>"
							<?php selected( $item['active'] ); ?>
						><?php echo esc_html( $item['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php
		} else {
			$class = 'inline' === $style ? 'leadst-switcher--inline' : 'leadst-switcher--list';
			?>
			<nav class="leadst-switcher <?php echo esc_attr( $class ); ?>" aria-label="<?php esc_attr_e( 'Language', 'leadstart-translate' ); ?>">
				<?php if ( $label ) : ?>
					<span class="leadst-switcher__label"><?php echo esc_html( $label ); ?></span>
				<?php endif; ?>
				<ul class="leadst-switcher__list">
					<?php foreach ( $items as $slug => $item ) : ?>
						<li class="leadst-switcher__item<?php echo $item['active'] ? ' is-active' : ''; ?>">
							<a
								href="<?php echo esc_url( $item['url'] ); ?>"
								data-lst-lang="<?php echo esc_attr( $slug ); ?>"
								hreflang="<?php echo esc_attr( $languages[ $slug ]['hreflang'] ); ?>"
								lang="<?php echo esc_attr( $languages[ $slug ]['hreflang'] ); ?>"
								<?php echo $item['active'] ? ' aria-current="true"' : ''; ?>
							><?php echo esc_html( $item['label'] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<?php
		}

		return trim( (string) ob_get_clean() );
	}

	/**
	 * Floating corner switcher.
	 *
	 * @return void
	 */
	public function render_floating() {
		$markup = $this->render( 'dropdown', true, '' );

		if ( '' === $markup ) {
			return;
		}

		echo '<div class="leadst-floating" role="complementary">' . $markup . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Template tag for themes.
 *
 * @param string $style  dropdown, list or inline.
 * @param bool   $native Use native names.
 * @return void
 */
function leadst_language_switcher( $style = 'dropdown', $native = true ) {
	echo LEADST_Switcher::instance()->render( $style, $native ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
