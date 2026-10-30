# Archived: core translation-update integration

This directory keeps, as a record, the first way Babilim delivered language
files: by plugging into WordPress core's own translation update machinery.
That code is no longer part of the plugin. It is kept here (and under the git
tag `archive/core-translation-updater`, which points at the last commit that
still shipped it) in case a future, officially supported route into the core
translation flow makes it useful again.

Nothing in this directory is loaded, built, packaged, type-checked or run by
the test suite. The files are verbatim copies; the class and file names in
them refer to the layout they had before removal.

## What is here

| Path | What it is |
| --- | --- |
| `src/class-babilim-updater.php` | The integration itself. |
| `src/class-babilim-updater-contract.php` | A small interface the plugin's main class used so tests could inject a recording double. |
| `tests/test-updater.php` | Unit tests for the integration. |
| `tests/test-real-core-bulk-upgrade.php` | Tests that ran WordPress core's own `Language_Pack_Upgrader::bulk_upgrade()` (pinned core files, not a stand-in) against the plugin's hooks. |
| `playground/b1-upgrader-adopt.php`, `playground/b2-two-offers.php` | The two WordPress Playground checks that drove a real core translation update end to end. |

## The design

The service reports, per site, which language builds are newer than what is
installed. The integration exposed that list to core as if it were a wp.org
language pack, and let core's own upgrader do the downloading:

1. **Offer.** Filters on `site_transient_update_plugins` and
   `site_transient_update_themes` appended one `translations[]` entry per
   pending build (the property core's `wp_get_translation_updates()` reads).
   Each entry carried a `babilim` marker and a `package` URL under a dedicated
   marker path on the API base. Entries already present (real wp.org packs)
   were left untouched, and the marker (never slug or locale) was the only way
   an entry was recognised as ours.
2. **Download.** `upgrader_pre_download` short-circuited the fetch for our
   entries only, still going through core's `download_url()`. This skips the
   wp.org package-signature check, which cannot verify a package signed by a
   different service.
3. **Take over the install.** `upgrader_source_selection` (added per item,
   inside the pre-download hook) read the unzipped files from core's working
   directory, staged them flat under `_adopt/{token}`, and called
   `Babilim_Installer::adopt()` so every file was checked (sha256, entry
   count, shape) before anything was served. It then returned an empty
   directory so core copied nothing into `WP_LANG_DIR`.
4. **Protect wp.org packs.** `upgrader_package_options` redirected the
   destination to a fresh empty directory under `_incoming/{token}`, because
   `Language_Pack_Upgrader::clear_destination()` deletes
   `{slug}-{locale}.po/.mo/.l10n.php` in the real destination before the copy.
   Without the redirect, every successful Babilim update deleted a real wp.org
   pack for the same slug and locale.
5. **Bookkeeping.** `upgrader_install_package_result` removed the offered
   build from the pending list once the whole install succeeded and cleaned up
   the incoming directory; a daily sweep removed leftovers.

## Behaviour verified on real WordPress, and traps found

- `Language_Pack_Upgrader::bulk_upgrade()` removes every filter already
  registered on `upgrader_post_install` and `upgrader_source_selection` before
  its per-item loop. A callback registered once at plugin load is gone by the
  time an item is processed. The source-selection hook therefore had to be
  added per item from inside `upgrader_pre_download`, at priority 20 (after
  core's own `check_package()` at 10).
- The destination redirect in step 4 was found by a real-core probe, not by
  reading code; a stand-in for core had hidden it.
- A mixed batch (one wp.org item, one of ours) and a pre-existing wp.org pack
  for the same slug and locale both behaved correctly (Playground checks b1
  and b2).
- A batch shares one manifest instance across items; separately built
  installers each read their own copy and overwrote each other's records.
  The plugin now builds one shared manifest per request.
- Core's background auto-updater reaches the same hooks, so the offer had to
  be removed from the transients on uninstall, or core would keep trying to
  fetch packages from a plugin that is gone.

## Why it was removed

The WordPress.org Plugin Check reports the error `plugin_updater_detected` for
any plugin that filters `update_plugins`/`update_themes` transients or hooks
the upgrader's package options. The check is a plain pattern match on those
hook names, so it fires on this integration even though it delivers
translations and never replaces plugin code. That error blocks an upload to
the plugin directory. Language files are now installed by the plugin's own
scheduled sync (the same installer, manifest lock, integrity checks and
rollback), with no core update hooks involved.

## Where to reconnect if an official route appears

- **Offer:** the two `inject_*_transient()` methods are the whole offer side;
  everything they read comes from the pending-updates list the sync already
  stores.
- **Install:** `source_selection()` is the only place that hands bytes to the
  installer, through `Babilim_Installer::adopt()`. That entry point still
  exists in the plugin for exactly this reason.
- **Keep** the destination redirect and the per-item hook registration if
  core's upgrader is used again; both are needed for correctness (see above).
- A supported route would probably replace the download short-circuit with
  proper package signing, which would also remove the need for the
  pre-download hook.
