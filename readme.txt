=== Babilim ===
Contributors: babilim
Tags: translation, internationalization, i18n, l10n, language packs
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fills in missing translations for the plugins and themes already installed on your site.

== Description ==

* See your installed plugins and themes with their translation status for each language you manage.
* Install an available translation with one click, or request one that is not available yet.
* Use any WordPress language. None is ranked, preferred or left out.
* Nothing is sent until you agree, not even when you activate the plugin.
* Your own `custom/` overrides always win over Babilim.

Babilim looks at the plugins and themes you already have installed and finds the ones that have no translation for your site's language.
It then lets you request a translation from the Babilim translation service.

If a plugin or theme ships its own translation, you can still request a Babilim translation for it.
The settings screen shows the progress of a request.
If an installed translation reads wrong, report it from the "Report translation" entry in the admin toolbar.

More details:

* **Languages.** The list of languages comes from the full WordPress.org locale registry.
* **Consent first.** The plugin makes no request to the service until you press "Enable Babilim" on its Translations screen. Activating the plugin sends nothing. The screen lists every item that will be sent right above the button.
* **Translation data only.** Babilim downloads translation files (`.po`, `.mo`, `.json`) and nothing else. It never downloads or runs PHP or JavaScript from anywhere.
* **Your own overrides.** A translation you place under `custom/` beats anything Babilim provides.
* **Check, roll back, report.** Babilim checks every installed file against its recorded checksum automatically, twice a day. A translation can be rolled back to the previous build or removed. If a translation reads wrongly you can report it from the same screen.
* **Clean removal.** Deleting the plugin removes its settings, scheduled events and the language files it installed. Two separate settings decide what stays: by default the three identity records (registration number, access token and install key) are kept so a reinstall picks the site up again, and your own `custom/` overrides are kept.

Where Babilim writes:

* Babilim only writes inside its own folder, `wp-content/languages/babilim/`.
* The only other write is a temporary zip for a source upload you confirm, in the system temporary directory or a folder your host names in the BABILIM_TEMP_DIR constant, and only when that folder lies outside the website (see External services).
* If `wp-content/languages/` does not exist yet, Babilim creates only that empty folder.
* It does not touch your plugins' or themes' own files, nor the language files WordPress itself installs.

Named after Babel: language should not stand between people and the web.

== How translations are loaded ==

The loading order, most specific first:

* For a plugin's or theme's own text domain: a site-specific override in `custom/` (if you have set one) beats a translation Babilim provides, which in turn beats an official WordPress.org language pack or the plugin's or theme's own bundled translation.
* For WordPress core's own `default` text domain: WordPress core's official translation beats any supplementary strings Babilim provides for that domain.

A source only ever fills a gap left by everything ranked above it.

* A site-specific override always wins over Babilim.
* WordPress core's own official `default` translation always wins over Babilim's supplement for that domain.

