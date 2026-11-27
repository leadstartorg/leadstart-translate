<?php
/**
 * Native presentation.
 *
 * Translating the words is only half of making a language version feel like its
 * own site. The other half is typography. A Korean or Amharic page rendered in
 * a theme font that has no Korean or Amharic glyphs falls back to whatever the
 * visitor's operating system happens to supply, which is why machine-translated
 * sites so often look borrowed.
 *
 * This class loads a typeface that actually covers the script being displayed,
 * flips the layout for right-to-left languages, and adds body classes so the
 * theme can be styled per language.
 *
 * Latin-script languages are deliberately left alone — the theme's own fonts
 * already cover them, and overriding would damage the design for no gain.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Script-aware fonts, RTL layout and per-language body classes.
 */
class LEADST_Presentation {

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Presentation|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Presentation
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Language slug to writing system.
	 *
	 * Anything absent from this map is Latin script.
	 *
	 * Dzongkha is deliberately absent: its Noto family name could not be
	 * confirmed against fonts.googleapis.com, and a wrong family name
	 * fetches nothing. It falls through to the theme's own font, which is
	 * what every unmapped language does.
	 *
	 * @return array
	 */
	public static function script_map() {
		return array(
			'ar'       => 'arabic',
			'ckb'      => 'arabic',
			'fa'       => 'arabic',
			'ms-arab'  => 'arabic',
			'pa-arab'  => 'arabic',
			'ps'       => 'arabic',
			'sd'       => 'arabic',
			'ug'       => 'arabic',
			'ur'       => 'arabic',

			'he'       => 'hebrew',
			'yi'       => 'hebrew',

			'dv'       => 'thaana',

			'ab'       => 'cyrillic',
			'ba'       => 'cyrillic',
			'be'       => 'cyrillic',
			'bg'       => 'cyrillic',
			'bua'      => 'cyrillic',
			'chm'      => 'cyrillic',
			'crh'      => 'cyrillic',
			'cv'       => 'cyrillic',
			'kk'       => 'cyrillic',
			'ky'       => 'cyrillic',
			'mk'       => 'cyrillic',
			'mn'       => 'cyrillic',
			'ru'       => 'cyrillic',
			'sr'       => 'cyrillic',
			'tg'       => 'cyrillic',
			'tt'       => 'cyrillic',
			'uk'       => 'cyrillic',

			'el'       => 'greek',

			'hy'       => 'armenian',

			'ka'       => 'georgian',

			'awa'      => 'devanagari',
			'bho'      => 'devanagari',
			'doi'      => 'devanagari',
			'gom'      => 'devanagari',
			'hi'       => 'devanagari',
			'mai'      => 'devanagari',
			'mr'       => 'devanagari',
			'ne'       => 'devanagari',
			'new'      => 'devanagari',
			'sa'       => 'devanagari',

			'as'       => 'bengali',
			'bn'       => 'bengali',

			'gu'       => 'gujarati',

			'pa'       => 'gurmukhi',

			'or'       => 'oriya',

			'ta'       => 'tamil',

			'te'       => 'telugu',

			'kn'       => 'kannada',

			'ml'       => 'malayalam',

			'si'       => 'sinhala',

			'mni-mtei' => 'meeteimayek',

			'th'       => 'thai',

			'lo'       => 'lao',

			'km'       => 'khmer',

			'my'       => 'myanmar',
			'shn'      => 'myanmar',

			'am'       => 'ethiopic',
			'ti'       => 'ethiopic',

			'zh-cn'    => 'sc',

			'yue'      => 'tc',
			'zh-tw'    => 'tc',

			'ja'       => 'jp',

			'ko'       => 'kr',
		);
	}

