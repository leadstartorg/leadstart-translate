<?php
/**
 * Language registry.
 *
 * Maps a URL slug to the code Google's API expects, the code that belongs in an
 * hreflang attribute (these differ for Hebrew, Javanese and Chinese), the name
 * in English, the name in its own alphabet, and text direction.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static list of every language Cloud Translation supports for this plugin.
 */
class LEADST_Languages {

	/**
	 * Cached language map.
	 *
	 * @var array|null
	 */
	private static $map = null;

	/**
	 * Full language table.
	 *
	 * Format: slug => array( google, hreflang, name, native, rtl ).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$map ) {
			return self::$map;
		}

		$raw = array(
			'ab'      => array( 'ab', 'ab', 'Abkhaz', 'Аԥсшәа', 0 ),
			'ace'     => array( 'ace', 'ace', 'Acehnese', 'Acehnese', 0 ),
			'ach'     => array( 'ach', 'ach', 'Acholi', 'Acholi', 0 ),
			'af'      => array( 'af', 'af', 'Afrikaans', 'Afrikaans', 0 ),
			'sq'      => array( 'sq', 'sq', 'Albanian', 'Shqip', 0 ),
			'alz'     => array( 'alz', 'alz', 'Alur', 'Alur', 0 ),
			'am'      => array( 'am', 'am', 'Amharic', 'አማርኛ', 0 ),
			'ar'      => array( 'ar', 'ar', 'Arabic', 'العربية', 1 ),
			'hy'      => array( 'hy', 'hy', 'Armenian', 'Հայերեն', 0 ),
			'as'      => array( 'as', 'as', 'Assamese', 'অসমীয়া', 0 ),
			'awa'     => array( 'awa', 'awa', 'Awadhi', 'Awadhi', 0 ),
			'ay'      => array( 'ay', 'ay', 'Aymara', 'Aymara', 0 ),
			'az'      => array( 'az', 'az', 'Azerbaijani', 'Azərbaycan', 0 ),
			'ban'     => array( 'ban', 'ban', 'Balinese', 'Balinese', 0 ),
			'bm'      => array( 'bm', 'bm', 'Bambara', 'bamanakan', 0 ),
			'ba'      => array( 'ba', 'ba', 'Bashkir', 'башҡорт теле', 0 ),
			'eu'      => array( 'eu', 'eu', 'Basque', 'Euskara', 0 ),
			'btx'     => array( 'btx', 'btx', 'Batak Karo', 'Batak Karo', 0 ),
			'bts'     => array( 'bts', 'bts', 'Batak Simalungun', 'Batak Simalungun', 0 ),
			'bbc'     => array( 'bbc', 'bbc', 'Batak Toba', 'Batak Toba', 0 ),
			'be'      => array( 'be', 'be', 'Belarusian', 'Беларуская', 0 ),
			'bem'     => array( 'bem', 'bem', 'Bemba', 'Ichibemba', 0 ),
			'bn'      => array( 'bn', 'bn', 'Bengali', 'বাংলা', 0 ),
			'bew'     => array( 'bew', 'bew', 'Betawi', 'Betawi', 0 ),
			'bho'     => array( 'bho', 'bho', 'Bhojpuri', 'भोजपुरी', 0 ),
			'bik'     => array( 'bik', 'bik', 'Bikol', 'Bikol', 0 ),
			'bs'      => array( 'bs', 'bs', 'Bosnian', 'Bosanski', 0 ),
			'br'      => array( 'br', 'br', 'Breton', 'brezhoneg', 0 ),
			'bg'      => array( 'bg', 'bg', 'Bulgarian', 'Български', 0 ),
			'bua'     => array( 'bua', 'bua', 'Buryat', 'Buryat', 0 ),
			'yue'     => array( 'yue', 'yue', 'Cantonese', '粵語', 0 ),
			'ca'      => array( 'ca', 'ca', 'Catalan', 'Català', 0 ),
			'ceb'     => array( 'ceb', 'ceb', 'Cebuano', 'Cebuano', 0 ),
			'ny'      => array( 'ny', 'ny', 'Chichewa', 'Chichewa', 0 ),
			'zh-cn'   => array( 'zh-CN', 'zh-Hans', 'Chinese (Simplified)', '简体中文', 0 ),
			'zh-tw'   => array( 'zh-TW', 'zh-Hant', 'Chinese (Traditional)', '繁體中文', 0 ),
			'cv'      => array( 'cv', 'cv', 'Chuvash', 'чӑваш', 0 ),
			'co'      => array( 'co', 'co', 'Corsican', 'Corsu', 0 ),
			'crh'     => array( 'crh', 'crh', 'Crimean Tatar', 'Crimean Tatar', 0 ),
			'hr'      => array( 'hr', 'hr', 'Croatian', 'Hrvatski', 0 ),
			'cs'      => array( 'cs', 'cs', 'Czech', 'Čeština', 0 ),
			'da'      => array( 'da', 'da', 'Danish', 'Dansk', 0 ),
			'din'     => array( 'din', 'din', 'Dinka', 'Dinka', 0 ),
			'dv'      => array( 'dv', 'dv', 'Divehi', 'Divehi', 1 ),
			'doi'     => array( 'doi', 'doi', 'Dogri', 'डोगरी', 0 ),
			'dov'     => array( 'dov', 'dov', 'Dombe', 'Dombe', 0 ),
			'nl'      => array( 'nl', 'nl', 'Dutch', 'Nederlands', 0 ),
			'dz'      => array( 'dz', 'dz', 'Dzongkha', 'རྫོང་ཁ', 0 ),
			'en'      => array( 'en', 'en', 'English', 'English', 0 ),
			'eo'      => array( 'eo', 'eo', 'Esperanto', 'Esperanto', 0 ),
			'et'      => array( 'et', 'et', 'Estonian', 'Eesti', 0 ),
			'ee'      => array( 'ee', 'ee', 'Ewe', 'eʋegbe', 0 ),
			'fj'      => array( 'fj', 'fj', 'Fijian', 'Fijian', 0 ),
			'tl'      => array( 'tl', 'tl', 'Filipino', 'Filipino', 0 ),
			'fi'      => array( 'fi', 'fi', 'Finnish', 'Suomi', 0 ),
			'fr'      => array( 'fr', 'fr', 'French', 'Français', 0 ),
			'fr-fr'   => array( 'fr-FR', 'fr-FR', 'French (French)', 'français (France)', 0 ),
			'fr-ca'   => array( 'fr-CA', 'fr-CA', 'French (Canadian)', 'français (Canada)', 0 ),
			'fy'      => array( 'fy', 'fy', 'Frisian', 'Frysk', 0 ),
			'ff'      => array( 'ff', 'ff', 'Fulfulde', 'Pulaar', 0 ),
			'gaa'     => array( 'gaa', 'gaa', 'Ga', 'Gã', 0 ),
			'gl'      => array( 'gl', 'gl', 'Galician', 'Galego', 0 ),
			'lg'      => array( 'lg', 'lg', 'Ganda (Luganda)', 'Luganda', 0 ),
			'ka'      => array( 'ka', 'ka', 'Georgian', 'ქართული', 0 ),
			'de'      => array( 'de', 'de', 'German', 'Deutsch', 0 ),
			'el'      => array( 'el', 'el', 'Greek', 'Ελληνικά', 0 ),
			'gn'      => array( 'gn', 'gn', 'Guarani', 'avañe’ẽ', 0 ),
			'gu'      => array( 'gu', 'gu', 'Gujarati', 'ગુજરાતી', 0 ),
			'ht'      => array( 'ht', 'ht', 'Haitian Creole', 'Kreyòl Ayisyen', 0 ),
			'cnh'     => array( 'cnh', 'cnh', 'Hakha Chin', 'Hakha Chin', 0 ),
			'ha'      => array( 'ha', 'ha', 'Hausa', 'Hausa', 0 ),
			'haw'     => array( 'haw', 'haw', 'Hawaiian', 'ʻŌlelo Hawaiʻi', 0 ),
			'he'      => array( 'iw', 'he', 'Hebrew', 'עברית', 1 ),
			'hil'     => array( 'hil', 'hil', 'Hiligaynon', 'Ilonggo', 0 ),
			'hi'      => array( 'hi', 'hi', 'Hindi', 'हिन्दी', 0 ),
			'hmn'     => array( 'hmn', 'hmn', 'Hmong', 'Hmoob', 0 ),
			'hu'      => array( 'hu', 'hu', 'Hungarian', 'Magyar', 0 ),
			'hrx'     => array( 'hrx', 'hrx', 'Hunsrik', 'Hunsrik', 0 ),
			'is'      => array( 'is', 'is', 'Icelandic', 'Íslenska', 0 ),
			'ig'      => array( 'ig', 'ig', 'Igbo', 'Igbo', 0 ),
			'ilo'     => array( 'ilo', 'ilo', 'Iloko', 'Ilokano', 0 ),
			'id'      => array( 'id', 'id', 'Indonesian', 'Bahasa Indonesia', 0 ),
			'ga'      => array( 'ga', 'ga', 'Irish', 'Gaeilge', 0 ),
			'it'      => array( 'it', 'it', 'Italian', 'Italiano', 0 ),
			'ja'      => array( 'ja', 'ja', 'Japanese', '日本語', 0 ),
			'jv'      => array( 'jw', 'jv', 'Javanese', 'Basa Jawa', 0 ),
			'kn'      => array( 'kn', 'kn', 'Kannada', 'ಕನ್ನಡ', 0 ),
			'pam'     => array( 'pam', 'pam', 'Kapampangan', 'Kapampangan', 0 ),
			'kk'      => array( 'kk', 'kk', 'Kazakh', 'Қазақ', 0 ),
			'km'      => array( 'km', 'km', 'Khmer', 'ភាសាខ្មែរ', 0 ),
			'cgg'     => array( 'cgg', 'cgg', 'Kiga', 'Rukiga', 0 ),
			'rw'      => array( 'rw', 'rw', 'Kinyarwanda', 'Ikinyarwanda', 0 ),
			'ktu'     => array( 'ktu', 'ktu', 'Kituba', 'Kituba', 0 ),
			'gom'     => array( 'gom', 'gom', 'Konkani', 'Konkani', 0 ),
			'ko'      => array( 'ko', 'ko', 'Korean', '한국어', 0 ),
			'kri'     => array( 'kri', 'kri', 'Krio', 'Krio', 0 ),
			'ku'      => array( 'ku', 'ku', 'Kurdish (Kurmanji)', 'Kurdî', 0 ),
			'ckb'     => array( 'ckb', 'ckb', 'Kurdish (Sorani)', 'کوردیی ناوەندی', 1 ),
			'ky'      => array( 'ky', 'ky', 'Kyrgyz', 'Кыргызча', 0 ),
			'lo'      => array( 'lo', 'lo', 'Lao', 'ລາວ', 0 ),
			'ltg'     => array( 'ltg', 'ltg', 'Latgalian', 'Latgalian', 0 ),
			'la'      => array( 'la', 'la', 'Latin', 'Latina', 0 ),
			'lv'      => array( 'lv', 'lv', 'Latvian', 'Latviešu', 0 ),
			'lij'     => array( 'lij', 'lij', 'Ligurian', 'ligure', 0 ),
			'li'      => array( 'li', 'li', 'Limburgan', 'Limburgan', 0 ),
			'ln'      => array( 'ln', 'ln', 'Lingala', 'lingála', 0 ),
			'lt'      => array( 'lt', 'lt', 'Lithuanian', 'Lietuvių', 0 ),
			'lmo'     => array( 'lmo', 'lmo', 'Lombard', 'Lombard', 0 ),
			'luo'     => array( 'luo', 'luo', 'Luo', 'Dholuo', 0 ),
			'lb'      => array( 'lb', 'lb', 'Luxembourgish', 'Lëtzebuergesch', 0 ),
			'mk'      => array( 'mk', 'mk', 'Macedonian', 'Македонски', 0 ),
			'mai'     => array( 'mai', 'mai', 'Maithili', 'मैथिली', 0 ),
			'mak'     => array( 'mak', 'mak', 'Makassar', 'Makassar', 0 ),
			'mg'      => array( 'mg', 'mg', 'Malagasy', 'Malagasy', 0 ),
			'ms'      => array( 'ms', 'ms', 'Malay', 'Bahasa Melayu', 0 ),
			'ms-arab' => array( 'ms-Arab', 'ms-Arab', 'Malay (Jawi)', 'Malay (Jawi)', 1 ),
			'ml'      => array( 'ml', 'ml', 'Malayalam', 'മലയാളം', 0 ),
			'mt'      => array( 'mt', 'mt', 'Maltese', 'Malti', 0 ),
			'mi'      => array( 'mi', 'mi', 'Maori', 'Te Reo Māori', 0 ),
			'mr'      => array( 'mr', 'mr', 'Marathi', 'मराठी', 0 ),
			'chm'     => array( 'chm', 'chm', 'Meadow Mari', 'Meadow Mari', 0 ),
			'mni-mtei'=> array( 'mni-Mtei', 'mni-Mtei', 'Meiteilon (Manipuri)', 'ꯃꯤꯇꯩꯂꯣꯟ (ꯃꯤꯇꯩ ꯃꯌꯦꯛ)', 0 ),
			'min'     => array( 'min', 'min', 'Minang', 'Minang', 0 ),
			'lus'     => array( 'lus', 'lus', 'Mizo', 'Mizo', 0 ),
			'mn'      => array( 'mn', 'mn', 'Mongolian', 'Монгол', 0 ),
			'my'      => array( 'my', 'my', 'Myanmar (Burmese)', 'မြန်မာ', 0 ),
			'nr'      => array( 'nr', 'nr', 'Ndebele (South)', 'Ndebele (South)', 0 ),
			'new'     => array( 'new', 'new', 'Nepalbhasa (Newari)', 'Nepalbhasa (Newari)', 0 ),
			'ne'      => array( 'ne', 'ne', 'Nepali', 'नेपाली', 0 ),
			'nso'     => array( 'nso', 'nso', 'Northern Sotho (Sepedi)', 'Sesotho sa Leboa', 0 ),
			'no'      => array( 'no', 'no', 'Norwegian', 'Norsk', 0 ),
			'nus'     => array( 'nus', 'nus', 'Nuer', 'Thok Nath', 0 ),
			'oc'      => array( 'oc', 'oc', 'Occitan', 'occitan', 0 ),
			'or'      => array( 'or', 'or', 'Odia (Oriya)', 'ଓଡ଼ିଆ', 0 ),
			'om'      => array( 'om', 'om', 'Oromo', 'Oromoo', 0 ),
			'pag'     => array( 'pag', 'pag', 'Pangasinan', 'Pangasinan', 0 ),
			'pap'     => array( 'pap', 'pap', 'Papiamento', 'Papiamentu', 0 ),
			'ps'      => array( 'ps', 'ps', 'Pashto', 'پښتو', 1 ),
			'fa'      => array( 'fa', 'fa', 'Persian', 'فارسی', 1 ),
			'pl'      => array( 'pl', 'pl', 'Polish', 'Polski', 0 ),
			'pt'      => array( 'pt', 'pt', 'Portuguese', 'Português', 0 ),
			'pt-pt'   => array( 'pt-PT', 'pt-PT', 'Portuguese (Portugal)', 'português (Portugal)', 0 ),
			'pt-br'   => array( 'pt-BR', 'pt-BR', 'Portuguese (Brazil)', 'português (Brasil)', 0 ),
			'pa'      => array( 'pa', 'pa', 'Punjabi', 'ਪੰਜਾਬੀ', 0 ),
			'pa-arab' => array( 'pa-Arab', 'pa-Arab', 'Punjabi (Shahmukhi)', 'پنجابی (عربی)', 1 ),
			'qu'      => array( 'qu', 'qu', 'Quechua', 'Runasimi', 0 ),
			'rom'     => array( 'rom', 'rom', 'Romani', 'Romani', 0 ),
			'ro'      => array( 'ro', 'ro', 'Romanian', 'Română', 0 ),
			'rn'      => array( 'rn', 'rn', 'Rundi', 'Ikirundi', 0 ),
			'ru'      => array( 'ru', 'ru', 'Russian', 'Русский', 0 ),
			'sm'      => array( 'sm', 'sm', 'Samoan', 'Gagana Samoa', 0 ),
			'sg'      => array( 'sg', 'sg', 'Sango', 'Sängö', 0 ),
			'sa'      => array( 'sa', 'sa', 'Sanskrit', 'संस्कृत भाषा', 0 ),
			'gd'      => array( 'gd', 'gd', 'Scottish Gaelic', 'Gàidhlig', 0 ),
			'sr'      => array( 'sr', 'sr', 'Serbian', 'Српски', 0 ),
			'st'      => array( 'st', 'st', 'Sesotho', 'Sesotho', 0 ),
			'crs'     => array( 'crs', 'crs', 'Seychellois Creole', 'Seychellois Creole', 0 ),
			'shn'     => array( 'shn', 'shn', 'Shan', 'တႆး', 0 ),
			'sn'      => array( 'sn', 'sn', 'Shona', 'Shona', 0 ),
			'scn'     => array( 'scn', 'scn', 'Sicilian', 'sicilianu', 0 ),
			'szl'     => array( 'szl', 'szl', 'Silesian', 'ślōnski', 0 ),
			'sd'      => array( 'sd', 'sd', 'Sindhi', 'سنڌي', 1 ),
			'si'      => array( 'si', 'si', 'Sinhala', 'සිංහල', 0 ),
			'sk'      => array( 'sk', 'sk', 'Slovak', 'Slovenčina', 0 ),
			'sl'      => array( 'sl', 'sl', 'Slovenian', 'Slovenščina', 0 ),
			'so'      => array( 'so', 'so', 'Somali', 'Soomaali', 0 ),
			'es'      => array( 'es', 'es', 'Spanish', 'Español', 0 ),
			'su'      => array( 'su', 'su', 'Sundanese', 'Basa Sunda', 0 ),
			'sw'      => array( 'sw', 'sw', 'Swahili', 'Kiswahili', 0 ),
			'ss'      => array( 'ss', 'ss', 'Swati', 'siSwati', 0 ),
			'sv'      => array( 'sv', 'sv', 'Swedish', 'Svenska', 0 ),
			'tg'      => array( 'tg', 'tg', 'Tajik', 'Тоҷикӣ', 0 ),
			'ta'      => array( 'ta', 'ta', 'Tamil', 'தமிழ்', 0 ),
			'tt'      => array( 'tt', 'tt', 'Tatar', 'татар', 0 ),
			'te'      => array( 'te', 'te', 'Telugu', 'తెలుగు', 0 ),
			'tet'     => array( 'tet', 'tet', 'Tetum', 'Tetum', 0 ),
			'th'      => array( 'th', 'th', 'Thai', 'ไทย', 0 ),
			'ti'      => array( 'ti', 'ti', 'Tigrinya', 'ትግርኛ', 0 ),
			'ts'      => array( 'ts', 'ts', 'Tsonga', 'Tsonga', 0 ),
			'tn'      => array( 'tn', 'tn', 'Tswana', 'Setswana', 0 ),
			'tr'      => array( 'tr', 'tr', 'Turkish', 'Türkçe', 0 ),
			'tk'      => array( 'tk', 'tk', 'Turkmen', 'türkmen dili', 0 ),
			'ak'      => array( 'ak', 'ak', 'Twi (Akan)', 'Twi (Akan)', 0 ),
			'uk'      => array( 'uk', 'uk', 'Ukrainian', 'Українська', 0 ),
			'ur'      => array( 'ur', 'ur', 'Urdu', 'اردو', 1 ),
			'ug'      => array( 'ug', 'ug', 'Uyghur', 'ئۇيغۇرچە', 1 ),
			'uz'      => array( 'uz', 'uz', 'Uzbek', 'Oʻzbek', 0 ),
			'vi'      => array( 'vi', 'vi', 'Vietnamese', 'Tiếng Việt', 0 ),
			'cy'      => array( 'cy', 'cy', 'Welsh', 'Cymraeg', 0 ),
			'xh'      => array( 'xh', 'xh', 'Xhosa', 'isiXhosa', 0 ),
			'yi'      => array( 'yi', 'yi', 'Yiddish', 'ייִדיש', 1 ),
			'yo'      => array( 'yo', 'yo', 'Yoruba', 'Yorùbá', 0 ),
			'yua'     => array( 'yua', 'yua', 'Yucatec Maya', 'Yucatec Maya', 0 ),
			'zu'      => array( 'zu', 'zu', 'Zulu', 'isiZulu', 0 ),
		);

		$map = array();
		foreach ( $raw as $slug => $row ) {
			$map[ $slug ] = array(
				'slug'     => $slug,
				'google'   => $row[0],
				'hreflang' => $row[1],
				'name'     => $row[2],
				'native'   => $row[3],
				'rtl'      => (bool) $row[4],
			);
		}

		self::$map = $map;
		return self::$map;
	}

	/**
	 * Look up one language.
	 *
	 * @param string $slug Language slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all  = self::all();
		$slug = strtolower( (string) $slug );
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * The slug matching the language this WordPress site is set to.
	 *
	 * WordPress already knows what language the site is written in: it is the
	 * Site Language setting, it is what get_locale() returns, and it is what
	 * ends up in the opening <html lang> tag. A plugin that assumes English
	 * tells a Nigerian site its content is English and offers to translate it
	 * into Igbo, which is backwards.
	 *
	 * Locales are matched most specific first, so pt_BR picks the Brazilian
	 * row rather than collapsing to Portuguese.
	 *
	 * @return string A slug that exists in the table; 'en' if nothing matches.
	 */
	public static function site_slug() {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';

		return self::slug_for_locale( $locale );
	}

