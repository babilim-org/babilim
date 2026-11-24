=== Babilim – Auto Translation & Language Packs ===
Contributors: babilim
Tags: translation, language pack, automatic translation, localization, i18n
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-translate every plugin and theme on your site, then fine-tune any line in a fast built-in editor. Your edits survive updates.

== Description ==

* See every plugin and theme on your site with its translation status in each language you manage.
* Install a translation with one click or automatically, or request one that does not exist yet.
* Edit any translation on your site. Your changes survive plugin, theme and translation updates.
* Bring your translations over from Loco Translate. The import runs on your site.
* Use any WordPress language. None is ranked, preferred or left out.
* Nothing is sent until you agree, not even when you activate the plugin.
* No API key needed.

Translations come from the Babilim translation service, which has a free plan and a one-time paid plan (see the FAQ). The editor and the Loco Translate import work without it.

Translation files are written only inside `wp-content/languages/babilim/`. Babilim does not touch your plugins, your themes or WordPress's own files.

== Installation ==

1. Install the plugin from the WordPress plugin directory, or upload it to `/wp-content/plugins/babilim`.
2. Activate it. Activation sends nothing.
3. Open **Babilim > Translations**, read what will be sent, and press **Enable Babilim** if you agree.
4. Install or request translations on that screen. Choose languages under **Babilim > Settings**, and edit under **Babilim > Edit translations**.

== Frequently Asked Questions ==

= What is sent, and when? =

Nothing until you press "Enable Babilim", not even on activation. After that, Babilim sends your site's URL, versions, languages and list of plugins and themes. Anything more is sent only when you act, or when you turn on error reports or edit sharing. "External services" below lists it all, and "Disable Babilim" in Settings stops every request.

= Where do the translations come from? =

The Babilim service makes them with AI and makes them available to eligible sites using the same plugin or theme version. The plugin downloads translation files (`.po`, `.mo`, `.json`) and never runs remote code.

= Is it free? =

* The free plan covers one theme and two plugins listed on WordPress.org per site, chosen with "Request translation". A request counts even if the translation already exists.
* A covered plugin or theme gets its later versions at no extra charge. If it leaves WordPress.org, it gets no new versions on the free plan; installed translations stay.
* The paid plan is a one-time purchase per site and covers every plugin and theme. Prices: https://babilim.org/pricing/
* The editor needs no plan, and free and paid sites install and update translations the same way.

= How do I change a translation, and which one wins? =

Open **Babilim > Edit translations**, pick a plugin or theme and a language, edit, and press Save. After an update your changes move to the new version, and lines whose original changed wait under "Needs review". For a WordPress.org plugin or theme with no translation template, enable Babilim and press "Get the text list from Babilim".

Your own changes come first: the ones you make in the editor, and files you place in `wp-content/languages/babilim/custom/` (imported into the editor and backed up in `custom/_backup/`). They beat Babilim, which beats a WordPress.org language pack or the plugin's or theme's bundled translation. For WordPress core's own `default` text domain, core's official translation beats Babilim's.

= What happens when I deactivate or delete the plugin? =

Deactivating stops the scheduled checks and leaves the installed files in place. Deleting removes Babilim's settings and files but by default keeps your edits, your `custom/` files and the site's registration, so a reinstall picks up where you left off.

= Where do I get help or report a wrong translation? =

Use **Report translation** in the admin toolbar or **Report a problem** on the Translations screen. Questions: https://wordpress.org/support/plugin/babilim/ . Bugs: https://github.com/babilim-org/babilim/issues . License or billing: support@babilim.org

== External services ==

This plugin connects to the Babilim translation service, `https://api.babilim.org`, to find, request and download translation files. The editor and the Loco Translate import work without it.

* When: **Nothing is sent before you consent.** No request is made when the plugin is activated, and none until you press "Enable Babilim". After that: when you use the plugin, on WordPress's twice-daily check, and when plugins, themes or your plan change, until you press "Disable Babilim".
* Sent automatically: your site's URL, access token and public key; the WordPress, PHP and Babilim versions; your site's languages; each plugin and theme with its version, text domain, WordPress.org listing, included languages (and which Babilim made) and installed Babilim translation; short diagnostic codes, never text. The service also sees your server's IP address.
* Only when you act: a translation request (plugin or theme, version, language); a text list request (plugin or theme, version); a problem report (what you fill in or select); a restore code; and, after you confirm, a zip of the code files of a plugin or theme not on WordPress.org. Check them first.
* Optional (off by default): error reports you share once or turn on (what failed, where and when, a random id, versions, site language; the error, where it happened and the versions become a public GitHub issue) and "Share my edits with Babilim" (each change to a Babilim translation: original, Babilim's translation, yours, plugin or theme, language, build).
* Uploads, reports and shared edits are stored and processed with AI models.
* Received: translation files, text lists and status.
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
4. The translation editor: search a plugin's or theme's strings and save your own wording, with the original and its placeholders kept intact.

== Changelog ==

= 2.2.0 =
* New: import your custom translations from Loco Translate.
* New: optionally share your edits with Babilim.
* New: for a WordPress.org plugin or theme that ships no translation template, enable Babilim, then get its text list and edit it.

= 2.1.0 =
* New editor layout: the string list on top and the editing area below, each scrolling on its own.
* Edit several strings, then press Save once. Until then every edit is kept as a draft.
* Problems show while you edit; a draft with a problem is not published.
* New filters, a wider search and sorting.
* After an update your changes move to the new version; changed originals wait under "Needs review".
* New name: Babilim – Auto Translation & Language Packs.

= 2.0.0 =
* New: edit any plugin or theme translation under Babilim > Edit translations.
* Your changes are checked before saving and stay in place through updates.
* The editor works without enabling the Babilim service.

= 1.3.0 =
* Actions finish on the same page.
* Confirmations and source uploads open in a dialog.
* Every screen still works without JavaScript.

Older versions are listed in changelog.txt.

== Upgrade Notice ==

= 2.2.0 =
Adds Loco Translate import, optional edit sharing and text lists for plugins and themes without a template. No action needed.

= 2.1.0 =
New editor layout with drafts and one Save button; no action needed.

= 2.0.0 =
Adds a translation editor; no action needed.

= 1.3.0 =
Actions finish on the same page and confirmations open in a dialog; no action needed.
