# CLAUDE.md

This file is the **complete** rulebook for working in this repo. A cloud
session working here has no access to our internal planning docs, memory,
or tooling — if it isn't written down here (or in a file this repo
tracks), assume the session can't see it. Don't guess at anything that
isn't; ask instead, or leave a `TODO` and flag it in your report.

## What this repo is

The public wp.org plugin half of **Babilim**, a WordPress translation
service: it looks at the plugins/themes installed on a site, finds ones
missing a translation for the site's locale, and lets the site request
one from the Babilim service. The service itself (translation pipeline +
hosted API) is a separate, private repository; this plugin only ever
talks to it over the versioned HTTP contract summarized in
`docs/api-contract.md` and pinned under `tests/contract/v1/`.

## Baseline requirements

- WordPress ≥ 6.6, PHP ≥ 7.4.
- `License: GPL-2.0-or-later` (see `LICENSE`, full text).
- `readme.txt` is in English (required by the wp.org directory).
- Plugin slug and text domain: `babilim`. No `Update URI:` header.

## Runtime constraints

These apply to every line of plugin code from the first implementation
task on:

- **Zero remote code.** The plugin only ever fetches translation *data*
  (`.mo`/`.po`/`.json`). It never downloads or executes remote PHP or JS.
  `.l10n.php` files are produced locally, from a downloaded `.po`/`.mo`,
  via `WP_Translation_File::transform()` — never downloaded as-is.
- **Zero client-side quota logic.** Quota/limit decisions are made by the
  service; this plugin only ever reflects what the service tells it.
- **Zero outbound requests before consent**, including on the activation
  hook. Nothing calls out to the service until the site owner has
  explicitly agreed to use it.
- **HTTP only via the WordPress HTTP API** (`wp_remote_get()` etc.) — no
  direct cURL, `file_get_contents()`, sockets, or any other transport.
- **Filesystem writes only via `WP_Filesystem`**, wrapped in a
  `Babilim_Fs` helper, and only inside `WP_LANG_DIR/babilim/`. That path
  is a hard allow-list, not a default to be extended. The first exception
  is the installer's lock file, `WP_LANG_DIR/babilim/.install.lock`: it is
  opened with `fopen()` because `flock()` needs a native handle, and it
  never holds any content.
  The second exception is source packaging: `Babilim_Packager` (and only
  it) writes one zip plus a deny-all `.htaccess` and empty `index.php` into
  a private staging directory it creates under the system temp directory
  (or WordPress's temp directory when the system one is inside the site),
  removes it when the upload ends, and sweeps leftovers older than 24 hours
  daily. Nothing else may write there, and nothing but a zip is ever
  written.
  The third exception is `Babilim_Wp_Fs::atomic_rename()`: when
  WP_Filesystem runs in direct mode it moves a file inside
  `WP_LANG_DIR/babilim/` with PHP's native `rename()`, because
  `WP_Filesystem_Direct::move()` deletes the destination before renaming
  and so leaves a window with no file at all. Both paths are checked
  against the same allow-list first, and no other method may use a native
  write call.
- **Every user-facing string** goes through `__()` (or equivalent) with
  the `babilim` text domain.
- **Every state-changing action** (anything that writes) requires a
  nonce check and the `manage_options` capability.
- **The site's API token (`site_token`) never reaches the browser** — not
  in an enqueued script, not in a data attribute, nowhere in the DOM or
  any JS that ships to the client.

## Translation-loading layer

The loading layer hooks exactly four WordPress core filters and two
actions — nothing else:

- `lang_dir_for_domain` (filter) — return WordPress core's own directory
  unchanged whenever core already has the file for that locale there.
  Only return our directory when core returns `false`, or when core's
  registered location has no file for that locale and we do.
- `load_translation_file` (filter) — the layered lookup happens here,
  with a recursive lock against re-entrancy. The `default` text domain
  does **not** go through this layering (see below).
- `load_script_translation_file` and `load_script_translations`
  (filters) — the same layering, for JS translations.
- `after_setup_theme` and `change_locale` (actions) — where the
  `default` domain's supplementary strings get loaded, always *after*
  WordPress core's own `load_default_textdomain()` has already run. Do
  **not** add a "locale unchanged, skip" guard here — that shortcut was
  considered and rejected; the supplement load always re-runs.

Do **not** hook `override_load_textdomain`, an unconditional
`load_textdomain_mofile`, or any `gettext*` filter for this — those are
the wrong layer and were rejected during design. If an approach seems to
need one of them, stop and ask rather than adding it.

Every callback here wraps its body in a `\Throwable` catch-all, validates
any path against an allow-list regex, and no-ops entirely if its root
directory doesn't exist.

**Loading priority** (this is contractual — `docs/api-contract.md` and
`readme.txt` both state it, and all three must stay in sync):

- Plugin/theme text domains: a site's own `custom/` override (if one
  exists) beats our `babilim/` supplement, which beats a WordPress.org
  language pack or the plugin/theme's own bundled translation.
