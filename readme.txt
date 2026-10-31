=== Babilim ===
Contributors: babilim
Tags: translation, internationalization, i18n, l10n, language packs
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fills in missing translations for the plugins and themes already installed on your site.

== Description ==

Babilim looks at the plugins and themes you already have installed, finds the ones that have no translation for your site's language, and lets you request one from the Babilim translation service. A translation that is already available can be installed with one click; one that is not yet available can be requested, and the settings screen shows its progress.

* **Every WordPress language on equal footing.** The list of languages comes from the full WordPress.org locale registry. No language is ranked, preferred or left out.
* **Nothing happens until you agree.** The plugin makes no request to the service, not even when you activate it, until you press "Enable Babilim" on its Translations screen. The screen lists every item that will be sent right above the button.
* **Translation data only.** Babilim downloads translation files (`.po`, `.mo`, `.json`) and nothing else. It never downloads or runs PHP or JavaScript from anywhere.
* **Your own overrides always win.** A translation you place under `custom/` beats anything Babilim provides.
* **Check, roll back, report.** Babilim checks every installed file against its recorded checksum automatically, twice a day. A translation can be rolled back to the previous build or removed. If a translation reads wrongly you can report it from the same screen.
* **Clean removal.** Deleting the plugin removes its options, scheduled events and language files. Your `custom/` overrides are kept unless you choose otherwise.

Babilim only writes inside its own folder, `wp-content/languages/babilim/`; the only other write is a temporary zip in the system temp directory, and only for a source upload you confirm (see External services). If `wp-content/languages/` does not exist yet, Babilim creates only that empty folder. It does not touch your plugins' or themes' own files, nor the language files WordPress itself installs.

== How translations are loaded ==

The loading order, most specific first:

* For a plugin's or theme's own text domain: a site-specific override in `custom/` (if you have set one) beats a translation Babilim provides, which in turn beats an official WordPress.org language pack or the plugin's or theme's own bundled translation.
* For WordPress core's own `default` text domain: WordPress core's official translation beats any supplementary strings Babilim provides for that domain.

