![Babilim](.wordpress-org/banner-1544x500.png)

# ![](.wordpress-org/icon-128x128.png) Babilim

Fills in missing translations for the plugins and themes already installed on your site.

Babilim is a WordPress plugin. It looks at what is installed, finds the plugins and themes that have no translation for your site's language, and lets you install or request one from the Babilim translation service.

## Install

1. Upload the plugin to `/wp-content/plugins/babilim`, or install it from the WordPress plugin directory: https://wordpress.org/plugins/babilim/
2. Activate it on the Plugins screen. Activation sends nothing.
3. Open **Babilim > Translations**, read the list of information that will be sent, and press **Enable Babilim** if you agree.
4. Components with a missing translation appear on the Translations screen. Install or request a translation there. Choose the languages to manage under **Babilim > Settings**.

Requires WordPress 6.6 or later and PHP 7.4 or later.

## Why "Babilim"

**Babilim: from Babel, a gate**

In the old story, people who shared one language gathered on a plain and set out to build a tower that would reach the sky. Then their speech was confused, they could no longer understand one another, the work stopped, and they scattered across the earth. Language has stood between people ever since.

The city's name carries two meanings. In ancient Akkadian it was Bāb-ilim, "gate of the gods", the name that later became Babylon. Yet those who passed down the tower story heard it as a word for confusion, and a name that once meant a gate came to stand for a world divided by language. Babilim uses the name in its first meaning: a gate, not a wall.

Babilim exists to open that gate in the wall. A WordPress site runs dozens of plugins and themes, and when even one of them hasn't been translated, the site suddenly switches to a foreign language at that point. Babilim finds those gaps and fills them automatically, so the whole site speaks your language — without waiting for someone to translate it, and without doing it yourself.

To be honest about the price: every translated string comes with an AI bill, and that is what our fees pay for. We charge so we can keep doing this, not to turn language into a business.

Language should not be a barrier to using the web.

## How it works

* Before you agree, the plugin makes no request at all, not even on activation.
* After you enable it, it lists your installed plugins and themes and the languages in use on the site.
* The Translations screen shows each component's status per language. A translation that exists can be installed with one click. One that does not exist yet can be requested. If a component ships its own translation, you can still request a Babilim translation for it.
* Loading order for a plugin's or theme's own text domain: your override in `custom/` first, then a translation Babilim provides, then an official WordPress.org language pack or the component's own bundled translation.
* For WordPress core's `default` text domain, core's official translation always comes first, and Babilim only supplies strings core does not have.
* Babilim downloads translation data only (`.po`, `.mo`, `.json`). It never downloads or runs remote PHP or JavaScript.
* It writes only inside `wp-content/languages/babilim/`, apart from a temporary zip for a source upload you confirm.

Every WordPress language is supported on equal footing. The language list comes from the WordPress.org locale registry.

## How we translate

**Every string goes through a full process.**

Babilim doesn't just hand strings to an AI. Before translating anything, it works out what the plugin does, who uses it and what tone it should take, and gathers the vocabulary of its field — in a help desk, for example, "Open" is a ticket status, not the verb "to open".

- **Speaks WordPress's language**: terms follow the wording the WordPress translation community has already approved, and each term is translated one way across the whole site.
- **Never breaks your site**: placeholders, markup and plural rules are protected before translation. If a single one is wrong, the language pack is not released.
- **A second pair of eyes**: strings likely to go wrong are always checked again by a separate AI, and the rest are spot-checked. Wherever human translations exist, each language pack is also compared with a part of them the system has never seen.
- **Keeps what should stay**: product and brand names stay as written, and where an official or human translation exists, the human one comes first.
- **Gets better with use**: when someone reports a weak translation, it is re-examined automatically and the language pack is rebuilt if needed, so every site using that plugin gets the update.

## Pricing

* Each site domain can use the service for one theme and two plugins listed on WordPress.org at no charge.
* Components not listed on WordPress.org, and anything beyond that allowance, need a one-time license for each site domain.
* The current price is shown on the Settings screen. Payment is handled by Paddle, the merchant of record. Refunds: https://babilim.org/refund/
* The allowance is decided by the service and may change. The plugin contains no limit logic of its own and only shows what the service reports.
* Translation files you already downloaded keep working regardless of the license.

## Screenshots

![The Translations screen before you enable Babilim](.wordpress-org/screenshot-1.png)

1. The Translations screen before you enable Babilim: what will be sent, and the Enable button.

![The Translations screen after enabling](.wordpress-org/screenshot-2.png)

2. The Translations screen after enabling: each installed plugin and theme with its translation status per language.

![Settings](.wordpress-org/screenshot-3.png)

3. Settings: the languages to manage and the uninstall choices.

## FAQ

### Does the plugin contact any server before I agree?

No. Nothing is sent until you press Enable Babilim.

### What is sent?

The plugin sends your site URL, your WordPress, PHP and plugin versions, your locales, a public key generated for your site, a list of installed plugins and themes, and up to 20 pending diagnostic events. The full list is shown above the Enable button and in the plugin's directory page.

### Does it change my site's files?

No. It writes translation data files into `wp-content/languages/babilim/`, plus a temporary zip only for a source upload you confirm. It does not touch your plugins' or themes' files.

### Which languages can I use?

Every locale WordPress supports.

### What if the service is unreachable?

Installed translations keep working because they are ordinary local files. The plugin retries later.

The full FAQ is on the plugin page: https://wordpress.org/plugins/babilim/

## Reporting a problem

* A wrong translation: use **Report a problem** inside the plugin.
* A bug or a feature request: open an issue at https://github.com/babilim-org/babilim/issues (there are templates).
* General questions: the support forum at https://wordpress.org/support/plugin/babilim/
* Security, license or billing matters: write to hi@babilim.org

## Links

* Terms of Service: https://babilim.org/terms/
* Privacy Policy: https://babilim.org/privacy/
* Refund Policy: https://babilim.org/refund/

## License

GPL-2.0-or-later. See `LICENSE`.

## Development

See `docs/development.md`.