	/**
	 * Writing system to a Google Fonts family that covers it.
	 *
	 * @return array Script key => array( family name, Google Fonts family slug ).
	 */
	public static function font_map() {
		return array(
			'arabic'     => array( 'Noto Sans Arabic', 'Noto+Sans+Arabic' ),
			'hebrew'     => array( 'Noto Sans Hebrew', 'Noto+Sans+Hebrew' ),
			'cyrillic'   => array( 'Noto Sans', 'Noto+Sans' ),
			'greek'      => array( 'Noto Sans', 'Noto+Sans' ),
			'armenian'   => array( 'Noto Sans Armenian', 'Noto+Sans+Armenian' ),
			'georgian'   => array( 'Noto Sans Georgian', 'Noto+Sans+Georgian' ),
			'devanagari' => array( 'Noto Sans Devanagari', 'Noto+Sans+Devanagari' ),
			'bengali'    => array( 'Noto Sans Bengali', 'Noto+Sans+Bengali' ),
			'gujarati'   => array( 'Noto Sans Gujarati', 'Noto+Sans+Gujarati' ),
			'gurmukhi'   => array( 'Noto Sans Gurmukhi', 'Noto+Sans+Gurmukhi' ),
			'tamil'      => array( 'Noto Sans Tamil', 'Noto+Sans+Tamil' ),
			'telugu'     => array( 'Noto Sans Telugu', 'Noto+Sans+Telugu' ),
			'kannada'    => array( 'Noto Sans Kannada', 'Noto+Sans+Kannada' ),
			'malayalam'  => array( 'Noto Sans Malayalam', 'Noto+Sans+Malayalam' ),
			'sinhala'    => array( 'Noto Sans Sinhala', 'Noto+Sans+Sinhala' ),
			'thai'       => array( 'Noto Sans Thai', 'Noto+Sans+Thai' ),
			'lao'        => array( 'Noto Sans Lao', 'Noto+Sans+Lao' ),
			'khmer'      => array( 'Noto Sans Khmer', 'Noto+Sans+Khmer' ),
			'myanmar'    => array( 'Noto Sans Myanmar', 'Noto+Sans+Myanmar' ),
			'ethiopic'   => array( 'Noto Sans Ethiopic', 'Noto+Sans+Ethiopic' ),
			'thaana'     => array( 'Noto Sans Thaana', 'Noto+Sans+Thaana' ),
			'oriya'      => array( 'Noto Sans Oriya', 'Noto+Sans+Oriya' ),
			'meeteimayek' => array( 'Noto Sans Meetei Mayek', 'Noto+Sans+Meetei+Mayek' ),
			'sc'         => array( 'Noto Sans SC', 'Noto+Sans+SC' ),
			'tc'         => array( 'Noto Sans TC', 'Noto+Sans+TC' ),
			'jp'         => array( 'Noto Sans JP', 'Noto+Sans+JP' ),
			'kr'         => array( 'Noto Sans KR', 'Noto+Sans+KR' ),
		);
	}