A source only ever fills a gap left by everything ranked above it. A site-specific override always wins over Babilim, and WordPress core's own official `default` translation always wins over Babilim's supplement for that domain. Where two installed components would supply different translations for the same core string, Babilim leaves that string out and WordPress falls back to the original English text.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/babilim`, or install it from the WordPress plugin directory.
2. Activate the plugin through the "Plugins" screen. Activation sends nothing.
3. Open **Babilim > Translations** in the admin menu, read the list of information that will be sent, and press **Enable Babilim** if you agree. The plugin registers the site, verifies it and checks your plugins and themes in one step.
4. Components with a missing translation appear on the Translations screen, where you can install or request a translation. Choose the locales to manage under **Babilim > Settings**.

== Frequently Asked Questions ==

= Does the plugin contact any server before I agree? =

No. Nothing is sent when the plugin is activated or until you press "Enable Babilim". "Disable Babilim" on the settings screen later stops every request the plugin makes.

= What exactly is sent when I agree? =

See the "External services" section below. It matches the list shown above the Enable button.

= Does this plugin modify my site's files or code? =

No. It only writes translation data files (`.po`, `.mo`, `.json` and the `.l10n.php` files WordPress itself generates from them) into its own `wp-content/languages/babilim/` folder. It never fetches or runs remote PHP, JavaScript or any other executable code.

= Where do the translations come from? =

From the Babilim translation service, over its own API. See "External services" below.

= Which languages can I use? =

Every locale WordPress supports. The list is read from the WordPress.org locale registry through the service, and no locale is treated differently from another. You can also type in a locale code that is not listed.

= Which translation wins when there are several? =

See "How translations are loaded" above: your own `custom/` override first, then Babilim, then a WordPress.org language pack or the component's bundled translation. For WordPress core's own strings, core's official translation comes first.

= Can I keep my own corrected translations? =

Yes. Put your own `.mo`, `.json` or `.l10n.php` file under `wp-content/languages/babilim/custom/plugins/` or `custom/themes/`. It always wins over anything Babilim provides. A setting on the settings screen keeps these files when you purge Babilim's files or uninstall the plugin; it is on by default.

= Is the service free? =

Each site domain can use the service for one theme and two plugins that are listed on WordPress.org at no charge. Components that are not listed on WordPress.org, and anything beyond that allowance, need a paid license (see "How much does a license cost?"). The allowance is set by the service and may change; the plugin contains no limit logic of its own and only shows what the service reports. Translation files that were already downloaded keep working regardless of the license.

= Are there limits on downloads? =

The service may limit how many translations a site can download. Those limits are decided and reported by the service; the plugin only shows what the service says on the settings screen.

= What happens if the service is unreachable? =

Translations you have already installed keep working, because they are ordinary local files. The plugin retries later.

= What happens when I deactivate or delete the plugin? =

Deactivating stops the scheduled tasks and leaves your installed translation files where they are, but the plugin's loading layer is then no longer active. Deleting the plugin removes its settings, its scheduled events and everything it recognizes under `wp-content/languages/babilim/`, except the `custom/` folder while "Keep my own translation overrides…" is ticked; files the plugin did not create there may remain. It makes no request to the service while doing so. By default deleting keeps your site's identity (see the next question) so that installing the plugin again picks up where you left off.

= Does deleting the plugin also delete my site's identity and license? =

No, not by default. The plugin keeps three things when it is deleted: the site's registration number, its access token and its install key. If you install the plugin again on the same site, it reuses them and its license stays attached and you do not pay for it again. (A site that never finished its first setup simply registers again with the same install key.)

The settings screen has a checkbox, "Delete all Babilim data on uninstall", which is off by default. If you tick it before deleting the plugin, the site's registration number, access token and install key are deleted too. After that, the site's paid license cannot be restored automatically: you must contact us at hi@babilim.org to get it back. Leave the box unticked unless you are sure you will never need this site's license again. WordPress runs no plugin code while a plugin is being deleted, so the deletion itself cannot show a warning. Instead, while the box is ticked, the plugin asks you to confirm when you click Deactivate on the Plugins screen. To keep the identity, untick the box before you deactivate the plugin.

= Which hosts and how large a site can use the one-click enable? =

Enabling registers the site, and the service then calls back to a public endpoint of your own site to verify it, so your host must be able to run a second PHP request while the first one is waiting. A host that serves only one PHP request at a time cannot complete that step; the plugin then says so. A single request should be allowed to run for at least 60 seconds. The first check is split into batches that are sent one after another, and one run sends at most seven; if it runs out of time it continues in the background and the Translations screen says so. A site with more components than that can be checked in one run needs fewer managed languages (Babilim > Settings) or fewer active plugins and themes, and the screen tells you by how much.

= The settings screen says the service could not reach my site. What does that mean? =

To register a site, the service calls back to a public endpoint of your own site, `/wp-json/babilim/v1/challenge`. If that call does not get through, the plugin shows a warning with the reason and does not download or install anything until the service can reach your site. Typical causes are a firewall, a security plugin or a host rule blocking requests from the service (allow the endpoint above), a very slow host (try again later), or a REST API that has been rewritten or a caching plugin that caches that endpoint. Press "Re-verify" on the settings screen once you have fixed the cause; the plugin also retries by itself, with longer and longer pauses.

= What happens on a multisite network? =

Deleting the plugin network-wide removes every site's own settings, not just the site the deletion happened to run on -- each site on the network is cleaned in turn. Each site's identity (registration number, access token and install key) is kept, unless that particular site ticked "Delete all Babilim data on uninstall" in its own settings, in which case it is deleted for that site. The `wp-content/languages/babilim/` folder is shared by the whole network, so it is only ever purged once no matter how many sites there are; a file the plugin did not create there may remain. If any one site had "Keep my own translation overrides…" ticked, the shared `custom/` folder is kept for the whole network, even if another site had it unticked. Before you deactivate the plugin from the network plugins list, it checks every site's own "Delete all Babilim data on uninstall" setting and asks for confirmation if any site has it ticked (on a very large network it asks without counting).

= How much does a license cost? =

A license is a one-time purchase per site domain; the current price is shown on the Settings screen next to the Upgrade button and includes any applicable tax. It includes all future translation updates for that site. Payment is handled by Paddle, our merchant of record, on the Babilim checkout page at babilim.org that opens in a new tab; the plugin never sees your payment details. Refunds follow Paddle's refund policy, see https://babilim.org/refund/.

= What is a restore code? =

When you buy a license you receive a restore code. If you reinstall the plugin on the same domain, enter the code under Settings → Restore purchase to attach the license to the new installation. The Settings screen of a licensed site can issue a new code at any time (the previous one stops working). The code is not needed for normal use. Moving a license to a different domain is handled by support (see "How do I contact you?").

= How do I contact you? =

Write to hi@babilim.org for support, including restoring a site's license.

= I found a wrong translation. =

Use **Report a problem** on the Babilim screen. You choose a category and may add the text, a suggestion and a note. Nothing is sent until you submit the form.

== External services ==

This plugin connects to the Babilim translation service, a hosted API, to look up, request and download translation files for the plugins and themes installed on your site. It cannot work without this connection.

Service: Babilim, at `https://api.babilim.org`. This is the value of the `BABILIM_API_BASE` constant defined in the plugin's main file, and the only address the plugin itself ever sends requests to. The one other address involved is the checkout page described under "Checkout" below, which opens in your browser and is not contacted by the plugin.