	/**
	 * Map a WordPress locale onto a slug in this table.
	 *
	 * @param string $locale A locale such as en_US, pt_BR or de_DE_formal.
	 * @return string
	 */
	public static function slug_for_locale( $locale ) {
		$all      = self::all();
		$normal   = strtolower( str_replace( '_', '-', trim( (string) $locale ) ) );
		$segments = array_values( array_filter( explode( '-', $normal ), 'strlen' ) );

		if ( empty( $segments ) ) {
			return 'en';
		}

		// de_DE_formal and friends carry a variant WordPress adds, not a region.
		$region = isset( $segments[1] ) ? $segments[0] . '-' . $segments[1] : '';

		if ( '' !== $region && isset( $all[ $region ] ) ) {
			return $region;
		}

		if ( isset( $all[ $segments[0] ] ) ) {
			return $segments[0];
		}

		// Google documents some languages under a code WordPress does not use.
		foreach ( $all as $slug => $language ) {
			if ( strtolower( $language['hreflang'] ) === $region
				|| strtolower( $language['google'] ) === $region ) {
				return $slug;
			}
		}

		foreach ( $all as $slug => $language ) {
			if ( strtolower( $language['hreflang'] ) === $segments[0]
				|| strtolower( $language['google'] ) === $segments[0] ) {
				return $slug;
			}
		}

		return 'en';
	}

