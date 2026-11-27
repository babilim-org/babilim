=== Babilim – Auto Translation & Language Packs ===
Contributors: babilim
Tags: translation, language pack, automatic translation, localization, i18n
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-translate every plugin and theme on your site, then fine-tune any line in a fast built-in editor. Your edits survive updates.

== Description ==

Your site is in your language, but some plugins and themes still show up in English? Babilim finds every plugin and theme that is missing a translation in the languages you use, and fills the gap.

= See what's missing at a glance =

One screen lists every plugin and theme on your site, with its translation status in each language you manage. No more guessing which screens will stay untranslated.

= Install with one click, or let it run on its own =

Install a translation with one click, or turn on automatic installs. If a translation doesn't exist yet, request it and Babilim makes one.

= Built for software, not just sent to an AI model =

Many translation plugins ask for your own AI API key, send each string to a general-purpose model and save whatever comes back. Babilim's translation service is built for plugin and theme strings:

* It follows WordPress's own approved terminology in each language.
* It keeps every term consistent across the whole plugin or theme.
* It checks that placeholders, HTML tags and plural forms come through intact.
* Each line is checked against the meaning of the original, not just its format.
* A line that still fails is retried or left out, never shipped broken.

No API key, no per-word bills.

= Change any line yourself =

Search a plugin's or theme's strings and rewrite them in the built-in editor. The original and its placeholders stay intact, and **your edits survive plugin, theme and translation updates.**

= Bring your Loco Translate work with you =

Already translated with Loco Translate? Import those translations in a few clicks. The import runs on your site.

= Nothing leaves your site until you agree =

Activating the plugin sends nothing. Before you press Enable, you see exactly what will be shared. **No API key, no account to set up.**

= Your plugins and themes stay untouched =

Translation files go only into `wp-content/languages/babilim/`. Babilim never changes your plugins, your themes or WordPress's own files.

= Every WordPress language, treated equally =

Use any language WordPress supports. None is ranked, preferred or left out.

Translations come from the Babilim translation service, which has a **free plan** and a **one-time paid plan** (see the FAQ). The editor and the Loco Translate import work without it.

== Installation ==

1. Install the plugin from the WordPress plugin directory, or upload it to `/wp-content/plugins/babilim`.
2. Activate it. Activation sends nothing.
3. Open **Babilim > Translations**, read what will be sent, and press **Enable Babilim** if you agree.
4. Install or request translations on that screen. Choose languages under **Babilim > Settings**, and edit under **Babilim > Edit translations**.

== Frequently Asked Questions ==

= What is sent, and when? =

Nothing until you press "Enable Babilim", not even on activation. After that, Babilim sends your site's URL, versions, languages and list of plugins and themes. Anything more is sent only when you act, or when you turn on error reports or edit sharing. "External services" below has the details, and "Disable Babilim" in Settings stops every request.

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

Babilim connects to its translation service, `https://api.babilim.org`, to find and download translations. Nothing is sent until you press "Enable Babilim". After that, the plugin shares your site's URL, its WordPress, PHP and Babilim versions, its languages, and the list of installed plugins and themes. Problem and error reports, shared edits, and code of a plugin or theme not on WordPress.org are sent only when you choose to, and are processed with AI models. No visitor data is collected. Payments are handled by Paddle on babilim.org.

Terms of Service: https://babilim.org/terms/
Privacy Policy: https://babilim.org/privacy/
Paddle Privacy Policy: https://www.paddle.com/legal/privacy

== Screenshots ==

1. Before and after: the same admin screens, first in English, then translated by Babilim.
2. Every plugin and theme on your site, with its translation status in each language.
3. Change any line in the built-in editor. The original and its placeholders stay intact.
4. Bring your Loco Translate translations over in a few clicks.
5. Nothing is sent until you press Enable. You see exactly what will be shared first.

== Changelog ==

= 2.2.2 =
* The translation editor now opens large plugins such as WooCommerce on sites with a 256 MB memory limit.

= 2.2.1 =
* Saving drafts on large plugins no longer holds up other Babilim tasks.
* Safer Loco Translate imports with many files.

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

Older versions are listed in changelog.txt.

== Upgrade Notice ==

= 2.2.2 =
Improves memory use when editing translations for large plugins and themes. No action needed.

= 2.2.1 =
Performance and reliability fixes. No action needed.

= 2.2.0 =
Adds Loco Translate import, optional edit sharing and text lists for plugins and themes without a template. No action needed.

= 2.1.0 =
New editor layout with drafts and one Save button; no action needed.

= 2.0.0 =
Adds a translation editor; no action needed.