* Terms of Service: https://babilim.org/terms/
* Privacy Policy: https://babilim.org/privacy/
* Refund Policy: https://babilim.org/refund/

Checkout: when you click "Upgrade this site", the Babilim checkout page at babilim.org opens in a new tab and loads Paddle's payment script there. Nothing from Paddle is loaded inside your WordPress admin, and no payment data passes through this plugin. Payment is processed by Paddle as merchant of record.

* Paddle Checkout Buyer Terms: https://www.paddle.com/legal/buyer-terms
* Paddle Privacy Policy: https://www.paddle.com/legal/privacy

**Nothing is sent before you consent.** No request is made when the plugin is activated, and none until you press "Enable Babilim". Once you have, requests are made when you use the plugin and on WordPress's own twice-daily scheduled check. "Disable Babilim" stops them all.

**What is sent when you register the site and on every sync.** Registration happens when you press "Enable Babilim". A sync runs twice a day and whenever you press "Check again now". Both send the items listed above the Enable button:

* `home_url`: your site's URL, so the service can confirm that you control the site. The service also calls back to a public endpoint of your own site, `/wp-json/babilim/v1/challenge`, to check a signed answer;
* `wp_version`, `php_version` and `plugin_version`: your WordPress version, your PHP version and this plugin's version, to serve compatible files;
* `site_locale` and `locales`: your site's configured locale, and every locale in use on the site (site locale, installed language packs, user locales and any you add by hand);
* `install_pub`: a public key generated for your site. Only the public half is ever sent; the private half stays in your database and is never shown or sent;
* `components`: for every installed plugin and theme, its slug, version, text domain(s), whether it is a WordPress.org listing, its own bundled locales, and which locales you have already installed a Babilim translation for;
* `client_events`: up to 20 pending diagnostic events (for example, a file that failed its checksum), each consisting only of a code, a component and a locale. No string content or translated text is included.

Every request also carries an `Authorization` header holding the site's access token, and a `User-Agent` of the form `Babilim/<plugin version>; <site URL>`. The service sees your server's IP address as part of any web request.