Where two installed components would supply different translations for the same core string, Babilim leaves that string out and WordPress falls back to the original English text.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/babilim`, or install it from the WordPress plugin directory.
2. Activate the plugin through the "Plugins" screen. Activation sends nothing.
3. Open **Babilim > Translations** in the admin menu, read the list of information that will be sent, and press **Enable Babilim** if you agree. The plugin registers the site, verifies it and checks your plugins and themes in one step.
4. Components with a missing translation appear on the Translations screen, where you can install or request a translation. Choose the locales to manage under **Babilim > Settings**.

== Frequently Asked Questions ==

= Does the plugin contact any server before I agree? =

No. Nothing is sent when the plugin is activated or until you press "Enable Babilim".
"Disable Babilim" on the settings screen later stops every request the plugin makes.

= What exactly is sent when I agree? =

See the "External services" section below. It matches the list shown above the Enable button.

= Does this plugin modify my site's files or code? =

No. It only writes translation data files into its own `wp-content/languages/babilim/` folder.
Those are `.po`, `.mo`, `.json` and the `.l10n.php` files WordPress itself generates from them.
It never fetches or runs remote PHP, JavaScript or any other executable code.

= Where do the translations come from? =

From the Babilim translation service, over its own API. See "External services" below.

= Which languages can I use? =

Every locale WordPress supports.
The list is read from the WordPress.org locale registry through the service, and no locale is treated differently from another.
You can also type in a locale code that is not listed.

= Which translation wins when there are several? =

See "How translations are loaded" above.
Your own `custom/` override comes first, then Babilim, then a WordPress.org language pack or the component's bundled translation.
For WordPress core's own strings, core's official translation comes first.

= Can I keep my own corrected translations? =

Yes. Put your own `.mo`, `.json` or `.l10n.php` file under `wp-content/languages/babilim/custom/plugins/` or `custom/themes/`.
It always wins over anything Babilim provides.
A setting on the settings screen keeps these files when you purge Babilim's files or uninstall the plugin. It is on by default.

= Is the service free? =

Each site domain can use the service for one theme and two plugins that are listed on WordPress.org at no charge.
Components that are not listed on WordPress.org, and anything beyond that allowance, need a paid license (see "How much does a license cost?").

The allowance is set by the service and may change.
The plugin contains no limit logic of its own and only shows what the service reports.
Translation files that were already downloaded keep working regardless of the license.

= Are there limits on downloads? =

The service may limit how many translations a site can download.
Those limits are decided and reported by the service. The plugin only shows what the service says on the settings screen.

= What happens if the service is unreachable? =

Translations you have already installed keep working, because they are ordinary local files.
The plugin retries later.

= What happens when I deactivate or delete the plugin? =

* Deactivating stops the scheduled tasks and leaves your installed translation files where they are. The plugin's loading layer is then no longer active.
* Deleting the plugin removes its settings, its scheduled events and everything it recognizes under `wp-content/languages/babilim/`, except what the two settings below keep.
* The `custom/` folder is kept while "Keep my own translation overrides…" is ticked. Files the plugin did not create there may remain.
* Deleting makes no request to the service.
* By default deleting keeps your site's identity (see the next question), so that installing the plugin again picks up where you left off.

= Does deleting the plugin also delete my site's identity and license? =

No, not by default.
The plugin keeps three things when it is deleted: the site's registration number, its access token and its install key.

If you install the plugin again on the same site, it reuses them. Its license stays attached and you do not pay for it again.

A site that never finished its first setup simply registers again with the same install key.

The settings screen has a checkbox, "Delete all Babilim data on uninstall", which is off by default.
If you tick it before deleting the plugin, the site's registration number, access token and install key are deleted too.

After that, the site's paid license cannot be restored automatically: you must contact us at hi@babilim.org to get it back.
Leave the box unticked unless you are sure you will never need this site's license again.

The uninstall step has no screen of its own and cannot ask you anything.
Instead, while the box is ticked, the plugin asks you to confirm when you click Deactivate on the Plugins screen.
To keep the identity, untick the box before you deactivate the plugin.

= Which hosts and how large a site can use the one-click enable? =

Enabling registers the site, and the service then calls back to a public endpoint of your own site to verify it.
Your host must therefore be able to run a second PHP request while the first one is waiting.
A host that serves only one PHP request at a time cannot complete that step. The plugin then says so.
A single request should be allowed to run for at least 60 seconds.

The first check is split into batches that are sent one after another, and one run sends at most seven.
If it runs out of time it continues in the background and the Translations screen says so.
A site with more components than that can be checked in one run needs fewer managed languages (Babilim > Settings) or fewer active plugins and themes.
The screen tells you by how much.

= The settings screen says the service could not reach my site. What does that mean? =

To register a site, the service calls back to a public endpoint of your own site, `/wp-json/babilim/v1/challenge`.
If that call does not get through, the plugin shows a warning with the reason.
It does not download or install anything until the service can reach your site.

Typical causes:

* A firewall, a security plugin or a host rule blocking requests from the service (allow the endpoint above).
* A very slow host (try again later).
* A REST API that has been rewritten, or a caching plugin that caches that endpoint.

Press "Re-verify" on the settings screen once you have fixed the cause.
The plugin also retries by itself, with longer and longer pauses.

= What happens on a multisite network? =

* Deleting the plugin network-wide removes every site's own settings, not just the site the deletion happened to run on. Each site on the network is cleaned in turn.
* Each site's identity (registration number, access token and install key) is kept, unless that particular site ticked "Delete all Babilim data on uninstall" in its own settings. In that case it is deleted for that site.
* The `wp-content/languages/babilim/` folder is shared by the whole network, so it is only ever purged once no matter how many sites there are. A file the plugin did not create there may remain.
* If any one site had "Keep my own translation overrides…" ticked, the shared `custom/` folder is kept for the whole network, even if another site had it unticked.
* Before you deactivate the plugin from the network plugins list, it checks every site's own "Delete all Babilim data on uninstall" setting and asks for confirmation if any site has it ticked. On a very large network it asks without counting.

= How much does a license cost? =

* A license is a one-time purchase per site domain.
* The current price is shown on the Settings screen next to the Upgrade button and includes any applicable tax.
* It includes all future translation updates for that site.
* Payment is handled by Paddle, our merchant of record, on the Babilim checkout page at babilim.org that opens in a new tab. The plugin never sees your payment details.
* Refunds follow Paddle's refund policy, see https://babilim.org/refund/.

= What is a restore code? =

When you buy a license you receive a restore code.
If you reinstall the plugin on the same domain, enter the code under Settings → Restore purchase to attach the license to the new installation.

The Settings screen of a licensed site can issue a new code at any time (the previous one stops working).
The code is not needed for normal use.

Moving a license to a different domain is handled by support (see "How do I contact you?").

= How do I contact you? =

Write to hi@babilim.org for support, including restoring a site's license.

= Where do I get help or report a bug? =

* A wrong translation: use **Report a problem** inside the plugin.
* General questions: the WordPress.org support forum at https://wordpress.org/support/plugin/babilim/
* A bug or a feature request: open an issue at https://github.com/babilim-org/babilim/issues
* Security, license or billing matters: write to hi@babilim.org

= I found a wrong translation. =

Use **Report a problem** on the Babilim screen.
You choose a category and may add the text, a suggestion and a note.
Nothing is sent until you submit the form.

== External services ==

This plugin connects to the Babilim translation service, a hosted API, to look up, request and download translation files for the plugins and themes installed on your site.
It cannot work without this connection.

Service: Babilim, at `https://api.babilim.org`.
This is the value of the `BABILIM_API_BASE` constant defined in the plugin's main file, and the only address the plugin itself ever sends requests to.
The one other address involved is the checkout page described under "Checkout" below, which opens in your browser and is not contacted by the plugin.

