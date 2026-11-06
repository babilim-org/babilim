# WordPress Plugin Check results

The official wordpress.org **Plugin Check** plugin (2.1.0), run for real
against this plugin's own shipped source (`babilim.php`, `includes/`,
`admin/`, `languages/`, `uninstall.php`, `readme.txt` -- nothing else).

Plugin Check running inside a WordPress Playground WASM boot, one check
at a time, has its own false negatives (see "WASM Playground: not used
for phpcs-based checks" below) -- **every phpcs-based check in this
document is run directly, with Plugin Check's own bundled
`vendor/bin/phpcs` and each
check's own exact `standard`/`sniffs` arguments (extracted from Plugin
Check 2.1.0's own `get_args()` methods, one per `Abstract_PHP_
CodeSniffer_Check` subclass)**, against a plain copy of the plugin's
shipped files on disk -- no WordPress boot, no WASM, deterministic,
under a second per check. The handful of default checks that are not
phpcs-based at all (content/structure/runtime checks -- `code_
obfuscation`, `plugin_content`, `file_type`, `plugin_header_fields`,
`plugin_uninstall`, `no_unfiltered_uploads`, `trademarks`, `direct_
file_access`, `external_admin_menu_links`, `wp_functions_compatibility`,
`plugin_updater`, `plugin_readme`) can't be run this way and still come
from the earlier WASM-based pass -- see "Checks that reported clean"
below for which ones and why those specific results aren't suspected of
the same false-negative problem.

## How to reproduce (phpcs-based checks -- the primary method now)

```
php vendor/bin/phpcs --standard=<standard> --sniffs=<sniffs> \
    --runtime-set minimum_wp_version 6.6 [<check-specific runtime-set>] \
    --extensions=php --report=full <plugin-source-dir>
```

using Plugin Check's own `vendor/bin/phpcs` (bundled with the Plugin
Check plugin zip, not this repo's own dev-dependency phpcs -- the two
can disagree on sniff versions) and, for each check, the exact
`standard`/`sniffs` pair its own `get_args( Check_Result $result )`
method returns. `--runtime-set minimum_wp_version 6.6` is passed to
every one of them -- not a per-check choice, but something
`Abstract_PHP_CodeSniffer_Check::get_argv_defaults()` itself adds to
every phpcs-based check's own arguments, reading it from this plugin's
own `Requires at least` header (`readme.txt`/`babilim.php`, currently
`6.6`), so it is kept for all of them since it's what a real run
actually does. It is not what once produced a false pass on `prefixing`:
running that check with only `minimum_wp_version`, or with neither
runtime-set, stays clean even against a known violation. What silences
`PrefixAllGlobals` is a missing `--runtime-set prefixes babilim` (see
that check's own section below); with it, the same violation is
reported.

| check id | standard | sniffs | check-specific runtime-set |
|---|---|---|---|
| `plugin_review_phpcs` | `phpcs-rulesets/plugin-review.xml` (Plugin Check's own bundled ruleset -- the same one wp.org's own reviewers use) | (ruleset-defined) | -- |
| `direct_db_queries` | `WordPress` | `WordPress.DB.DirectDatabaseQuery` | -- |
| `direct_db` | `PluginCheck` | `PluginCheck.Security.DirectDB` | -- |
| `late_escaping` | `WordPress` | `WordPress.Security.EscapeOutput` | -- |
| `i18n_usage` | `WordPress` | `WordPress.WP.I18n` | `text_domain babilim` |
| `enqueued_scripts_in_footer` | `WordPress` | `WordPress.WP.EnqueuedResourceParameters` | -- |
| `performant_wp_query_params` | `WordPress,WordPressVIPMinimum` | `WordPress.DB.SlowDBQuery,WordPressVIPMinimum.Performance.WPQueryParams` | -- |
| `enqueued_resources` | `WordPress` | `WordPress.WP.EnqueuedResources` | -- |
| `setting_sanitization` | `PluginCheck` | `PluginCheck.CodeAnalysis.SettingSanitization` | -- |
| `write_file` | `PluginCheck` | `PluginCheck.CodeAnalysis.WriteFile` | -- |
| `prefixing` | `WordPress` | `WordPress.NamingConventions.PrefixAllGlobals` | `prefixes babilim` |
| `verify_nonce` | `PluginCheck` | `PluginCheck.Security.VerifyNonce` | -- |
| `safe_redirect` | `WordPress` | `WordPress.Security.SafeRedirect` | -- |
| `offloading_files` | `PluginCheck` | `PluginCheck.CodeAnalysis.EnqueuedResourceOffloading,PluginCheck.CodeAnalysis.Offloading` | -- |
| `ai_provider` | `PluginCheck` | `PluginCheck.CodeAnalysis.AIProvider` | -- |
| `minified_files` | `PluginCheck` | `Internal.Tokenizer.Exception` | -- |
| `localhost` | `PluginCheck` | `PluginCheck.CodeAnalysis.Localhost` | -- |
| `php_error_reporting` | `PluginCheck` | `PluginCheck.CodeAnalysis.PHPErrorReporting` | -- |

`minified_files` is the one phpcs-based check that cannot be reproduced
with the command above: it has no ordinary sniff, so
`--sniffs=Internal.Tokenizer.Exception` makes phpcs answer "No sniffs
were registered" (exit code 3) instead of scanning. It only runs inside
Plugin Check's own runner. `scripts/plugin-check-native.sh` replaces it
with a direct file scan: every `.js` and `.css` file is a finding if it
is named `*.min.*` or has a line over 1000 characters. The shipped
`.js` files are `admin/js/babilim-admin.js` and `admin/js/babilim-report.js`;
neither has such a line. (The scan does not look at PHP files: the only
hand-written PHP line over 1000 characters,
`admin/class-babilim-admin.php:39`, is an inline SVG data URI for the
menu icon; the generated `languages/*.l10n.php` files hold their
translations as one long array line by format.)

`prefixing`'s own `prefixes` value (`babilim`) comes from calling Plugin
Check's own `Prefix_Scanner` directly against this plugin's source, the
same way `Prefixing_Check::get_args()` itself derives it -- not a guess.

That's every `Abstract_PHP_CodeSniffer_Check` subclass in Plugin Check
2.1.0's default (`TYPE_STATIC`) set -- 18 of the 29 default checks. The
other 11 default checks (listed above) inherit from `Abstract_File_
Check` or `Abstract_Runtime_Check` instead; they don't run phpcs at all,
so there's no `standard`/`sniffs` pair to extract for them.

`plugin_review_phpcs`'s own ruleset is **not** a superset of the other
17 checks' sniffs -- confirmed directly by grepping `phpcs-rulesets/
plugin-review.xml` for `EscapeOutput`, `DirectDatabaseQuery`, and
`PluginCheck.Security.DirectDB`: none of the three appear in it. A real
`wp plugin check` run against all default checks together reports every
one of them independently; a line flagged by more than one check (see
`class-babilim-manifest.php`'s four lock-table lines below, flagged by
both `plugin_review_phpcs` and `direct_db` under different sniff names)
shows up more than once in that aggregate report. This document's
totals add every check's own count, matching that behavior, rather than
collapsing overlapping lines into one.

**One check reclassifies its own findings**: Plugin Check 2.1.0's own
`Prefixing_Check` class (`includes/Checker/Checks/Plugin_Repo/
Prefixing_Check.php` inside the Plugin Check plugin itself -- not part
of this repo) overrides `add_result_message_for_file()` to force every
finding it reports to `$error = false` (a WARNING, never an ERROR) at
severity 6, regardless of what the underlying `WordPress.
NamingConventions.PrefixAllGlobals` sniff itself reports (an ERROR).
This document's `prefixing` numbers are given as a
real `wp plugin check` run would actually show them -- WARNING -- not
as raw phpcs reports them. Confirmed this reclassification is specific
to `prefixing`: the other two checks in this document whose own class
also overrides `add_result_message_for_file()` (`late_escaping`,
`plugin_review_phpcs`) only add a documentation URL there, never
touching `$error`/`$severity` -- their own numbers below are unaffected
and already match raw phpcs directly.

## Before / after

"Before" is commit `099687b` (the last commit before any final-fix work
started on this plugin). "After" is this repository's current state.

| | Errors | Warnings |
|---|---|---|
| Before | 5 | 22 |
| After | 0 | 0 |

Per check (only the checks that found anything at either point --
every other one of the 18 phpcs-based checks was 0 errors/0 warnings
both before and after):

| check id | before | after |
|---|---|---|
| `plugin_review_phpcs` | 1 error, 15 warnings | 0, 0 |
| `direct_db_queries` | 0 errors, 2 warnings | 0, 0 |
| `direct_db` | 0 errors, 4 warnings | 0, 0 |
| `late_escaping` | 4 errors, 0 warnings | 0, 0 |
| `prefixing` | 0 errors, 1 warning | 0, 0 |
| **total** | **5 errors, 22 warnings** | **0, 0** |

### `plugin_review_phpcs`: 1 error, 15 warnings (before)

- `includes/class-babilim-wp-fs.php:143` -- 1 ERROR,
  `WordPress.WP.AlternativeFunctions.rename_rename` (native `rename()`
  in the manifest write path's `WP_Filesystem` `direct`-mode branch;
  see that method's own docblock for why `WP_Filesystem::move()` can't
  be substituted -- it deletes the destination before renaming the
  source into place, a real window with no destination file at all the
  manifest's cross-request-safe write can't tolerate).
- `includes/class-babilim-manifest.php:1366,1373,1376,1405` -- 4
  WARNINGS, `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` (the
  manifest lock's four SQL statements interpolating `{$table}`
  directly).
- `admin/class-babilim-admin.php:389,402,424,441,861` -- 11 WARNINGS,
  `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` /
  `.InputNotSanitized` (5 field-read helpers reading a superglobal on
  one line, unslashing/sanitizing on a later one) plus one
  `WordPress.Security.NonceVerification.Missing` at line 861 (the
  `locales[]` read -- the nonce is checked by `guard()` earlier in the
  same request, which PHPCS can't see across methods).

**Fixed:** the rename ERROR now carries an explicit `phpcs:ignore`
alongside its existing docblock explanation. The four manifest-lock
statements now pass the table name through `$wpdb->prepare()`'s own
`%i` identifier placeholder (WP 6.2+; this plugin's floor is 6.6)
instead of interpolating `{$table}` -- a real behavior change, not just
a suppression (see "Also fixed" below for the test-double fidelity fix
this required). The admin.php field helpers now inline `wp_unslash()`/
the sanitizer directly around the superglobal read in one statement;
the two deliberately-unsanitized raw-value helpers and the nonce-
covered `locales[]` read carry explicit, reasoned `phpcs:ignore`
comments instead of leaving the gap silent.

### `direct_db_queries`: 0 errors, 2 warnings (before)

- `includes/class-babilim.php:1362` (the same query is `get_users_
  locales()`, whose `$wpdb->get_col()` call is currently at line 1426
  in the file) -- `WordPress.DB.
  DirectDatabaseQuery.DirectQuery` and `.NoCaching`. A direct `$wpdb`
  query reading `wp_users.locale` for every user with a real value set,
  used to build the "which locales does this install's own admin team
  actually use" seed list the settings screen suggests -- there's no
  `WP_User_Query` equivalent for "distinct non-empty `locale` meta
  values across all users."
- **Not object-cached, deliberately** (per `get_users_locales()`'s own
  docblock): `locale` user-meta changes only when a person changes
  their own profile language, an infrequent, low-stakes write with no
  real staleness cost either way, and this method's only caller
  (`Babilim_Inventory::detected_locales()`, reached from at most one or
  two admin-screen renders per request) already queries it only a
  handful of times per request at most -- the invalidation an object
  cache would need (hooking every possible write to a `locale` meta key
  across every user on the site) would add more surface than the query
  it replaces ever costs.
- **Fixed:** added `// phpcs:ignore WordPress.DB.DirectDatabaseQuery.
  DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ...` with
  that reasoning, plus the docblock explaining why no core API covers
  this query and why caching it isn't worthwhile.

### `direct_db`: 0 errors, 4 warnings (before)

- `includes/class-babilim-manifest.php:1366,1373,1376,1405` -- the same
  four manifest-lock lines as `plugin_review_phpcs` above, flagged here
  under `PluginCheck.Security.DirectDB`'s own "unescaped parameter used
  in `$wpdb->query()`/`get_var()`" message instead of `WordPress.DB.
  PreparedSQL.InterpolatedNotPrepared` -- two different checks, same
  four lines, both legitimately reported in a real aggregate run.
- **Fixed:** by the same `%i` change described under `plugin_review_
  phpcs` above -- one code change, both checks' findings gone.

### `late_escaping`: 4 errors, 0 warnings (before)

- `includes/class-babilim-paths.php:98` -- exception message interpolating
  `{$type}` into `"Unknown Babilim path type: {$type}"`.
- `includes/class-babilim-wp-fs.php:49` -- exception message interpolating
  `{$path}` into `"Path not allowed: {$path}"`.
- `includes/class-babilim-manifest.php:535,536` -- exception message
  built from `__()` and a `(string)` cast.
- All four are `throw new \RuntimeException( ... )` (or similar)
  arguments -- `WordPress.Security.EscapeOutput`'s own sub-code for
  this shape is `ExceptionNotEscaped`, not the more commonly seen
  `OutputNotEscaped` (confirmed by this real phpcs run; the two are
  distinct sniff codes and only one suppresses the other).
- **Fixed:** each now carries `// phpcs:ignore WordPress.Security.
  EscapeOutput.ExceptionNotEscaped -- ...` with a reason (an exception
  message is never rendered as page output; it's caught, logged, or
  surfaced through `Babilim_Result`'s own escaping at the point it's
  finally displayed).
- **Separately, admin page rendering:** `admin/class-babilim-
  admin.php`'s `render()` used to `echo $this->render_current_page();`
  behind a blanket `phpcs:ignore WordPress.Security.EscapeOutput.
  OutputNotEscaped` -- not one of the four findings above (a bare
  `echo $variable` with no interpolated string literal isn't what
  `EscapeOutput` matches the same way), but the exact shape wp.org's
  manual review rejects on sight regardless of what the automated
  check catches. Fixed for real: the
  ignore comment is gone, and `render()` now runs the whole assembled
  page through `wp_kses( $this->render_current_page(), self::
  allowed_admin_html() )`, an explicit allow-list of every tag/
  attribute this settings page's own markup actually uses.

### `prefixing`: 0 errors, 1 warning (before)

- `includes/class-babilim-inventory.php:892` -- `apply_filters(
  'wpml_active_languages', null )`, in `multilingual_plugin_locales()`.
  `WordPress.NamingConventions.PrefixAllGlobals` (reclassified to
  WARNING severity 6 by Plugin Check's own `Prefixing_Check` -- see
  "How to reproduce" above) flags the un-prefixed hook name.
- This is WPML's own public filter (its documented API for reading its
  active-languages list), not a hook this plugin defines -- the name is
  fixed by WPML itself and can't be prefixed without breaking the
  integration.
- **Fixed:** an explicit, reasoned `// phpcs:ignore WordPress.
  NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ...`
  immediately above the call.
- This check is entirely missed without passing `--runtime-set prefixes
  babilim` -- `Prefixing_Check::get_args()` derives `prefixes` from
  Plugin Check's own `Prefix_Scanner` and passes it to phpcs at
  runtime; without it, `WordPress.NamingConventions.PrefixAllGlobals`
  has no prefix to check names against and silently reports nothing at
  all, on every version of this plugin's code. Confirmed directly:
  re-running the exact same sniff with no `--runtime-set prefixes` set
  produces zero findings on the same file that has one with it set.

## Checks that reported clean (WASM Playground run, WordPress 6.6, PHP 8.1)

The following are not phpcs-based (see "How to reproduce" above for
why), so they still come from the earlier per-check WASM Playground
pass, each verified individually (`--checks=<slug> --format=json`,
"Success: Checks complete. No errors found."): `code_obfuscation`,
`plugin_content`, `file_type`, `plugin_header_fields`, `plugin_
uninstall`, `no_unfiltered_uploads`, `trademarks`, `direct_file_access`,
`external_admin_menu_links`, `wp_functions_compatibility`.

These aren't suspected of the same WASM false-negative problem
described below: that problem was specific to `plugin_review_phpcs`
(the broadest, most memory-hungry single check) under WordPress
`latest`, and every result cited here was captured under `6.6` (the
version the false-negative comparison showed gave correct results).
Nothing plugin headers, readme structure, the uninstall routine, file
types, trademarks, upload filtering, admin-menu external links, or
core function-availability depends on has changed since -- nothing to
re-run these ten checks against.

## WASM Playground: not used for phpcs-based checks

Two environment issues, discovered while this document's numbers still
came entirely from Playground, are worth keeping on record:

- Plugin Check's own `wp plugin check babilim` (no `--checks=` flag, the
  plain default invocation) **crashes the Playground CLI's PHP WASM
  runtime** (`RuntimeError: unreachable`, thrown from deep inside
  `php.wasm` itself, not a catchable PHP error) as soon as more than one
  or two of its default checks run back to back in the same long-lived
  request -- reproduced repeatedly, independent of which specific checks
  are combined.
- **Which WordPress version is booted changes what Plugin Check finds --
  not just which functions exist, but whether real findings are
  reported at all.** The same check, the same plugin source, differing
  only in `preferredVersions.wp` (`latest` = 7.1.2, vs `6.6`): under
  `latest`, `plugin_review_phpcs` silently reported zero errors and zero
  warnings; under `6.6`, the exact same check on the exact same files
  reported real findings. Confirmed this wasn't about PHP version (7.4
  and 8.1 gave identical results at a fixed WordPress version) or
  anything in this plugin's own code changing between the two runs --
  purely the booted WordPress version. The likely cause: `latest` is a
  substantially heavier core to boot inside the WASM sandbox's fixed
  memory budget than `6.6`, leaving less headroom for PHPCS's own
  ruleset processing; rather than crashing hard (like the multi-check
  case above), it appears to silently truncate to an empty result.

Both are why this document's phpcs-based numbers no longer come from
Playground at all: Plugin Check's own bundled phpcs, run directly (see
above), sidesteps the WASM sandbox entirely -- no crash risk, no WP-
version-dependent memory pressure, deterministic, under a second per
check. Playground is still the right tool for `tests/playground/`'s own
suite (real hooks, a real database, a real HTTP round trip -- nothing
phpcs can exercise), and for the ten non-phpcs checks above, whose
result doesn't depend on running PHPCS at all.

`wp-cli.phar` itself (this repo's own pinned `tools/wp-cli.phar`,
SHA256-verified by `make tools-verify`) was mounted in and passed via
each `wp-cli` blueprint step's own `wpCliPath`, instead of letting
Playground fetch its own copy from its usual hosted download location
(that fetch returned HTTP 403 more than once across the many boots this
took -- pre-mounting a known-good phar sidesteps it entirely). Every
boot: PID recorded, torn down by that PID once its own result file
appeared, never `pkill -f`; one instance at a time throughout.

## 1.3.0 run (WordPress 6.6, PHP 8.1, Plugin Check 2.1.0)

All 17 reproducible phpcs-based checks from the table above, run with
Plugin Check's own bundled phpcs against a copy of the shipped files
(`babilim.php`, `includes/`, `admin/`, `languages/`, `uninstall.php`,
`readme.txt`): 0 errors, 0 warnings for every one
(`plugin_review_phpcs`, `direct_db_queries`, `direct_db`,
`late_escaping`, `i18n_usage`, `enqueued_scripts_in_footer`,
`performant_wp_query_params`, `enqueued_resources`,
`setting_sanitization`, `write_file`, `prefixing`, `verify_nonce`,
`safe_redirect`, `offloading_files`, `ai_provider`, `localhost`,
`php_error_reporting`). `minified_files` is verified as described above
(no `.js`/`.css`, no long lines). A negative control (a scratch file
with an unescaped `echo $_GET[...]`, a global function without prefix)
made `late_escaping`, `prefixing` and `plugin_review_phpcs` report
findings, so the zero results are not a silent no-op.

The non-phpcs checks were run for real in a WordPress 6.6 Playground
boot with Plugin Check activated (`wp plugin check babilim
--checks=<slug> --format=json`, one check per invocation, in-process):
`code_obfuscation`, `plugin_content`, `file_type`,
`plugin_header_fields`, `plugin_uninstall`, `no_unfiltered_uploads`,
`trademarks`, `direct_file_access`, `external_admin_menu_links`,
`wp_functions_compatibility` and `plugin_updater` each answered
"Success: Checks complete. No errors found." `plugin_readme` reported
one WARNING, `readme_parser_warnings_tested_header_ignored` (the
"Tested up to" placeholder), and nothing else. A negative control (a
scratch file containing the `site_transient_update_plugins` filter name)
made `plugin_updater` report `plugin_updater_detected` again, so the
clean result is real.

Total: 0 errors, 1 warning (the readme placeholder below).

## Findings not fixed -- structural, not fixable by a code change

### `plugin_updater_detected` -- resolved

Earlier revisions of this plugin injected translation entries into
core's `site_transient_update_plugins` / `_themes` transients, which
Plugin Check's `plugin_updater` check flags as an ERROR. That mechanism
is gone: the periodic sync now installs listed language-file builds
itself through the plugin's own installer. The old code is archived
under `docs/archive/core-translation-updater/` (outside the shipped
files). The check now reports nothing on the shipped source; see "1.3.0
run" above.

### `readme_parser_warnings_tested_header_ignored` (WARNING)

- **Check:** `plugin_readme`.
- **Cause:** the readme's `Tested up to` header carried a placeholder, which the readme parser ignores and reports.
- **Disposition:** Resolved: `Tested up to` now carries a real version, and `plugin_readme` reports nothing in the "1.0.0 fix-wave run" below.

## Also fixed (kept as hardening, not required to reach the counts above)

- `includes/class-babilim-manifest.php`'s four manifest-lock SQL
  statements now pass the lock table name through `$wpdb->prepare()`'s
  own `%i` identifier placeholder (WP 6.2+; this plugin's floor is 6.6)
  instead of interpolating `{$table}` directly into the query string --
  this is what actually fixed both `plugin_review_phpcs`'s
  `InterpolatedNotPrepared` warnings and `direct_db`'s "unescaped
  parameter" warnings above; it isn't separate hardening. `tests/
  bootstrap.php`'s `Babilim_Test_Wpdb::prepare()`/`query()`/`get_var()`
  stubs were extended to require the real backtick-quoted `` `%i` ``
  shape (matching real WordPress core) instead of treating the
  backticks as optional -- the original, more lenient stub let a test
  keep passing even with `{$table}` interpolation reverted, in
  `tests/unit/test-manifest-lock.php`.

## One-click enable change (admin script and menu)

This change added the plugin's first and only script,
`admin/js/babilim-admin.js`, enqueued from `Babilim_Admin::enqueue_admin_assets()`
only on the plugin's own two admin pages. It is registered with an explicit
version (`BABILIM_VERSION`), in the footer (`true`) and with no
dependencies, which is the shape the `enqueued_scripts_in_footer` and
`enqueued_resources` checks look for (`WordPress.WP.EnqueuedResourceParameters`,
`WordPress.WP.EnqueuedResources`): the script is a local file from
`plugins_url()`, never a remote one. The packaged zip (`make zip`)
contains the script and nothing from `tests/`.

Superseded: the full default check set was re-run afterwards; see
"1.0.0 fix-wave run" below.

## 1.0.0 fix-wave run (source at aa8b5cd)

- **Tooling:** Plugin Check 2.1.0, WordPress 6.6.4 on the SQLite
  Database Integration plugin 3.0.2, PHP 8.3, wp-cli 2.11.0,
  `WP_HTTP_BLOCK_EXTERNAL=true`, from a scratch directory outside the
  repository. The plugin copy was the unpacked `build/babilim-1.0.0.zip`
  produced by `make release-check` at aa8b5cd, so it held exactly what
  ships (`babilim.php`, `includes/`, `admin/`, `languages/`,
  `uninstall.php`, `readme.txt`, `LICENSE`).
- **Command** (run from the WordPress root, no `--path=` argument to the
  check itself):

```
wp plugin check babilim --require=<wp-content>/plugins/plugin-check/cli.php \
  --format=csv --fields=file,line,column,type,code,severity \
  --include-low-severity-errors --include-low-severity-warnings
```

- **Result:** every default check, including the runtime ones and
  `plugin_readme`, ran in one pass: "Success: Checks complete. No errors
  found." The CSV output was empty -- 0 errors, 0 warnings.
- **Negative control:** a scratch file in `includes/` with an unprefixed
  global function and an unescaped `echo $_GET['x']` made the same
  command report 2 ERRORs (`missing_direct_file_access_protection`,
  `WordPress.Security.EscapeOutput.OutputNotEscaped`) and 5 WARNINGs
  (`PrefixAllGlobals.NonPrefixedFunctionFound` and four
  input-handling sniffs). With the file removed, the result went back to
  clean, so the empty result is not a silent no-op.
- **`load_plugin_textdomainFound`:** it does not appear in this run
  because the one call (`includes/class-babilim.php`) carries an inline
  `phpcs:ignore` with the reason. The call is kept on purpose: the
  plugin declares `Requires at least: 6.6`, and on that floor this call
  is what loads the bundled `languages/` files. If the
  directory team asks for it to be removed, it becomes a conditional call
  guarded by the WordPress version.
- **`readme_parser_warnings_tested_header_ignored`:** gone; `Tested up
  to` carries a real version.


## Input reads and the official sniff (review fix wave)

Plugin Check 2.1.0's bundled `vendor/bin/phpcs` with
`phpcs-rulesets/plugin-review.xml` and `--runtime-set minimum_wp_version 6.6`,
run over `tests/fixtures/sniff-probe/input-forms.php` (one function per
candidate form, each after a nonce check so only
`WordPress.Security.ValidatedSanitizedInput` is under test). Acceptance
counts warnings as well as errors.

Probe functions, in file order: `babilim_probe_helper` (a helper that
sanitizes), `_map` (`array_map( 'sanitize_text_field', array_filter(
wp_unslash( ... ), 'is_string' ) )`), `_loop` (foreach),
`_root_plain` / `_root_raw` (DOCUMENT_ROOT, no guard),
`_root_plain_guarded` / `_root_raw_guarded` (with an `isset()` +
`is_string()` guard), `_extra_*` (more array variants: direct
`array_map`, closure, early return) and `_map_then_filter` (sanitize
first, then drop empty results).

Verbatim output:

```

FILE: tests/fixtures/sniff-probe/input-forms.php
--------------------------------------------------------------------------------
FOUND 0 ERRORS AND 11 WARNINGS AFFECTING 10 LINES
--------------------------------------------------------------------------------
  11 | WARNING | Detected usage of a non-sanitized input variable: $_POST['k']
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotSanitized)
  28 | WARNING | Detected usage of a non-sanitized input variable: $_POST['k']
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotSanitized)
  35 | WARNING | Detected usage of a non-sanitized input variable: $_POST['k']
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotSanitized)
  46 | WARNING | Detected usage of a possibly undefined superglobal array
     |         | index: $_SERVER['DOCUMENT_ROOT']. Check that the array index
     |         | exists before using it.
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotValidated)
  47 | WARNING | Detected usage of a possibly undefined superglobal array
     |         | index: $_SERVER['DOCUMENT_ROOT']. Check that the array index
     |         | exists before using it.
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotValidated)
  47 | WARNING | Detected usage of a non-sanitized input variable:
     |         | $_SERVER['DOCUMENT_ROOT']
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotSanitized)
  55 | WARNING | Detected usage of a possibly undefined superglobal array
     |         | index: $_SERVER['DOCUMENT_ROOT']. Check that the array index
     |         | exists before using it.
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotValidated)
  56 | WARNING | Detected usage of a possibly undefined superglobal array
     |         | index: $_SERVER['DOCUMENT_ROOT']. Check that the array index
     |         | exists before using it.
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotValidated)
  68 | WARNING | Detected usage of a non-sanitized input variable:
     |         | $_SERVER['DOCUMENT_ROOT']
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotSanitized)
  95 | WARNING | Detected usage of a non-sanitized input variable: $_POST['k']
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotSanitized)
 112 | WARNING | Detected usage of a non-sanitized input variable: $_POST['k']
     |         | (WordPress.Security.ValidatedSanitizedInput.InputNotSanitized)
--------------------------------------------------------------------------------

Time: 51ms; Memory: 6MB
```

### array input form

Every filter-first form is reported `InputNotSanitized` (warning): the
helper, `_map`, `_loop` and every `_extra_*` variant (the sniff does not
follow `array_map()` or a helper into the elements). The one clean form
is `_map_then_filter`, which sanitizes first and then drops the empty
results:

```php
isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) ), 'strlen' ) ) : array()
```

`sanitize_text_field()` returns `''` for an array element, so a nested
array is removed by the `strlen` filter and never reaches the caller as
a value. This form is used in `Babilim_Admin::post_list()` (which
`components[]`, `pending[]` and `locales[]` all use), with no
`ValidatedSanitizedInput` ignore at all.

### DOCUMENT_ROOT identity read

Without a guard both comparisons are reported `InputNotValidated`
(possibly undefined index), and the plain one also `InputNotSanitized`.
With `isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( ... )` first,
**the raw comparison is clean** (`babilim_probe_root_raw_guarded`, no
finding) and the plain one is still reported (`InputNotSanitized`,
line 68). The identity read is therefore:

```php
if ( ! isset( $_SERVER['DOCUMENT_ROOT'] ) || ! is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
    return null;
}
$clean = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
if ( (string) filter_var( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ), FILTER_UNSAFE_RAW ) !== $clean ) {
    return null; // sanitizing changed the value: its identity is undecidable
}
```

No exception is registered for it.

### Registered exceptions

Lines where an `ignore` for `ValidatedSanitizedInput` is accepted by
`tests/unit/test-plugin-check.php`. A row has the form
``| `file.php:LINE` | sniff output | reason |``.

None. Every `ValidatedSanitizedInput` finding in `admin/`, `includes/`,
`babilim.php` and `uninstall.php` is handled by code, not by an ignore.

### Result on the real source

`phpcs --standard=plugin-review.xml --runtime-set minimum_wp_version 6.6
--report=full admin includes babilim.php uninstall.php`: 0 errors and 0
warnings. With `--ignore-annotations` added, no
`WordPress.Security.ValidatedSanitizedInput.*` finding remains (the
remaining ones are the `NonceVerification` annotations, which are
justified where they stand).

## 1.1.0 release run (zip built from 5123467)

`build/babilim-1.1.0.zip`: 295 files, 4873139 bytes, sha256
`f7753ee5e34a2ea068d911e95506a81c3fc0f914b9d0d83d94410d0feab10420`.
`docs/` is not in the zip, so this section does not change that hash.

`scripts/plugin-check-native.sh` was run on the unpacked zip, not on the
working tree. Each phpcs-based check uses its own standard and sniffs
from the table above; `minified_files` is the file scan described there.

| Check | Errors | Warnings |
|---|---|---|
| `plugin_review_phpcs` | 0 | 0 |
| `direct_db_queries` | 0 | 0 |
| `direct_db` | 0 | 0 |
| `late_escaping` | 0 | 0 |
| `i18n_usage` | 0 | 0 |
| `enqueued_scripts_in_footer` | 0 | 0 |
| `performant_wp_query_params` | 0 | 0 |
| `enqueued_resources` | 0 | 0 |
| `setting_sanitization` | 0 | 0 |
| `write_file` | 0 | 0 |
| `prefixing` | 0 | 0 |
| `verify_nonce` | 0 | 0 |
| `safe_redirect` | 0 | 0 |
| `offloading_files` | 0 | 0 |
| `ai_provider` | 0 | 0 |
| `localhost` | 0 | 0 |
| `php_error_reporting` | 0 | 0 |
| `minified_files` (file scan) | 0 | 0 |

These counts are with the source's own `phpcs:ignore` / `phpcs:disable`
annotations in effect, as Plugin Check runs them (72 of them across
`admin/` and `includes/`). With
`--ignore-annotations` added, some checks report the annotated lines;
for example `direct_db_queries` then reports 0 errors and 14 warnings
in 4 files. "Registered exceptions" above covers only the
`ValidatedSanitizedInput` sniff, and lists none.

### Negative control

To show the run can fail, a copy of the unpacked zip had two lines added
inside an ordinary admin method: one reading a raw `$_GET` value into a
variable, one echoing that variable unescaped. The same script then
reported, and exited non-zero:

- `plugin_review_phpcs`: 0 errors, 4 warnings, all on the added input
  line (`InputNotValidated`, `MissingUnslash`, `InputNotSanitized`,
  `NonceVerification`).
- `late_escaping`: 1 error on the added output line (`EscapeOutput`).

Every other check stayed at 0 errors and 0 warnings.

### Runtime and file checks (WordPress Playground)

The same zip (sha256 above), unpacked and mounted as the plugin, with
Plugin Check 2.1.0 activated, in a WordPress 6.6 / PHP 8.1 Playground
boot (`@wp-playground/cli` 3.1.54). Each check ran in its own `wp
eval-file` step, through Plugin Check's own `Default_Check_Repository`,
`Check_Context` and `Check_Result`, one check per request. The runner is
a one-off script kept outside this repository:

| Check | Errors | Warnings |
|---|---|---|
| `plugin_readme` | 0 | 0 |
| `plugin_uninstall` | 0 | 0 |
| `direct_file_access` | 0 | 0 |
| `file_type` | 0 | 0 |
| `plugin_header_fields` | 0 | 0 |
| `trademarks` | 0 | 0 |
| `external_admin_menu_links` | 0 | 0 |
| `wp_functions_compatibility` | 0 | 0 |
| `plugin_updater` | 0 | 0 |
| `code_obfuscation` | 0 | 0 |
| `plugin_content` | 0 | 0 |
| `no_unfiltered_uploads` | 0 | 0 |
| `minified_files` | 0 | 0 |

Negative control, same boot setup, on a copy with three probe files
added (a PHP file hooking `site_transient_update_plugins` without an
`ABSPATH` guard, a one-line minified `.js`, and an empty `.phar`): `plugin_updater` 1 error
(`plugin_updater_detected`), `direct_file_access` 1 error
(`missing_direct_file_access_protection`), `file_type` 2 errors
(`phar_files`, `application_detected`); the other checks stayed at 0.

`minified_files` in Plugin Check 2.1.0 hands only `.php` files to phpcs
and reports tokenizer exceptions; phpcs's "appears to be minified"
test applies to its JS and CSS tokenizers, so neither a minified `.js`
probe nor a long-line `.php` probe made it report anything. Its 0 above
is therefore not backed by a negative control; the `.js`/`.css` file
scan in `scripts/plugin-check-native.sh` is what covers minified
scripts.


## 1.2.0 first package (zip built from 2c37bee, replaced before upload)

`build/babilim-1.2.0.zip`: 295 files, 4911592 bytes, sha256
`e5f49813b19dec5c070f1998ad003e1931c7264069d97f57705cc9df3ccd3d34`.
`make test`, `make contract-check` and `make release-check` all exited 0
on that commit, and two separate `make zip` runs gave the same hash.
`docs/` is not in the zip, so this section does not change that hash.

### Plugin Check, phpcs-based checks

`scripts/plugin-check-native.sh` on the unpacked zip, same method as the
1.1.0 run above:

| Check | Errors | Warnings |
|---|---|---|
| `plugin_review_phpcs` | 0 | 0 |
| `direct_db_queries` | 0 | 0 |
| `direct_db` | 0 | 0 |
| `late_escaping` | 0 | 0 |
| `i18n_usage` | 0 | 0 |
| `enqueued_scripts_in_footer` | 0 | 0 |
| `performant_wp_query_params` | 0 | 0 |
| `enqueued_resources` | 0 | 0 |
| `setting_sanitization` | 0 | 0 |
| `write_file` | 0 | 0 |
| `prefixing` | 0 | 0 |
| `verify_nonce` | 0 | 0 |
| `safe_redirect` | 0 | 0 |
| `offloading_files` | 0 | 0 |
| `ai_provider` | 0 | 0 |
| `localhost` | 0 | 0 |
| `php_error_reporting` | 0 | 0 |
| `minified_files` (file scan) | 0 | 0 |

The source carries 66 `phpcs:ignore` / `phpcs:disable` annotations in
`admin/` and `includes/`, each with its reason on the same line.

Negative control: a copy of the unpacked zip with a raw `$_GET` read and
an unescaped `echo` of it added inside `enqueue_admin_assets()`. The
script exited non-zero with `plugin_review_phpcs` 0 errors, 4 warnings
(`InputNotValidated`, `MissingUnslash`, `InputNotSanitized`,
`NonceVerification`) and `late_escaping` 1 error. Every other check
stayed at 0.

### Plugin Check, runtime and file checks (WordPress Playground)

Same setup as the 1.1.0 run: the unpacked zip mounted as the plugin,
Plugin Check 2.1.0 active, WordPress 6.6 / PHP 8.1, one check per `wp
eval-file` step.

| Check | Errors | Warnings |
|---|---|---|
| `plugin_readme` | 0 | 0 |
| `plugin_uninstall` | 0 | 0 |
| `direct_file_access` | 0 | 0 |
| `file_type` | 0 | 0 |
| `plugin_header_fields` | 0 | 0 |
| `trademarks` | 0 | 0 |
| `external_admin_menu_links` | 0 | 0 |
| `wp_functions_compatibility` | 0 | 0 |
| `plugin_updater` | 0 | 0 |
| `code_obfuscation` | 0 | 0 |
| `plugin_content` | 0 | 0 |
| `no_unfiltered_uploads` | 0 | 0 |
| `minified_files` | 0 | 0 |

The first run of this table, on the zip built from the commit before
2c37bee, gave `plugin_readme` 1 warning,
`readme_parser_warnings_trimmed_section_description`. The readme parser
appends every non-standard `== section ==` to Description and cuts the
result at 2500 words; the longer "External services" section had pushed
that total to about 2600, so the end of it would not have shown on the
plugin page. 2c37bee moved "How translations are loaded" and "Privacy"
into the FAQ (which has a 5000-word limit) without changing their text,
and `tests/unit/test-readme.php` now keeps that total at 2400 words or
less. That first run also serves as the negative control for
`plugin_readme`.

### Behaviour checks (WordPress Playground)

`tests/playground/run-checks.sh` on WordPress 6.6.11 and 7.1.4, PHP 8.1,
`@wp-playground/cli` 3.1.54: 28 PASS and 0 FAIL on each version. They ran
on 8626517; the commits after it change only `readme.txt` and tests.

### Browser scenarios

`tests/playground/browser/ux-flow.mjs` with the scenarios `enable`,
`settings`, `errconsent` and `report`, on d19d4a8 (code identical to
2c37bee):

| Environment | PASS | FAIL |
|---|---|---|
| Playground, WordPress 6.6.11 (mock service) | 169 | 0 |
| Playground, WordPress 7.1.4 (mock service) | 169 | 0 |
| WordPress 6.6.4 on `php -S` with SQLite, real verification callback (mock service) | 169 | 0 |

### Live service, two throwaway free sites

Two fresh WordPress 6.6.4 sites (PHP 8.3, SQLite, pretty permalinks,
site language `zh_TW`, Classic Editor installed), each reachable over a
temporary public HTTPS tunnel, enabled against the production service.
Plugin source at d19d4a8.

Site 1:

1. "Enable Babilim" registers and verifies the site.
2. The `zh_TW` cell for Classic Editor shows "Request translation" and no
   Download button.
3. Pressing it opens this confirmation (shown in `zh_TW`; the source
   string is): "If this plugin or theme is not on one of the free service
   plan's slots for this site yet, requesting a translation puts it on
   one. A slot stays used, and a failed translation does not free it.
   Continue?" (When the service has reported its slot limits, the
   sentence names them in parentheses after "one".)
4. After confirming, the cell goes from "Scheduled, waiting to be
   processed" to "Translating…" to "Available" with a Download button,
   about two minutes later.
5. Download installs the translation; the cell shows "Installed" and the
   plugin's manifest records build `bd_2a0d40a421ee`.

Site 2, for the same component and locale, so that build already exists:

1. Before any request, a direct `POST /v1/downloads` for
   `bd_2a0d40a421ee` with this site's own token is refused:
   `403 babilim_slot_required`.
2. The cell shows "Request translation" and no Download button.
3. After the same confirmation, the cell is "Available" at once.
4. Download installs it; the manifest records the same build.

Knowing a build id is not enough: the service serves it only to a site
whose slot covers the component. Manual Download and automatic installs
go through the same `POST /v1/downloads` request and the same answer.
The plugin's install and download code does not read the plan; the plan
the service reports is used only to word the screen (the Upgrade button
and the free-plan slot notice).

## 1.2.0 release run (zip built from 17a5d18)

`build/babilim-1.2.0.zip`: 295 files, 4983309 bytes, sha256
`cc1d62714d4089a004457ca805af25eb7f7c97f87f75752c6e3dafe9a47b3ca9`.
`make test` (1989 tests), `make stan`, `make contract-check` and
`make release-check` all exited 0 on that commit, and two separate
`make zip` runs gave the same hash. This replaces the first 1.2.0 package
above, which was never uploaded.

### Plugin Check, phpcs-based checks

`scripts/plugin-check-native.sh` on the unpacked zip: all 18 checks
(`plugin_review_phpcs`, `direct_db_queries`, `direct_db`, `late_escaping`,
`i18n_usage`, `enqueued_scripts_in_footer`, `performant_wp_query_params`,
`enqueued_resources`, `setting_sanitization`, `write_file`, `prefixing`,
`verify_nonce`, `safe_redirect`, `offloading_files`, `ai_provider`,
`localhost`, `php_error_reporting`, `minified_files`) report 0 errors and
0 warnings. The source carries 57 `phpcs:ignore` / `phpcs:disable`
annotations in `admin/` and `includes/`, each with its reason.

### Plugin Check, runtime and file checks (WordPress Playground)

The unpacked zip mounted as the plugin, Plugin Check 2.1.0 active,
WordPress 6.6 / PHP 8.1, `@wp-playground/cli` 3.1.54, one check per
`wp eval-file` step through `Default_Check_Repository`, `Check_Context`
and `Check_Result`: `plugin_readme`, `plugin_uninstall`,
`direct_file_access`, `file_type`, `plugin_header_fields`, `trademarks`,
`external_admin_menu_links`, `wp_functions_compatibility`,
`plugin_updater`, `code_obfuscation`, `plugin_content`,
`no_unfiltered_uploads` and `minified_files` all report 0 errors and 0
warnings.

Negative control with the same runner: a copy with the `Stable tag:` line
removed from `readme.txt` and a new `includes/` file without the
`ABSPATH` guard gave `plugin_readme` 1 error (`no_stable_tag`) and
`direct_file_access` 1 error (`missing_direct_file_access_protection`).

### Behaviour checks and browser scenarios (WordPress Playground)

- `tests/playground/run-checks.sh` on WordPress 6.6 and latest: 28 PASS,
  0 FAIL on each (on 2141d02; the later commits change only tests,
  translations and screenshots).
- `tests/playground/run-ux-flow.sh` (noverify) on WordPress 6.6 and
  latest with the scenarios `enable settings list bulk rowactions
  screenshots report errconsent notices noquota`: 390 PASS, 0 FAIL on
  each.
- `tests/playground/run-neutral-ux-flow.sh` (real callback) with the same
  scenarios plus `l10n`: 402 PASS and 1 FAIL (`enable-nojs`, a
  `waitForNavigation` timeout); the `enable` scenario alone, run twice
  more, gave 28 PASS, 0 FAIL both times.

### Live service, free plan (two throwaway sites)

The same steps as the first 1.2.0 package's section above, against the
live service: site 1 requests `classic-editor` / `zh_TW` and installs
build `bd_2a0d40a421ee`; site 2 is refused a direct `POST /v1/downloads`
for that build with `403 babilim_slot_required` until it requests the
component, after which the cell is "Available" at once and Download
installs it. 15 PASS, 0 FAIL. Both test sites were removed from the
service afterwards.