**Automatic downloads during the sync.** While "Install available translations automatically" is on (it is on by default; you can turn it off under Settings), the twice-daily sync downloads available translations for the languages you manage without a click, and each download sends the identifier of the build. On the free plan only components that already use one of your free slots are installed this way, so no new slot is taken. Translations you remove yourself are not installed again automatically until you download them by hand. "Remove everything Babilim installed" also turns automatic installation off; turning it on again later can bring translations back. On a multisite network, new translations are not installed automatically at all; translations already installed are still updated.

**What is sent only when you take an action.**

* Requesting a translation sends the component kind, its slug and version, and the locale.
* Downloading a translation sends the identifier of the build you chose.
* Reading the list of locales sends no site data beyond the headers above.
* Reporting a problem sends the component, locale and build, the category you chose, and any of the following that you fill in: the original text, the current translation, your suggested translation, a note, and the address of the screen where you saw it.

**Uploading a component's source (only after you confirm).** For a plugin or theme that is not listed on WordPress.org, requesting a translation needs the component's source. The service asks for it and the plugin shows a confirmation screen first; nothing is uploaded until you press "Upload and request". If you do, the entire plugin or theme directory is packaged as a zip and uploaded in parts. If that directory contains keys, logs or customer data, they are uploaded too; the service removes common sensitive files (configuration, environment, database dumps, logs, keys) but cannot guarantee it finds everything. The upload is kept by the service until you ask for it to be deleted, is stored by its hosting provider and processed by AI models, and the original text and the translations made from it are provided to other sites that install the same version of the component. By uploading you confirm you have the right to do so. The temporary zip is written to the system temporary directory, or to WordPress's temporary directory when the system one is not usable or lies inside the site. The directory has a random name and gets a deny-all `.htaccess` (honoured by Apache-compatible servers only) and an empty `index.php`. The zip is deleted as soon as the upload finishes or fails, and anything left behind is removed after 24 hours by a daily job.

**What is received.** Translation data files (`.po`, `.mo`, `.json`) for the locales you manage, together with status information: which translations exist, their build identifiers and dates, and any download limit that applies to your site. Nothing that can be executed is ever received or run.

The Translations screen shows the same list before you consent, and your consent can be withdrawn at any time with "Disable Babilim".

== Privacy ==

The information described under "External services" is the only information the plugin sends, and only after you consent. The only exception is a source upload you explicitly confirm, described above. It never sends the content of your posts, pages, comments or media, user names or e-mail addresses, or your WordPress administrator account. The site access token and the private key are stored in your site's database and are never included in a page or script sent to a browser. Deleting the plugin keeps them by default so that a reinstall can reuse the site's identity; ticking "Delete all Babilim data on uninstall" removes them.

== Screenshots ==

1. The Translations screen before you enable Babilim: what will be sent, and the Enable button.
2. The Translations screen after enabling: each installed plugin and theme with its translation status per language.
3. Settings: the languages to manage and the uninstall choices.

== Changelog ==

= 1.0.0 =
* First release.
* Consent-first registration and twice-daily sync with the Babilim translation service.
* Layered translation loading: your `custom/` overrides, then Babilim, then WordPress.org language packs or bundled translations; WordPress core's own translation first for the `default` text domain.
* Install, check, roll back, remove and report actions for each translation, and automatic installation of newer builds of translations you already installed, during the twice-daily sync.
* Automatic installation of available translations for the languages you manage during the twice-daily sync, on by default and switchable in Settings. On the free plan only components that already use a free slot are installed this way, and translations you removed yourself are not installed again. A short notice on the Translations screen says how many were installed. "Remove everything Babilim installed" turns it off. A multisite network does not install new translations automatically.
* Removal of settings, scheduled events and language files on uninstall, with your `custom/` overrides and your site's identity kept by default.

== Upgrade Notice ==

= 1.0.0 =
First release.