	/**
	 * The hreflang tag to publish for the site's own language.
	 *
	 * For the source language this is whatever WordPress puts in <html lang>,
	 * so a site set to English (United States) is annotated en-US and one set
	 * to English (UK) is annotated en-GB. Translation targets keep the table's
	 * neutral tag, because the API returns one generic English, not a regional
	 * variety of it.
	 *
	 * @return string
	 */
	public static function site_hreflang() {
		$slug = leadst()->get( 'source_language', self::site_slug() );
		$row  = self::get( $slug );
		$tag  = $row ? $row['hreflang'] : 'en';

		if ( ! function_exists( 'get_bloginfo' ) ) {
			return $tag;
		}

		$reported = trim( (string) get_bloginfo( 'language' ) );

		if ( '' === $reported || ! preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $reported ) ) {
			return $tag;
		}

		// Only honour it when WordPress and the chosen source agree on the
		// language; an en-US site whose source is set to French must not
		// publish French pages as en-US.
		$base = strtolower( strtok( $reported, '-' ) );

		return ( $base === strtolower( strtok( $tag, '-' ) ) ) ? $reported : $tag;
	}

	/**
	 * Whether a slug is a language this plugin knows.
	 *
	 * @param string $slug Language slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		return null !== self::get( $slug );
	}

	/**
	 * Every valid slug.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_keys( self::all() );
	}

	/**
	 * Languages switched on in settings, source language excluded.
	 *
	 * @return array Slug => language row.
	 */
	public static function active() {
		$active = (array) leadst()->get( 'active_languages', array() );
		$source = leadst()->get( 'source_language', 'en' );
		$all    = self::all();
		$out    = array();

		foreach ( $active as $slug ) {
			$slug = strtolower( $slug );
			if ( $slug === $source || ! isset( $all[ $slug ] ) ) {
				continue;
			}
			$out[ $slug ] = $all[ $slug ];
		}

		return $out;
	}

	/**
	 * Active languages plus the source language, in display order.
	 *
	 * @return array Slug => language row.
	 */
	public static function active_with_source() {
		$source = leadst()->get( 'source_language', 'en' );
		$all    = self::all();
		$out    = array();

		if ( isset( $all[ $source ] ) ) {
			$out[ $source ] = $all[ $source ];
		}

		return $out + self::active();
	}

	/**
	 * Display label for a language.
	 *
	 * @param string $slug   Language slug.
	 * @param bool   $native Use the native name.
	 * @return string
	 */
	public static function label( $slug, $native = true ) {
		$lang = self::get( $slug );
		if ( ! $lang ) {
			return $slug;
		}
		return $native ? $lang['native'] : $lang['name'];
	}
}