* Terms of Service: https://babilim.org/terms/
* Privacy Policy: https://babilim.org/privacy/
* Refund Policy: https://babilim.org/refund/

Checkout: when you click "Upgrade this site", the Babilim checkout page at babilim.org opens in a new tab and loads Paddle's payment script there.
Nothing from Paddle is loaded inside your WordPress admin, and no payment data passes through this plugin.
Payment is processed by Paddle as merchant of record.

* Paddle Checkout Buyer Terms: https://www.paddle.com/legal/buyer-terms
* Paddle Privacy Policy: https://www.paddle.com/legal/privacy

**Nothing is sent before you consent.**
No request is made when the plugin is activated, and none until you press "Enable Babilim".
Once you have, requests are made when you use the plugin and on WordPress's own twice-daily scheduled check.
"Disable Babilim" stops them all.

**What is sent when you register the site and on every sync.**
Registration happens when you press "Enable Babilim".
A sync runs twice a day and whenever you press "Check again now".
Both send the items listed above the Enable button:

* `home_url`: your site's URL, so the service can confirm that you control the site. The service also calls back to a public endpoint of your own site, `/wp-json/babilim/v1/challenge`, to check a signed answer;
* `wp_version`, `php_version` and `plugin_version`: your WordPress version, your PHP version and this plugin's version, to serve compatible files;
* `site_locale` and `locales`: your site's configured locale, and every locale in use on the site (site locale, installed language packs, user locales and any you add by hand);
* `install_pub`: a public key generated for your site. Only the public half is ever sent; the private half stays in your database and is never shown or sent;
* `components`: for every installed plugin and theme, its slug, version, text domain(s), whether it is a WordPress.org listing, its own bundled locales, and which locales you have already installed a Babilim translation for;
* `client_events`: up to 20 pending diagnostic events (for example, a file that failed its checksum), each consisting only of a code, a component and a locale. No string content or translated text is included.