- The `default` text domain: WordPress core's own official translation
  beats our `babilim/default/` supplement.

A source only ever fills a gap left by everything ranked above it — a
site's `custom/` override always wins over our supplement, and
WordPress core's own official `default` translation always wins over
ours for that domain. (This is the *system-level* priority, achieved by
`load_translation_file`'s layering. Don't confuse it with
`lang_dir_for_domain`'s own narrower rule above — "return core's
directory unchanged whenever core already has the file, only substitute
ours when core has nothing registered" — that's the directory-resolution
half of the mechanism, not a restatement of this priority order; they
describe two different layers and can look contradictory if read as the
same claim.)

(Hook-by-hook implementation detail — exact filter signatures, WordPress
core version breakpoints, etc. — lands with the plugin's first
implementation task. Ask for it rather than guessing if you need it
before then.)

## `default` domain supplement governance

This is a confirmed design requirement, not speculative:

- Each request loads at most **one** merged-per-locale index
  (`default/_merged-{locale}.mo` / `.l10n.php`) — never a glob over
  individual supplement files.
- That index only includes entries from components that are both
  currently active *and* at the exact version the entry was built for.
- If two different components supply a different translation for the
  same `(msgctxt, msgid)` pair, that entry is left out of the index
  entirely — falling back to English beats silently picking a winner.
- Any entry WordPress core already has an official translation for is
  excluded when the index is built (belt-and-suspenders with the loading
  priority above).
- Every translation file — main file and supplement alike — is recorded
  in a `_manifest.json` with the component it belongs to, that
  component's version, and its build id.
- The index is rebuilt whenever a component is activated, deactivated,
  removed, or updated; a build is installed, rolled back, or deleted; or
  the site's locale configuration changes.
- A daily scheduled job reconciles `_manifest.json` against what's
  actually on disk and removes orphaned files and supplement files left
  behind by a component that no longer exists.

**Explicitly out of scope for this milestone:** proving a caller's
identity via a backtrace before serving it a supplement file — the
performance cost isn't worth what it buys. Don't add this without a
design discussion first.

## How language packs become available

This plugin doesn't implement this — it's context for why the API
behaves the way `docs/api-contract.md` describes:

Once the service's translation pipeline marks a build as published, it's
immediately available to sites; there's no separate manual publish step.
The admin action described as "disable/roll back this language-pack
version" just marks that build inactive server-side — sites pick up the
current active build on their next sync, and files a site has already
installed keep working regardless.

**Nothing about how a translation was produced or graded — no grade, no
supporting evidence, no "verified" badge — is ever returned to a site or
shown in this plugin's UI.** Whatever internal review process the service
runs is entirely server-side and never crosses this plugin's API
boundary. Don't add UI, a field, or a status value for it here, and don't
invent a status beyond the ones `docs/api-contract.md` lists.

## File layout

File layout on disk follows the design spec's directory structure
(landing with the plugin's first implementation task — ask if you need
it before then). The one rule that matters at this stage: the serving
directories only ever hold `.l10n.php`/`.mo`/`.json`; `.po` files live in
a `_versions/` history directory, never somewhere WordPress core would
actually load from.

## Locale neutrality

