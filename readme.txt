=== Babilim – Auto-Translate Plugins & Themes ===
Contributors: babilim
Tags: translation, translate, automatic translation, localization, i18n
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fills in missing translations for the plugins and themes already installed on your site.

== Description ==

* See your installed plugins and themes with their translation status for each language you manage.
* Install an available translation with one click, or request one that is not available yet.
* Use any WordPress language. None is ranked, preferred or left out.
* Nothing is sent until you agree, not even when you activate the plugin.
* Your own `custom/` overrides always win over Babilim.

Babilim finds the plugins and themes on your site that have no translation for your language and lets you request one from the Babilim translation service.
The plugin needs that service to work. It has a free plan and an optional paid plan (see the FAQ).

Translation files are written only inside `wp-content/languages/babilim/`. Babilim does not touch your plugins, your themes or WordPress's own files.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/babilim`, or install it from the WordPress plugin directory.
2. Activate it on the "Plugins" screen. Activation sends nothing.
3. Open **Babilim > Translations**, read what will be sent, and press **Enable Babilim** if you agree.
4. Install or request translations on the same screen. Choose your languages under **Babilim > Settings**.

== Frequently Asked Questions ==

= Is anything sent before I agree? =

No. Nothing is sent when you activate the plugin or until you press "Enable Babilim". "Disable Babilim" in Settings stops every request again.

= What is sent? =

Your site's URL, version numbers, languages and the list of installed plugins and themes. The Enable screen lists what is sent automatically, and "External services" below and the privacy policy explain the rest.

= Where do the translations come from? =

The Babilim service makes them with AI and stores them. The plugin downloads translation files (`.po`, `.mo`, `.json`) and never runs remote code.

= Is it free? =

* The free plan covers one theme and two plugins listed on WordPress.org per site, which you choose with "Request translation".
* A request counts toward this allowance even if the service has already made that translation for another site.
* Once a component is covered, its later versions are translated and updated at no extra charge.
* On the free plan, a component removed from WordPress.org gets no new translation versions; the translations already installed stay.
* The paid plan covers every component. Prices: https://babilim.org/pricing/
* Free and paid sites use the plugin in exactly the same way, and translations are installed and updated the same way, by hand or automatically.

= Which translation wins, and can I keep my own? =

Your own file in `wp-content/languages/babilim/custom/` beats Babilim, which beats a WordPress.org language pack or the plugin's or theme's bundled translation. For WordPress core's own `default` text domain, core's official translation beats Babilim's.

= What happens when I deactivate or delete the plugin? =

Deactivating stops the scheduled checks and leaves the installed files in place. Deleting removes Babilim's settings and files and by default keeps your `custom/` overrides; by default it also keeps the site's registration so a reinstall picks up where you left off.

= Where do I get help or report a wrong translation? =

Use **Report translation** in the admin toolbar or **Report a problem** on the Translations screen. For questions, use the support forum at https://wordpress.org/support/plugin/babilim/ . For bugs, open an issue at https://github.com/babilim-org/babilim/issues . For license or billing matters, write to hi@babilim.org

== External services ==

This plugin connects to the Babilim translation service to look up, request and download translation files. It cannot work without it.

* Service: Babilim, `https://api.babilim.org`.
* When: **Nothing is sent before you consent.** No request is made when the plugin is activated, and none until you press "Enable Babilim". After that, requests are made when you use the plugin, on WordPress's twice-daily check, and when plugins or themes are installed, activated, deactivated, updated or switched or your plan changes, until you press "Disable Babilim".
* Sent: your site's URL; the WordPress, PHP and Babilim versions; the languages your site uses; a public key for the site; and short diagnostic codes, never any text content.
* Sent also: the installed plugins and themes with their versions, text domains, whether they are listed on WordPress.org, the languages each already includes (and which Babilim generated), and the state of any Babilim translation installed.
* Each request includes your site's URL and, when needed, its access token. The service also sees your server's IP address.
* Only when you act: requesting a translation (component, version, language); reporting a problem (what you fill in or select); a restore code you enter; and, for a component not on WordPress.org, uploading its code files as a zip after you confirm. They may contain anything, so check them first. Uploads and reports are stored and processed with AI models.
* Optional error reports, off by default, only if you choose to share: an error code, the action, where in the plugin and when it happened, a random report id, versions and the site language. Only the error code and versions are published as a public GitHub issue.
* Received: translation files and status.
* Checkout: "Upgrade this site" opens babilim.org in a new tab, where Paddle, the merchant of record, takes payment. This plugin never sees payment data.

The Enable screen lists what is sent automatically before you agree.

* Terms of Service: https://babilim.org/terms/
* Privacy Policy: https://babilim.org/privacy/
* Refund Policy: https://babilim.org/refund/
* Paddle Checkout Buyer Terms: https://www.paddle.com/legal/buyer-terms
* Paddle Privacy Policy: https://www.paddle.com/legal/privacy

== Screenshots ==

1. The Translations screen before you enable Babilim: what will be sent, and the Enable button.
2. The Translations screen after enabling: each installed plugin and theme with its translation status per language.
3. Settings: the languages to manage and the uninstall choices.

== Changelog ==

= 1.2.3 =
* A translation a plugin or theme already ships, when Babilim generated it, now counts as installed: no download, no request button, and no free slot used.
* New display name: Babilim – Auto-Translate Plugins & Themes.
* A quiet rating link on Babilim's screens and in the Plugins list.

= 1.2.2 =
* When a plugin or theme ships its own translation, you can switch between it and Babilim's translation.
* No request button for a translation that is already scheduled.
* Updated bundled German, Dutch and Chinese translations.

= 1.2.1 =
* A translation the service refused is asked for again when your plan or daily allowance changes.
* The readme and the Enable screen now describe what is sent in plain language.
* The FAQ explains what the free plan covers.

= 1.2.0 =
* Automatic and one-click installs use the same installer.
* A refusal from the service is no longer counted as a failed install.
* Notices appear only on Babilim's own screens, the Dashboard and the Plugins screen.

= 1.1.0 =
* Optional error reports, off by default.
* New "Report translation" entry in the admin toolbar.
* A source upload now contains only the component's code and description files.

= 1.0.1 =
* Shows "Original language" for a language a plugin or theme is written in.
* Detects more bundled translations.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.2.3 =
Translations a component already ships from Babilim no longer use a slot; no action needed.

= 1.2.2 =
Switch between a component's own translation and Babilim's; no action needed.

= 1.2.1 =
Plainer wording about what is sent; no action needed.

= 1.2.0 =
Automatic and one-click installs use the same installer.

= 1.1.0 =
Adds optional error reports and a toolbar report entry.

= 1.0.1 =
Better detection of bundled translations.

= 1.0.0 =
First release.