Every request also carries an `Authorization` header holding the site's access token, and a `User-Agent` of the form `Babilim/<plugin version>; <site URL>`.
The service sees your server's IP address as part of any web request.

**Automatic downloads during the sync.**
While "Install available translations automatically" is on (it is on by default; you can turn it off under Settings), the twice-daily sync downloads available translations for the languages you manage without a click.
Each download sends the identifier of the build.

* The Babilim service decides which available translations may be installed automatically. On the free plan it normally only allows plugins and themes that already have a Babilim translation installed on this site, so that automatic installs do not take up a new free slot.
* Translations you remove yourself are not installed again automatically until you download them by hand.
* "Remove everything Babilim installed" also turns automatic installation off. Turning it on again later can bring translations back.
* On a multisite network, new translations are not installed automatically at all. Translations already installed are still updated.

**What is sent only when you take an action.**

* Requesting a translation sends the component kind, its slug and version, and the locale.
* Downloading a translation sends the identifier of the build you chose.
* Reading the list of locales sends no site data beyond the headers above.
* Reporting a problem from the Report translation entry in the admin toolbar sends the component, locale and build, the original text, the current translation, the category you chose, and any suggested translation or note you fill in. It does not send the address of the screen you were on.
* The service processes every report, including your suggested translation and note, automatically with AI models.
* Reporting a problem with the older form on the Translations screen sends the component, locale and build, the category you chose, and the current translation, suggested translation, note and screen address you type. That form does not carry the original string.

**Uploading a component's source (only after you confirm).**
For a plugin or theme that is not listed on WordPress.org, requesting a translation needs the component's source.
The service asks for it and the plugin shows a confirmation screen first. Nothing is uploaded until you press "Upload and request".

If you do:

* Babilim will upload these files from the component: every .php and .js file, every block.json file, and the readme.txt, readme.md, style.css and theme.json in its main folder. They are packaged as a zip and uploaded in parts. They are used to find the text that needs translating and to identify the plugin or theme.
* Files with other names or extensions are not included, nor are the uploads, cache, logs, backup, data, vendor, node_modules and languages folders, hidden files, or files whose names match common secrets (wp-config*, .env*, *.sql, *.log, *.pem, *.key).
* Code files can still contain text or data you would not want to share; check them if you are unsure. The service removes the same kinds of files again on arrival.
* The upload is kept by the service until you ask for it to be deleted. It is stored by its hosting provider and processed by AI models.
* The original text and the translations made from it are provided to other sites that install the same version of the component.
* By uploading you confirm you have the right to do so.

The temporary zip:

* It is written only to the system temporary directory, or to the folder named by the BABILIM_TEMP_DIR constant, and only if that folder lies outside the website. If neither does, nothing is packaged or uploaded and the plugin tells you.
* The folder has a random name and holds nothing but the zip.
* The zip is deleted when the upload completes, fails or is cancelled. If the site is interrupted before that, the leftover sits in that temporary folder outside the website and is cleaned up by your host's own temporary-file housekeeping; Babilim does not promise a removal time.

**Error reports (optional, off by default).**
When something fails inside Babilim, the plugin can send an error report to the Babilim service so it can be published as a public issue on GitHub.
Nothing is sent until you choose "Share this report" or "Always share" in the notice that appears after an error, or tick "Share error reports" in Settings.