No list, default, ordering, or piece of wording anywhere may favor one
language over another. This plugin does not hardcode a locale allow-list
— the full WordPress.org locale registry (see `GET /v1/meta/locales` in
`docs/api-contract.md`) is the source of truth. All UI strings are
written in English and are fully translatable. Every WordPress-supported
locale can be requested on equal footing; there is no locale tiering.

## The service backend

Not implemented in this repo (it's a separate, private repository) — kept
here only so this plugin's retry/backoff/error-handling behavior makes
sense against it: the service keeps its `/v1/` API backward compatible,
never invalidates a file a site has already downloaded, uses the
`{error:{code,message,hint}}` error shape throughout, and rate-limits at
60 requests/minute per site, 10 registrations/minute per IP, and 500
downloads/day per site. A `429` always carries `Retry-After`.

## The contract

`contract/v1/` in the service repository is the single source of truth
for the API. An interface change always updates the contract first (bump
`contract/v1/VERSION`, add a changelog entry) before either side's code
changes.

This repo's copy, `tests/contract/v1/`, is synced locally from the
service repo and pinned by a SHA256 of its `SHA256SUMS` file (see
`tests/contract/PINNED`). **Never hand-edit anything under
`tests/contract/v1/`** — `make contract-check` exists specifically to
catch a copy that's drifted from what's pinned. If the contract needs to
change, that happens in the service repo first; this repo only ever
re-syncs.

## Public-repo discipline

This repo is a public wp.org plugin, and public-repo discipline applies
from the first commit, whether or not the GitHub repo happens to be
private at any given moment (in the past that hasn't stopped things
leaking, and this codebase may be copied out to wp.org SVN at any time).
Nothing tracked in this repo may reference:

- Our own internal infrastructure, hosting, or tooling by name — not a
  product/service name, not an internal path. This includes anything
  hostname- or IP-address-shaped that isn't on a short allow-list of
  domains that are genuinely fine to cite (our own product domain,
  WordPress.org, a couple of other public/neutral domains this repo's
  own schemas legitimately reference, and the IANA-reserved documentation/
  testing TLDs and IP ranges). We can't reference our real internal
  hostnames here even to *ban* them by name — doing that in a script this
  repo ships would leak them by definition — so the check works as an
  allow-list instead of a deny-list; see `scripts/public-rules.sh` for
  exactly what's allowed. The same goes for plain internal names,
  codenames and tool names: `scripts/public-rules.sh` only bans a few
  generic words, because a list of our real ones would itself be the
  leak. Those are checked by a separate scan that isn't part of this
  repo, run before anything here is published — so write as if nothing
  in this repo will catch them for you.
- Any client/customer name or one of their sites. These can't be
  exhaustively enumerated here (we maintain a large, changing fleet of
  unrelated client sites) — this is a case where the automated check
  can't be complete, so extra care is warranted when writing anything
  that names a real organization or site.
- Any credential, key, or secret of any kind.
- Our internal task/milestone tracking codes, or a section-mark-style citation
  into one of our private planning/research documents (if you find
  yourself wanting to write one, that's a sign the information it points
  to needs to be stated directly here instead — ask if you're not sure
  how).
- Chinese/Japanese/Korean-language prose (this repo is English-only —
  see "Language" below). The one narrow exception is
  `tests/contract/v1/examples/meta.locales.ok.json`, which legitimately
  carries translation *data* that's genuinely part of the API contract —
  a WordPress locale's own native-language name, written in that
  language (e.g. Japanese's own name for itself, not its English name
  "Japanese") — real WordPress.org locale-registry data, not leaked
  internal text. No other file gets this exception.
- Internal-only vocabulary about how a translation is graded or reviewed
  — see "How language packs become available" above; nothing about that
  process belongs in this repo at all, in any language.
- Anything from the service repository beyond what `contract/v1/`
  publishes. That repo is private; don't cite its file paths, its
  internal docs, or anything about how the service itself is built —
  only the contract is ours to reference here.

`scripts/public-check.sh` (`make public-check`) is the automated,
authoritative check for all of this; its rules live in
`scripts/public-rules.sh`, which is the definitive list if this section
and the script ever disagree. The scripts are scanned too — none is
blanket-excluded — except for the handful of specific lines in
`scripts/public-rules.sh` that legitimately have to contain a banned
word as literal ban-list data, each marked individually (see that
script's header comment). It must
pass before every commit. If you're ever unsure whether something is
safe to write here, run it rather than guessing.

The API base is only ever the literal domain configured in the
`BABILIM_API_BASE` constant (overridable) — never any other domain, path,
or internal detail about how the service is hosted.

### `make public-check` only covers the working tree

It scans files, not commit messages and not history. Neither of those is
clean yet — this repo's early commits (from before this discipline was
fully tightened) still name things `make public-check` would now reject,
and nothing removes that from history automatically. Two more tools
exist for that, and neither is part of the normal commit loop:

- **`make public-history-check`** (`scripts/public-history-check.sh`)
  scans every commit message, every commit's author and committer, every
  annotated tag (message and tagger), and every blob ever committed, on
  every ref — not just what's checked out. It uses the same rule engine
  (`scripts/public-rules.sh`) and the same exemptions as
  `make public-check`, so a history squashed to one clean commit passes
  it. It is expected to fail right now.
  **Before this repo (or its history) is ever made public or pushed
  anywhere reachable from outside**, someone must rewrite history first
  (squash to a single clean initial commit, or an equivalent filter-repo/
  filter-branch pass with both a message filter and a tree filter) and
  re-run this until it's green. Don't attempt that rewrite as a side
  effect of an unrelated task — it's a deliberate, separate, one-time
  step, and it's destructive to the existing history.
- **The `commit-msg` hook** (`scripts/commit-msg-check.sh`, wired up via
  `.githooks/commit-msg`, with `.githooks/prepare-commit-msg` recording
  where the message came from) rejects a commit whose *message* trips
  the same rule set, before it's even created — the earliest point
  anything can be caught. It checks exactly what git will store: comment
  lines are skipped only when git will strip them (the editor with the
  default cleanup, or `--cleanup=strip`), and the text below a scissors
  line only when git will cut it (`-v` / `commit.verbose`, or
  `--cleanup=scissors` in the editor). With `-m` / `-F`, `verbatim` or
  `whitespace`, a `#` line is part of the message and is checked like any
  other line. Whenever the hook can't tell for certain (an unrecognized
  option, `core.commentChar=auto`, no `/proc`, a missing record), it
  checks every line — so at worst git's own comment block (say, a branch
  name) gets a clean message rejected. It also rejects the commit if the
  check itself errors. It's opt-in per clone (a git hook is local
  machine state, not something a repo can force on you): run
  `make install-hooks`, or by hand, `git config core.hooksPath .githooks`
  (that one setting enables both hooks).