	/**
	 * The writing system for a language.
	 *
	 * @param string $lang Language slug.
	 * @return string Script key, or 'latin'.
	 */
	public static function script_for( $lang ) {
		$map  = self::script_map();
		$lang = strtolower( (string) $lang );

		return isset( $map[ $lang ] ) ? $map[ $lang ] : 'latin';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_filter( 'body_class', array( $this, 'add_body_classes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'output_styles' ), 100 );
	}

	/**
	 * Add per-language classes so the theme can be styled per language.
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public function add_body_classes( $classes ) {
		$lang     = LEADST_Router::instance()->current();
		$language = LEADST_Languages::get( $lang );

		if ( ! $language ) {
			return $classes;
		}

		$classes[] = 'leadst-lang-' . sanitize_html_class( $lang );
		$classes[] = 'leadst-script-' . sanitize_html_class( self::script_for( $lang ) );

		if ( $language['rtl'] ) {
			$classes[] = 'leadst-rtl';
		}

		if ( LEADST_Router::instance()->is_translated() ) {
			$classes[] = 'leadst-translated';
		}

		return $classes;
	}

	/**
	 * Print the font link and the language stylesheet.
	 *
	 * Runs late on wp_enqueue_scripts so it lands after the theme's own
	 * stylesheets.
	 *
	 * @return void
	 */
	public function output_styles() {
		if ( is_admin() ) {
			return;
		}

		$lang     = LEADST_Router::instance()->current();
		$language = LEADST_Languages::get( $lang );

		if ( ! $language ) {
			return;
		}

		$script = self::script_for( $lang );
		$rtl    = (bool) $language['rtl'];
		$css    = '';

		if ( leadst()->get( 'native_fonts' ) && 'latin' !== $script ) {
			$fonts = self::font_map();

			if ( isset( $fonts[ $script ] ) ) {
				list( $family, $slug ) = $fonts[ $script ];

				$href = sprintf(
					'https://fonts.googleapis.com/css2?family=%s:wght@400;600;700&display=swap',
					$slug
				);

				wp_enqueue_style( 'leadst-font-' . $script, $href, array(), LEADST_VERSION );

				$stack = sprintf(
					"'%s', system-ui, -apple-system, 'Segoe UI', sans-serif",
					str_replace( "'", '', $family )
				);

				// The theme font has no glyphs for this script, so this
				// replaces the browser's arbitrary fallback, not the design.
				$selectors = array(
					'body.leadst-script-' . $script,
					'body.leadst-script-' . $script . ' h1',
					'body.leadst-script-' . $script . ' h2',
					'body.leadst-script-' . $script . ' h3',
					'body.leadst-script-' . $script . ' h4',
					'body.leadst-script-' . $script . ' h5',
					'body.leadst-script-' . $script . ' h6',
					'body.leadst-script-' . $script . ' p',
					'body.leadst-script-' . $script . ' a',
					'body.leadst-script-' . $script . ' li',
					'body.leadst-script-' . $script . ' span',
					'body.leadst-script-' . $script . ' td',
					'body.leadst-script-' . $script . ' th',
					'body.leadst-script-' . $script . ' label',
					'body.leadst-script-' . $script . ' button',
					'body.leadst-script-' . $script . ' input',
					'body.leadst-script-' . $script . ' select',
					'body.leadst-script-' . $script . ' textarea',
					'body.leadst-script-' . $script . ' blockquote',
					'body.leadst-script-' . $script . ' figcaption',
				);

				$css .= implode( ',', $selectors ) . '{font-family:' . $stack . ';}';

				// Scripts with tall ascenders and descenders need more room than
				// a Latin-tuned theme allows, or lines collide.
				if ( in_array( $script, array( 'devanagari', 'bengali', 'thai', 'lao', 'khmer', 'myanmar', 'sinhala', 'tamil', 'telugu', 'kannada', 'malayalam', 'gujarati', 'gurmukhi', 'ethiopic', 'arabic' ), true ) ) {
					$css .= 'body.leadst-script-' . $script . '{line-height:1.85;}';
				}
			}
		}

		if ( $rtl && leadst()->get( 'rtl_support' ) ) {
			$css .= 'body.leadst-rtl{direction:rtl;text-align:right;}'
				. 'body.leadst-rtl ul,body.leadst-rtl ol{padding-right:1.5em;padding-left:0;}'
				. 'body.leadst-rtl th,body.leadst-rtl td{text-align:right;}'
				. 'body.leadst-rtl input,body.leadst-rtl textarea,body.leadst-rtl select{text-align:right;}'
				. 'body.leadst-rtl .leadst-switcher{text-align:right;}';
		}

		/**
		 * Filter the per-language CSS before it is printed.
		 *
		 * @param string $css    Generated CSS.
		 * @param string $lang   Language slug.
		 * @param string $script Writing system key.
		 */
		$css = (string) apply_filters( 'leadst_language_css', $css, $lang, $script );

		if ( '' !== trim( $css ) ) {
			wp_enqueue_style( 'leadst-presentation', LEADST_URL . 'assets/presentation.css', array(), LEADST_VERSION );
			wp_add_inline_style( 'leadst-presentation', wp_strip_all_tags( $css ) );
		}
	}
}
