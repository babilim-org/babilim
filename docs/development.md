# Development

This is the public wp.org plugin half of Babilim. The companion service
(translation pipeline + hosted API) lives in a separate, private repo; this
repo only ever talks to it over the versioned HTTP contract described in
`docs/api-contract.md`.

## Layout

```
babilim.php            plugin main file -- header, constants, autoloader
uninstall.php           uninstall guard (placeholder -- nothing to clean up yet)
LICENSE               GPL-2.0-or-later, full text
readme.txt             wp.org plugin directory listing (source of truth for
                        Requires/Tested/Stable/License headers + the
                        External Services disclosure)
includes/
  class-babilim.php             plugins_loaded-time assembly point (empty so far)
  class-babilim-loader.php       Babilim_Loader::register(), called at babilim.php's
                                  top level; the loading layer's actual hooks land later
  class-babilim-paths.php        Babilim_Paths -- the WP_LANG_DIR/babilim/ directory
                                  layout, file-naming helpers, and the is_allowed()/
                                  is_safe_name()/is_safe_locale() path allow-list
  class-babilim-fs.php           Babilim_Fs -- the filesystem interface
  class-babilim-wp-fs.php         Babilim_Wp_Fs -- the real, WP_Filesystem-backed
                                  implementation, guarded by Babilim_Paths::is_allowed()
  class-babilim-manifest.php      Babilim_Manifest -- reads/writes _manifest.json (which
                                  build installed each file, its default-domain-supplement
                                  contributions, and the per-locale merged default index)
phpstan-stubs/
  wp-runtime-constants.stub.php  WordPress runtime constants (WP_LANG_DIR) the plugin's
                                  own code references, scanned only -- see phpstan.neon
docs/
  api-contract.md       plain-English summary of the API contract
  development.md         this file
tests/
  bootstrap.php          PHPUnit bootstrap -- WordPress function/constant stand-ins
  phpunit.xml             PHPUnit config
  phpstan.neon             PHPStan config for tests/ specifically, see that file's comment
  phpstan-stubs/           symbols PHPStan needs that aren't Composer-resolvable here
  fixtures/
    class-babilim-memory-fs.php  Babilim_Memory_Fs -- in-memory Babilim_Fs test double,
                                  enforces Babilim_Paths::is_allowed() like the real one;
                                  lives under tests/ so it's never packaged (see .distignore)
  unit/                    the PHPUnit test suite
  contract/
    PINNED               pinned contract version + hash this repo expects
    check_pinned.sh       verifies tests/contract/v1/ matches PINNED
    v1/                   pinned COPY of the service repo's contract/v1/
                           -- see "The contract copy" below
scripts/
  public-rules.sh          the shared rule engine behind the three checks below
  public-check.sh          public-repo discipline guard (working tree), see CLAUDE.md
  public-history-check.sh   same rules over the full commit history -- expected to fail
                             until the pre-launch history rewrite, see CLAUDE.md
  commit-msg-check.sh       same rules applied to a commit message; the actual
                             body of both opt-in hooks below
  fetch-tool.sh             SHA256-verified download helper used by `make tools`
  build-zip.php             builds the release zip, used by `make zip`
.githooks/
  prepare-commit-msg       records where the message came from (exec's
                            commit-msg-check.sh record-source); never blocks a commit
  commit-msg               the checking hook (exec's commit-msg-check.sh); both are
                            inactive until `make install-hooks` or `git config
                            core.hooksPath .githooks`
phpstan.neon            PHPStan config for the plugin's own PHP (babilim.php,
                         uninstall.php, includes/, scripts/build-zip.php)
Makefile                  top-level developer targets
tools/                   gitignored -- the pinned dev toolchain `make tools` fetches
```

The plugin's own PHP source (`babilim.php`, `uninstall.php`, `includes/`)
and its PHPUnit suite (`tests/bootstrap.php`, `tests/unit/`) landed with the
first implementation task. WP Playground blueprints are still to come.

## Running the plugin locally

- `make tools` once, to fetch the pinned dev toolchain into `tools/`
  (gitignored; see the Makefile for exact versions and SHA256s).
- `make test` runs the PHPUnit suite. No WordPress install is required --
  `tests/bootstrap.php` stubs the handful of WordPress functions/constants
  the plugin's own code calls (see CLAUDE.md's Testing rules).