## Testing rules

- Plugin unit tests do not require a WordPress install.
- **Zero network calls, zero real client-site calls** — in every test, in
  the cloud or locally, no exceptions.
- Don't route this plugin's tests, or anything else in this repo, through
  any internal task-queue or orchestration tooling. Run them directly.
- A seam test against a real local instance of the service (once one
  exists) runs for real, locally — never mocked across that boundary.
- Tests run in the foreground, synchronously. Don't background a test
  run and wait for it — if a command needs a long timeout, give it one
  and wait for it to finish.
- **Every task report must include mutation-test evidence** — not just a
  passing run. Break the thing you just fixed on purpose (comment out the
  fix, flip a condition, whatever's fastest) and show the test catching
  it, then show it passing again with the fix restored.

## Never touch live client sites

We separately operate a fleet of real, live client WordPress sites that
have nothing to do with this product. Nothing done in this repo — testing,
debugging, anything — ever touches one of those. If you need a real,
running WordPress instance to test against, use WP Playground (the
`wp-playground` skill covers this).

## Development flow

For any code change: **write a failing test first, implement until it
passes, then write the mutation-test evidence described above, then
report.** This is standard TDD; don't skip the "failing test" step even
when the fix feels obvious — a test that was never seen to fail proves
nothing.

Your work will be code-reviewed against your diff before it merges. If
review finds issues, you'll be resumed with the specific findings — fix
them, re-run the tests that cover the changed code, and append a short
fix report (what changed, which tests you ran, and their output) rather
than rewriting your original report.

**Report format** (in the PR description, and in any task report file the
brief you were given points you at):

- What you implemented, or what you attempted if blocked.
- Test command(s) run and their output (trimmed to the relevant lines,
  but never truncated in a way that hides a failure).
- RED: the failing-test command and output, and why that failure was
  expected, from before implementation.
- GREEN: the same command's output after implementation.
- Mutation-test evidence: what you broke on purpose, the test failing
  because of it, then the test passing again once reverted.
- Files changed.
- Any applicable WordPress official skill checklist items you followed,
  cited as `path/to/file.php:123` — see "WordPress official skills"
  below.
- Concerns, if any. It's always fine to report `DONE_WITH_CONCERNS` or
  `BLOCKED` — bad silent work is worse than an honest stop.

## Branch / PR / commit conventions

- One task, one branch, one PR.
- PR description includes: the task this PR implements, a test summary,
  the mutation-test result, and the contract version this code was built
  against (from `tests/contract/v1/VERSION`).
- Commit messages: English, imperative, one focused change per commit,
  and — like the rest of this repo — subject to public-repo discipline:
  no internal task/stage/document-reference codes, no internal names,
  no hostname/IP not on the allow-list, no Chinese/Japanese/Korean text.
  `make public-check` never sees commit messages (it only scans files),
  so this isn't caught automatically unless you've opted into the
  `commit-msg` hook (`make install-hooks`; see "`make public-check` only
  covers the working tree" above) — until then, it's on you to write a
  clean message the same way you'd write clean file content. End every
  commit message with the two-line attribution your session's
  instructions give you. Those two trailer lines are recognized by their
  exact whole-line shape (see `normalize_attribution` in
  `scripts/public-rules.sh`); the vendor domains they carry are accepted
  there and nowhere else — not elsewhere in a message, and never in a
  file.

## WordPress official skills

When implementing plugin code, use the WordPress official agent skills if
your environment has them — check what's actually installed (however
your tooling lists available skills) before citing one, since the exact
set can change and a cloud session may not have them at all (they're
typically installed locally as a convenience, not carried by this repo).
Source: https://github.com/WordPress/agent-skills — if you have network
access and they aren't installed, that's where they come from; if you
don't, use the fallback below instead of guessing at what a skill would
have said.

As of this writing the ones most likely to apply here: `wp-plugin-development`
(architecture, hooks, security patterns), `wp-phpstan` (static analysis),
`wp-playground` (a real WordPress instance to test against without
touching a live site), `wp-plugin-directory-guidelines` (wp.org
submission rules — relevant to `readme.txt` and packaging), `wp-rest-api`
(if/when this plugin exposes its own endpoints, e.g. the challenge
endpoint in the contract), `wp-abilities-api`/`wp-abilities-audit`/
`wp-abilities-verify` (if the plugin exposes any WordPress Abilities),
and `wp-wpcli-and-ops` (WP-CLI commands, if any land).

**Fallback, if no skill is available:** work directly from the WordPress
Plugin Handbook's own published guidance and check your code against it
line by line — in particular: Security (nonces on every state-changing
action, capability checks, output escaping, input sanitization, SQL
preparation), Internationalization (every user-facing string wrapped and
loaded correctly, matching this file's runtime-constraints section
above), the Settings API (if the plugin adds admin-screen settings, use
it rather than hand-rolling form handling), and the uninstall pattern
(`uninstall.php` or `register_uninstall_hook()`, not logic left in the
main plugin file). Whichever path you took — skill or fallback — your
task report must cite the specific checklist items you followed, with
`file:line`, not just "used the skill" or "followed the handbook".

## Commands

```
make contract-check   # tests/contract/v1/ matches PINNED + its own SHA256SUMS
make public-check     # public-repo discipline guard — must pass before every commit
```

`make test` (PHPUnit) and `make tools` (fetch/build the local dev
toolchain into the gitignored `tools/`) land with the first
implementation task.

## Language

Code comments, `readme.txt`, this file, `docs/development.md`, and commit
messages are all English (this is the public half of the project; the
service repo, which is private, is Traditional Chinese instead — that's
a different repo's convention, not a contradiction of this one).
