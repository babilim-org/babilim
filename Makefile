# Babilim plugin repo — top-level developer targets.

# Pinned dev-tool downloads for `make tools` -- the one place in the
# normal dev/test loop that touches the network (CLAUDE.md's Testing
# rules require zero network in the tests themselves). Each is verified
# against the SHA256 below before it's left in tools/ (gitignored).
PHPUNIT_VERSION    := 9.6.37
PHPUNIT_SHA256      := 3466327b9c9f5047cfd71acc4d19620fa4cc943e7590b8f32a3ac173fd56c363
PHPSTAN_VERSION     := 2.2.16
PHPSTAN_SHA256       := 1a2fb5460c142502d3cd06272529c18b19fda004ada974bd2581cb9fd6c0a53b
WP_STUBS_VERSION    := v7.1.0
WP_STUBS_SHA256      := 686e9249c8498ed175bdfea31a0ff47f69d1b5f5c8e862c9d312bcbee86ab8d2
WP_CLI_VERSION      := 2.12.0
WP_CLI_SHA256        := ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c
# A real WordPress core tag, pinned by exact SHA256 -- never committed
# (tools/ is gitignored; see .gitignore), fetched here the same way as
# every other tools/* download.
WP_CORE_TAG             := 6.7.1
# Same tag, same reason -- tests/unit/test-manifest-concurrency.php loads
# these two to run its own two-process probe against WordPress core's
# real WP_Filesystem_Direct::move() (confirmed byte-identical across
# 6.6.9/6.8.10/7.1.2's own core source; see that test's own docblock),
# not a hand-written stand-in for it.
WP_CORE_FS_BASE_SHA256   := 28ada0d3504f25cdd28ec4ba167a820d1a1ac583e3ef894674c718e6238c85c9
WP_CORE_FS_DIRECT_SHA256 := 03eab077e7edb0261f8d973086283ee468cdf1b5b628687c11f101435650e9e9
# Same tag, same reason -- a test loads this (see tests/support/
# real-kses.php) to run admin/class-babilim-admin.php's own rendered
# HTML through WordPress core's real wp_kses(), rather than a
# hand-written stand-in for what it strips (in particular, which
# `style` properties safecss_filter_attr() allows through -- `display`
# is not one of them, on every core version checked).
WP_CORE_KSES_SHA256      := 80dca515d8f424fe37725c4b5d408dd3503131122d1aae3944ffdac582bbd4e2

# .PHONY, not file targets keyed on tools/*.phar existing: a file target
# only runs its recipe when the file is missing, which would make the
# SHA check in fetch-tool.sh dead code (make's own liveness check the
# first time). tools always runs; fetch-tool.sh is what makes repeat
# runs cheap -- it re-verifies the existing file's SHA and only
# re-downloads when that check fails (see that script; also see
# `tools-verify`, below, to check without ever touching the network).
.PHONY: tools
tools:
	@bash scripts/fetch-tool.sh \
		"https://phar.phpunit.de/phpunit-$(PHPUNIT_VERSION).phar" \
		tools/phpunit.phar "$(PHPUNIT_SHA256)"
	@bash scripts/fetch-tool.sh \
		"https://github.com/phpstan/phpstan/releases/download/$(PHPSTAN_VERSION)/phpstan.phar" \
		tools/phpstan.phar "$(PHPSTAN_SHA256)"
	@bash scripts/fetch-tool.sh \
		"https://raw.githubusercontent.com/php-stubs/wordpress-stubs/$(WP_STUBS_VERSION)/wordpress-stubs.php" \
		tools/wordpress-stubs.php "$(WP_STUBS_SHA256)"
	@bash scripts/fetch-tool.sh \
		"https://github.com/wp-cli/wp-cli/releases/download/v$(WP_CLI_VERSION)/wp-cli-$(WP_CLI_VERSION).phar" \
		tools/wp-cli.phar "$(WP_CLI_SHA256)"
	@bash scripts/fetch-tool.sh \
		"https://raw.githubusercontent.com/WordPress/wordpress-develop/$(WP_CORE_TAG)/src/wp-admin/includes/class-wp-filesystem-base.php" \
		tools/wp-core/class-wp-filesystem-base.php "$(WP_CORE_FS_BASE_SHA256)"
	@bash scripts/fetch-tool.sh \
		"https://raw.githubusercontent.com/WordPress/wordpress-develop/$(WP_CORE_TAG)/src/wp-admin/includes/class-wp-filesystem-direct.php" \
		tools/wp-core/class-wp-filesystem-direct.php "$(WP_CORE_FS_DIRECT_SHA256)"
	@bash scripts/fetch-tool.sh \
		"https://raw.githubusercontent.com/WordPress/wordpress-develop/$(WP_CORE_TAG)/src/wp-includes/kses.php" \
		tools/wp-core/kses.php "$(WP_CORE_KSES_SHA256)"