- `make stan` runs PHPStan over the plugin's PHP (`phpstan.neon`) and,
  separately, over `tests/` (`tests/phpstan.neon` -- kept apart because
  PHPUnit ships here only as a phar with no Composer classmap PHPStan can
  resolve `PHPUnit\Framework\TestCase` against; see that file's own
  comment for how it's stubbed instead).
- `make pot` regenerates `languages/babilim.pot` via wp-cli's
  `i18n make-pot`; the file is committed, so run it (and commit the
  result) whenever a translatable string changes. `make pot-check`
  fails when the committed file no longer matches the code.
- `make zip` builds `build/babilim-<version>.zip` from git's own
  tracked-file list (so it can never ship something uncommitted), filtered
  through `.distignore`, using PHP's `ZipArchive` rather than an external
  `zip` binary. It refuses to build if `readme.txt`'s `Stable tag`,
  `babilim.php`'s `Version:` header, and its `BABILIM_VERSION` constant
  don't all agree.

## Enabling and the first check

The Translations screen (`admin.php?page=babilim`) is the welcome block
until the site owner presses "Enable Babilim". That one button records
consent, then runs `Babilim_Site::sync( ENABLE_BUDGET_SECONDS )`
(registration, verification and the inventory, 45 seconds in total).
A run that is out of time returns `in_progress`: the handler schedules
one `babilim_sync_once` event and calls `spawn_cron()`, and the screen
shows an informational notice with a "Check again" button.

What the screen shows follows from stored state only: consent, the
`fetched_at` of the last completed check, and the last sync error
(`babilim_sync_last_error`, `{code, message, since, at}`; `since` is
set only when no record exists, so it marks the first failure of an
unfinished streak). With consent and neither `fetched_at` nor a
recorded error, loading the screen runs one `RESUME_BUDGET_SECONDS`
check. A check that finishes with nothing to send (no managed locale)
also writes `fetched_at`, so such a site is not checked on every load.
A recorded error is never retried by a page load; "Check again now"
(`babilim_sync_restart`) deletes the record and runs a new check.

The settings page (`admin.php?page=babilim-settings`) has two tabs,
`tab=general` (the default; any other value is General) and
`tab=advanced`. General is one form (`babilim-settings-form`,
`babilim_save_settings`) holding the managed languages, the uninstall
choices and the account block; its "Disable Babilim" button belongs to a
second, separate form through its `form` attribute, because forms cannot
be nested. Unchecking a detected language adds it to
`babilim_disabled_locales` (checking it removes it again; files already
installed are kept), and `Babilim_Inventory::locales()` is the detected
and manual languages minus that list. The "Add another language" menu is
the service's locale list, which is only requested after consent, so
before consent there is no menu. Advanced holds "Check again now"
(`babilim_sync_now`, a POST form; it syncs with `RESUME_BUDGET_SECONDS`
and schedules the background check when that runs out of time), the
index and sweep buttons and the danger zone.

Activating, deactivating, updating or switching a plugin or theme
schedules one `babilim_sync_once` event about 30 seconds ahead
(`Babilim::schedule_sync_once()`): nothing without consent, never a
second while one is pending. The event only runs the inventory sync; it
installs nothing.

The supported size follows from the batch limit: one run sends at most
`MAX_SYNC_BATCHES` (7) requests, each holding at most
`min(500, floor(20000 / L))` components for `L` managed languages, so
up to 7 x 500 = 3500 components when `L` is 40 or less. Measured times
for a single batch are recorded in `docs/playground-results.md`.

## Known gaps

- **No CI yet.** `make test`/`make stan`/`make public-check`/
  `make contract-check` all run locally, but nothing runs them on push
  yet. A GitHub Actions workflow is planned (tracked separately) and
  should automate the two checks below, which have each now been run
  manually at least once but aren't gated on every change:
  - **PHP 7.4 syntax.** `phpstan.neon`'s `phpVersion: 70400` catches most
    but not all PHP 8-only syntax (nullsafe `?->`, for one, analyses
    clean under it). This repo's dev box only has PHP 8.3, so the actual
    gate is a Playground boot under `--php=7.4` (see
    `docs/playground-results.md`'s own PHP 7.4 section) -- run manually,
    not on every push.
  - **A real WordPress Plugin Check run.** `tests/unit/test-plugin-check.php`
    only ever simulates a subset of it. A real run now exists (see
    `docs/plugin-check-results.md`) but, like the PHP 7.4 gate above, is
    run manually, not gated on every push.

## The contract copy

`tests/contract/v1/` is a byte-for-byte pinned copy of the service repo's
`contract/v1/` (its canonical schemas, examples, and `SHA256SUMS`). Never
hand-edit anything under `tests/contract/v1/` — it's synced from the
service repo and its integrity is what `make contract-check` verifies.

To pick up a new contract version, see the "Local validation" section of
`docs/api-contract.md`.

## Commands

```
make contract-check        # tests/contract/v1/ still matches PINNED + its own SHA256SUMS
make public-check          # public-repo discipline guard (see CLAUDE.md), scans every tracked file
make public-history-check  # same, over full history -- currently red on purpose, see CLAUDE.md
make install-hooks         # opts this clone into the commit-message discipline hooks
make tools                 # fetches the pinned dev toolchain into tools/ (gitignored), verified by SHA256
make tools-verify          # re-checks every tools/* SHA256 without touching the network
make test                  # PHPUnit -- no WordPress install required, see CLAUDE.md's Testing rules
make stan                  # PHPStan over the plugin, then separately over tests/
make pot                   # regenerates the committed languages/babilim.pot
make pot-check             # fails if languages/babilim.pot is stale
make l10n L10N_SRC=<dir>   # rebuilds languages/babilim-<locale>.{po,mo,l10n.php} for each locale in languages/LOCALES
                           # (after `make pot` only moved line references, l10n-check says STALE (+0/-0) for every
                           # locale: run `make l10n L10N_SRC=languages` to rebuild from the committed .po files)
make zip                   # builds build/babilim-<version>.zip
```

`contract-check`/`public-check`/`public-history-check`/`install-hooks` are
pure shell — no network, no PHP/Python toolchain required
(`install-hooks` runs one `git config` command; nothing else here
touches your git config). `tools` is the one target that touches the
network, and only that one (see "Running the plugin locally" above);
everything else after it stays offline.

## WordPress official skills

When implementing plugin code, use the WordPress official agent skills
installed locally (see CLAUDE.md's "WordPress official skills" section
for which ones apply, the fallback when they're not available, and how
task reports must cite them).

## Language

Everything in this repo — code comments, this file, `readme.txt`,
`CLAUDE.md`, and commit messages — is English. See CLAUDE.md's
"Public-repo discipline" section for why, and `scripts/public-check.sh`
(rules in `scripts/public-rules.sh`) for the automated check.
