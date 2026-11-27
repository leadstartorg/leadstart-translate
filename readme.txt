=== Leadstart Translate ===
Contributors: leadstartorg
Tags: translate, multilingual, translation, localization, hreflang
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://leadstart.org/donate

Multilingual WordPress with translation memory, indexable language URLs, a manual editor and a choice of translation engines.

== Description ==

Leadstart Translate creates translated front-end versions of a WordPress site without routing visitors through a translation proxy. Translation results are saved to site-owned WordPress tables and reused when the same source text and target language reoccur.

= Translation engines =

Choose one primary engine in Translate > Settings:

* Google Cloud Translation v2 (neural machine translation).
* Google Gemini (your API key and selected model).
* WordPress AI Client (WordPress 7.0 or later; uses the provider you configure under Settings > Connectors).
* Mistral AI (optional API key and model).
* Qwen on Alibaba Cloud Model Studio (optional key, model, workspace ID and region).
* DeepL API (optional Free or Pro API key, or opt-in first background fallback).

The site owner supplies credentials and agrees to each selected provider's terms. No Mistral or Qwen key is required unless that engine is selected. WordPress AI Client uses the credentials already configured in WordPress. DeepL can be enabled as the first background fallback when another primary engine is unavailable or fails; it is disabled by default and only used for languages supported by DeepL. Optional private self-hosted and bulk LLM services can be enabled as later fallback tiers.

= Features =

* Language registry of 194 language codes, based on a published Google NMT list (provider support varies).
* Original-language detection from WordPress Site Language.
* Database-backed translation memory and a manual translation editor; manual corrections are protected against machine overwrites.
* Translated subdirectory URLs and an optional subdomain mode requiring separate DNS/origin routing setup.
* Translated URL slugs, hreflang alternates, multilingual SEO metadata and paginated sitemaps.
* Dropdown, inline and list language switchers; optional floating switcher.
* RTL styling, language-specific CSS classes and optional Google Fonts (off by default).
* Background translation queue and persistent pre-fill jobs in custom indexed tables.
* Atomic, independently metered Google Cloud, DeepL, and AI-provider source-character volume reservations, with separate daily and monthly limits.
* Optional self-hosted translation fallback and an additional bulk LLM fallback for background jobs.
* Provider comparison tool, URL diagnostics and usage statistics.
* Exclusion rules for selected content, selectors and paths.

= Billing and privacy =

Google Cloud Translation, DeepL, and AI providers use **separate local character-volume guards**. New installations default to a shared AI limit of 50,000 source characters/day and 300,000/month for Gemini, Mistral, Qwen, WordPress AI Client and optional bulk LLM fallback. These source-character limits do not calculate tokens, compute cost, or guarantee provider currency caps. Configure separate quotas and billing alerts with every provider before activating or bulk pre-filling languages.

All translatable page text is sent to the selected external provider when translation is needed; this may include text relating to your visitors or users. You are responsible for privacy notices, lawful data sharing, and configuring exclusions for personal or confidential text data. A translation request is never a substitute for consent where consent is required.

= Requirements and caveats =

WordPress 7.0+, PHP 7.4+ with DOM and HTTPS connectivity to the configured provider. Database-backed jobs require MySQL/MariaDB with InnoDB support. Please configure server cron for predictable high-traffic or high-volume pre-fill. Provider model and language availability can change over time.

No external service is automatically contacted simply by activating the plugin; with a configured engine and languages, the site can send text for translation. The optional font service is off by default.

Subdomain translation requires the relevant language hostnames to reach the same installation. The plugin does not configure DNS. All structured data inside JSON-LD is not currently translated; images, PDFs and non-rendered third-party content are outside scope. WooCommerce compatibility and all SEO-plugin combinations require site-specific testing.

== External services ==

**Google Cloud Translation API**: Sends source text and target language for translation only when selected. Terms: https://cloud.google.com/terms/ Privacy: https://policies.google.com/privacy

**Google Gemini**: Sends strings and translation instructions when Gemini is selected or its optional bulk tier is enabled. Terms: https://ai.google.dev/gemini-api/terms Privacy: https://policies.google.com/privacy