* What is sent: the plugin version, WordPress version, PHP version, the site language code, and for each event a random id, an error code, the action being performed, the place in the plugin where it happened, and the time. The notice that asks for your consent lists all of these.
* Every code, action and place comes from a fixed list; no free text is sent.
* Like every other request to the service, the report is sent as coming from your site: it carries the site access token and the User-Agent header with the site address. The service uses them only to accept the request.
* The service publishes only the error code and versions as a public issue. The public issue contains no site address, site name, content, user data, list of plugins and themes, or file path.
* The report is published publicly on GitHub and may be kept by third parties.

**What is received.**
Translation data files (`.po`, `.mo`, `.json`) for the locales you manage, together with status information: which translations exist, their build identifiers and dates, and any download limit that applies to your site.
Nothing that can be executed is ever received or run.

The Translations screen shows the same list before you consent, and your consent can be withdrawn at any time with "Disable Babilim".

== Privacy ==

The information described under "External services" is the only information the plugin sends, and only after you consent.
The only exception is a source upload you explicitly confirm, described above.

Error reports are only sent after you choose to share them.

The plugin itself never reads or sends the content of your posts, pages, comments or media, user names or e-mail addresses, or your WordPress administrator account.
Two things you type or confirm can still carry such data if you put it there: the free-text fields of a report, and the code files of a component you choose to upload (see "Uploading a component's source" above).

The site access token and the private key are stored in your site's database and are never included in a page or script sent to a browser.
Deleting the plugin keeps them by default so that a reinstall can reuse the site's identity. Ticking "Delete all Babilim data on uninstall" removes them.

== Screenshots ==

1. The Translations screen before you enable Babilim: what will be sent, and the Enable button.
2. The Translations screen after enabling: each installed plugin and theme with its translation status per language.
3. Settings: the languages to manage and the uninstall choices.

== Changelog ==

= 1.1.0 =
* Optional error reports: when something fails inside Babilim, you can choose to share an error report so the error code can be published as a public issue. Off by default; nothing is sent until you agree.
* New "Report translation" entry in the admin toolbar: search the translations Babilim installed on your site, pick one and report a problem with it.
* Which available translations are installed automatically is now decided by the Babilim service and returned with the sync; the plugin no longer reads the license kind for this.
* A source upload now contains only the component's code and description files: its .php and .js files, its block.json files, and the readme, style.css and theme.json in its main folder.
* The temporary zip of a source upload is now only written to a folder confirmed to lie outside the website; when none is available the upload is refused with a message.

= 1.0.1 =
* The Translations screen shows "Original language" for a language a plugin or theme is originally written in, instead of "Not supported".
* A language that a plugin or theme already ships a translation for can now also be requested from Babilim.
* Bundled translations are now also detected in a plugin's `languages/`, `locale/`, `lang/` and `i18n/languages/` folders when it declares no Domain Path, for every text domain the component ships, and in language packs a vendor installed under `wp-content/languages/`.
* When the service asks the site to wait, the follow-up check is scheduled for the end of the wait instead of being dropped.
* Networks are scanned in pages, and very large networks are left out of the shared index rebuild.
* The readme was rewritten in shorter sentences and lists.

= 1.0.0 =
* First release.
* Consent-first registration and twice-daily sync with the Babilim translation service.
* Layered translation loading: your `custom/` overrides, then Babilim, then WordPress.org language packs or bundled translations; WordPress core's own translation first for the `default` text domain.
* Install, check, roll back, remove and report actions for each translation, and automatic installation of newer builds of translations you already installed, during the twice-daily sync.
* Automatic installation of available translations for the languages you manage during the twice-daily sync, on by default and switchable in Settings. On the free plan only components that already use a free slot are installed this way, and translations you removed yourself are not installed again.
* A short notice on the Translations screen says how many translations were installed automatically. "Remove everything Babilim installed" turns automatic installation off. A multisite network does not install new translations automatically.
* Removal of settings, scheduled events and language files on uninstall, with your `custom/` overrides and your site's identity kept by default.

== Upgrade Notice ==

= 1.1.0 =
Adds an optional "Report translation" toolbar entry, optional error reports and a smaller source upload. The reports are off until you use or enable them; no action needed.

= 1.0.1 =
Better detection of translations your plugins already ship; no action needed.

= 1.0.0 =
First release.