# php-stubs/wordpress-stubs (used by `make stan`, see phpstan.neon's
# scanFiles) is a single-file stub of WordPress core's functions,
# classes and constants. szepeviktor/phpstan-wordpress (a fuller
# PHPStan extension) ships no phar and no single-file form, and pulling
# it in without Composer would mean vendoring its dependency tree by
# hand; this stub-only setup already resolves WordPress core's own
# functions/classes, which is what this plugin's code calls. Revisit if
# the code ever needs the extension's dynamic-return-type/rule coverage.

# Re-verifies every tools/* SHA256 without downloading anything -- fails
# (and says which file) if any of them is missing or has drifted from
# what's pinned above, without touching the network.
.PHONY: tools-verify
tools-verify:
	@echo "$(PHPUNIT_SHA256)  tools/phpunit.phar" | sha256sum -c -
	@echo "$(PHPSTAN_SHA256)  tools/phpstan.phar" | sha256sum -c -
	@echo "$(WP_STUBS_SHA256)  tools/wordpress-stubs.php" | sha256sum -c -
	@echo "$(WP_CLI_SHA256)  tools/wp-cli.phar" | sha256sum -c -
	@echo "$(WP_CORE_FS_BASE_SHA256)  tools/wp-core/class-wp-filesystem-base.php" | sha256sum -c -
	@echo "$(WP_CORE_FS_DIRECT_SHA256)  tools/wp-core/class-wp-filesystem-direct.php" | sha256sum -c -
	@echo "$(WP_CORE_KSES_SHA256)  tools/wp-core/kses.php" | sha256sum -c -

.PHONY: test
test:
	@php tools/phpunit.phar -c tests/phpunit.xml

.PHONY: stan
stan:
	@php tools/phpstan.phar analyse -c phpstan.neon
	@php tools/phpstan.phar analyse -c tests/phpstan.neon

# wp-cli refuses to run as root unless told otherwise; a container build
# runs as root, a developer machine normally does not.
WP_CLI_FLAGS := $(if $(filter 0,$(shell id -u)),--allow-root)
POT_ARGS      := --domain=babilim --slug=babilim --exclude=tools,tests,scripts,docs,build,vendor,node_modules $(WP_CLI_FLAGS) --headers='{"Language-Team":""}'

# Regenerates languages/babilim.pot from every translatable string in
# the plugin. Commit the result whenever a string changes.
.PHONY: pot
pot:
	@mkdir -p languages
	@php tools/wp-cli.phar i18n make-pot . languages/babilim.pot $(POT_ARGS)