**Mistral AI**: Sends strings and language instructions to the official Mistral API when the Mistral engine is selected. Terms: https://mistral.ai/terms Privacy: https://mistral.ai/terms#privacy-policy

**Qwen via Alibaba Cloud Model Studio**: Sends source strings and translation instructions to the workspace-specific Model Studio endpoint in the region selected by the administrator. The API key must come from the same region. Terms: https://www.alibabacloud.com/help/en/legal/latest/alibaba-cloud-international-website-product-terms-of-service Privacy: https://www.alibabacloud.com/help/en/legal/latest/alibaba-cloud-international-website-privacy-policy

**DeepL API**: Sends source text and target language to the DeepL Free or Pro API only when selected as primary, explicitly opted in as fallback, or invoked by an administrator in the comparison tool. Terms: https://www.deepl.com/en/pro-license Privacy: https://www.deepl.com/privacy

**WordPress AI Client**: Uses the external AI provider(s) configured by the administrator under WordPress Settings > Connectors. Requests, provider terms and privacy depend on those configured provider plugins. No duplicate provider key is stored by Leadstart Translate.

**OpenAI**: Used only if the separately enabled bulk LLM fallback is set to OpenAI. Terms: https://openai.com/policies/terms-of-use/ Privacy: https://openai.com/policies/privacy-policy/

**Optional self-hosted fallback**: Text may be sent to the administrator's configured private translation service. The operator is responsible for its terms, billing and privacy. It is disabled by default.

**Google Fonts**: Optional and off by default. If enabled, the visitor's browser requests script-appropriate fonts from Google Fonts, exposing network metadata to Google. Terms: https://developers.google.com/fonts/faq Privacy: https://policies.google.com/privacy

Leadstart Translate is not affiliated with or endorsed by Google, Mistral AI, Alibaba Cloud or OpenAI.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the ZIP under Plugins > Add New > Upload Plugin. If you are updating an existing site, back up the database first and test on staging.
2. Activate the plugin. Settings and translations from earlier pre-release builds are adopted automatically.
3. Go to Translate > Settings and choose a primary engine; configure its key or WordPress AI Client connection. For Qwen, enter your workspace ID and the region matching the key.
4. Choose source and target languages, save, and use Status & Tools to test the selected engine.
5. Verify translated URLs, editing, sitemaps, cron, caching, API costs and SEO compatibility in staging before production.

== Screenshots ==

1. Translation engines: Google Cloud Translation
2. Translation engines: AI providers (Gemini, Mistral, Qwen Alibaba and DeepL) 
3. Multi-tier fallback settings for a self-hosted translation service, WordPress AI Client, and bulk LLM fallback.
4. Local daily and monthly character-volume spending limits.
5. Language selection: The primary site language, 193 target languages, and estimated character volume.
6. Exclusion rules for protected terms, CSS selectors/tags, and URL paths.
7. Status & Tools screen: Displaying real-time API character usage and pre-fill progress.
8. Status & Tools screen: Diagnostic actions, engine comparison controls, and the translated URL 404 tracer.
9. Front-end WordPress site translated into Spanish.
10. Front-end WordPress site translated into French.
11. Front-end WordPress site translated into Kiswahili.

== Frequently Asked Questions ==

= Is the WordPress AI Client the same as a paid provider? =

No, the AI Client is the WordPress Core routing API, available in WordPress 7.0+. A compatible provider plugin and administrator-configured connection are required. Provider pricing and available models depend on that connection.

= Does the plugin guarantee that translation requests are free? =

No, Google Cloud and AI character-volume limits are not currency billing guarantees. AI providers can charge per token or request. Cached translations avoid repeated identical translation requests, but provider usage must still be monitored.

= Can I switch engines without losing translations? =

Yes, saved source/target translation-memory entries are reused, including manually edited translations. If you switch engines, the plugin does not automatically retranslate existing strings.


== Licensing ==

Leadstart Translate is distributed under GPLv2 or later. We do not sell premium features, require a license purchase, or include a paid upgrade tier. External translation services may bill you under their own terms, and choosing a service is an administrator action.


== Changelog ==

= 1.0.0 =
* Initial release.