# Fails when languages/babilim.pot no longer matches the code (the
# creation date, which changes on every run, is ignored).
.PHONY: pot-check
pot-check:
	@tmp="$$(mktemp)"; \
	php tools/wp-cli.phar i18n make-pot . "$$tmp" $(POT_ARGS) >/dev/null || { rm -f "$$tmp"; exit 1; }; \
	grep -v '^"POT-Creation-Date:' languages/babilim.pot > "$$tmp.have"; \
	grep -v '^"POT-Creation-Date:' "$$tmp" > "$$tmp.want"; \
	if cmp -s "$$tmp.have" "$$tmp.want"; then \
		echo "OK: languages/babilim.pot is up to date"; rc=0; \
	else \
		echo "FAIL: languages/babilim.pot is stale -- run 'make pot'" >&2; rc=1; \
	fi; \
	rm -f "$$tmp" "$$tmp.have" "$$tmp.want"; exit $$rc

# Rebuilds the bundled translation files (languages/babilim-<locale>.po,
# .mo, .l10n.php) from a translation run directory holding
# babilim-<locale>.po files. L10N_SRC is required. To rebuild from the
# committed files (the pot only changed line references, so l10n-check says
# STALE (+0/-0)): make l10n L10N_SRC=languages
L10N_LOCALES ?= languages/LOCALES
# Allowed empty entries per locale (under 4% of the pot); read by l10n-check.
L10N_MAX_UNTRANSLATED ?= 15
export L10N_MAX_UNTRANSLATED

.PHONY: l10n
l10n:
	@test -n "$(L10N_SRC)" || { echo "usage: make l10n L10N_SRC=<translation run directory>" >&2; exit 2; }
	@bash scripts/l10n.sh "$(L10N_SRC)" "$(L10N_LOCALES)"

# Fails when a bundled translation is missing, stale against the pot,
# has a placeholder mismatch, or does not match its .mo/.l10n.php.
.PHONY: l10n-check
l10n-check:
	@bash scripts/l10n-check.sh "$(L10N_LOCALES)"

.PHONY: zip
zip:
	@php scripts/build-zip.php

.PHONY: contract-check
contract-check:
	@bash tests/contract/check_pinned.sh

.PHONY: public-check
public-check:
	@bash scripts/public-check.sh

.PHONY: public-history-check
public-history-check:
	@bash scripts/public-history-check.sh

# Gates a real release on no TODO-OWNER placeholder remaining in
# readme.txt (a field that is not yet known is written as TODO-OWNER
# during development). Deliberately not part of `make test`. Also runs
# public-check, since both are pre-release gates.
.PHONY: release-check
release-check: public-check pot-check l10n-check zip
	@version=$$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' readme.txt | head -n 1); \
	size=$$(stat -c %s "build/babilim-$$version.zip"); \
	[ $$size -le 10000000 ] || { echo "FAIL: zip is $$size bytes (> 10 MB)"; exit 1; }
	@if grep -q 'TODO-OWNER' readme.txt; then \
		echo "FAIL: readme.txt still has TODO-OWNER placeholders that must be filled in before release:" >&2; \
		grep -n 'TODO-OWNER' readme.txt >&2; \
		exit 1; \
	fi
	@echo "OK: no TODO-OWNER placeholders remain in readme.txt"

.PHONY: install-hooks
install-hooks:
	@git config core.hooksPath .githooks
	@echo "core.hooksPath set to .githooks -- prepare-commit-msg and commit-msg hooks are now active."

.PHONY: wporg-assets
wporg-assets:
	@node scripts/render-wporg-assets.mjs

# Renders the three wordpress.org directory screenshots from the neutral
# browser flow (needs Playwright on NODE_PATH; takes a couple of minutes).
.PHONY: wporg-screenshots
wporg-screenshots:
	@out="$$(mktemp -d)"; BABILIM_UX_SHOTS="$$out" bash tests/playground/run-neutral-ux-flow.sh "$$out" 1 screenshots || exit 1; \
	cp "$$out"/screenshot-1.png "$$out"/screenshot-2.png "$$out"/screenshot-3.png .wordpress-org/

# One release, one SVN commit: trunk + assets + tags/<version>. Needs svn
# and rsync; run `make release RELEASE_ARGS=--dry-run` first.
.PHONY: release
release:
	@bash scripts/release.sh $(RELEASE_ARGS)
