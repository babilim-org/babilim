#!/usr/bin/env bash
# Runs the browser flow (tests/playground/browser/ux-flow.mjs) against a plain
# WordPress served by PHP's built-in web server on a SQLite database, with the
# packaged plugin and the local 127.0.0.1-only mock service. No WordPress
# Playground involved: this is the neutral environment the results in
# docs/playground-results.md refer to, and every download below is a public
# release artifact.
#
# Usage: run-neutral-ux-flow.sh <out-dir> [runs] [scenarios]
#   out-dir    results and per-run logs go here (outside the repository)
#   runs       how many times each scenario is repeated, default 1
#   scenarios  space separated, default "enable settings list"; also bulk, rowactions and l10n; each scenario runs
#              with and without the admin script (--no-js), on a fresh database
#
# Environment:
#   BABILIM_NEUTRAL_WORKERS  PHP_CLI_SERVER_WORKERS, default 4
#   BABILIM_NEUTRAL_CACHE    download cache, default ~/.cache/babilim-compat
#   NODE_PATH                must contain Playwright
#
# The PHP CLI needs pdo_sqlite. If it has none, extract the distribution's
# php-sqlite3 package into <cache>/phpext (apt-get download php8.3-sqlite3 and
# dpkg -x it there) and this script loads the extension from that directory.
#
# Cached downloads (WordPress 6.6.4, the wp.org SQLite Database Integration
# plugin 3.0.2, wp-cli 2.11.0) are fetched with curl on first use. Every
# process started here is stopped by its recorded PID; the script prints
# whether anything is left. Exit code is non-zero on any FAIL line.

set -uo pipefail

OUT_DIR="${1:?usage: run-neutral-ux-flow.sh <out-dir> [runs] [scenarios]}"
RUNS="${2:-1}"
SCENARIOS="${3:-enable settings list}"

WP_VERSION=6.6.4
SQLITE_VERSION=3.0.2
WPCLI_VERSION=2.11.0
CACHE="${BABILIM_NEUTRAL_CACHE:-${HOME}/.cache/babilim-compat}"
WORKERS="${BABILIM_NEUTRAL_WORKERS:-4}"
SITE_PORT=8801
MOCK_PORT=8797
SITE_HOST="compat.localhost"
SITE_URL="http://${SITE_HOST}:${SITE_PORT}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
WORK="${OUT_DIR}/neutral-work"
RESULTS="${OUT_DIR}/neutral-results.txt"
ROOT="${WORK}/wp"

mkdir -p "${OUT_DIR}" "${CACHE}"
rm -rf "${WORK}"
mkdir -p "${WORK}"
: > "${RESULTS}"

fail_infra() {
	echo "FAIL infra $1" >> "${RESULTS}"
	cat "${RESULTS}"
	exit 1
}

fetch() { # <url> <file>
	[ -s "$2" ] || curl -fsSL -o "$2" "$1" || { rm -f "$2"; fail_infra "could not download $1"; }
}

node -e "require('playwright')" > /dev/null 2>&1 || fail_infra "playwright missing (set NODE_PATH to a directory that contains it)"

fetch "https://wordpress.org/wordpress-${WP_VERSION}.zip" "${CACHE}/wordpress-${WP_VERSION}.zip"
fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_VERSION}.zip" "${CACHE}/sqlite-database-integration-${SQLITE_VERSION}.zip"
fetch "https://github.com/wp-cli/wp-cli/releases/download/v${WPCLI_VERSION}/wp-cli-${WPCLI_VERSION}.phar" "${CACHE}/wp-cli-${WPCLI_VERSION}.phar"

# php -d extension=... only when the CLI has no pdo_sqlite of its own.
PHP_EXT=()
if ! php -m | grep -qi '^pdo_sqlite$'; then
	EXT_DIR="$(find "${CACHE}/phpext" -name pdo_sqlite.so -printf '%h\n' 2>/dev/null | head -1)"
	[ -n "${EXT_DIR}" ] || fail_infra "php has no pdo_sqlite (see the header of this script)"
	PHP_EXT=(-d "extension=${EXT_DIR}/pdo_sqlite.so" -d "extension=${EXT_DIR}/sqlite3.so")
fi
php_run() { php "${PHP_EXT[@]}" "$@"; }

python3 - "${CACHE}" "${ROOT}" "${WP_VERSION}" "${SQLITE_VERSION}" <<'PY' || fail_infra "could not unpack the downloads"
import sys, zipfile, shutil, os
cache, root, wp, sq = sys.argv[1:5]
tmp = root + ".unpack"
zipfile.ZipFile(f"{cache}/wordpress-{wp}.zip").extractall(tmp)
shutil.move(f"{tmp}/wordpress", root)
shutil.rmtree(tmp)
zipfile.ZipFile(f"{cache}/sqlite-database-integration-{sq}.zip").extractall(f"{root}/wp-content/plugins")
src = open(f"{root}/wp-content/plugins/sqlite-database-integration/db.copy").read()
src = src.replace("{SQLITE_IMPLEMENTATION_FOLDER_PATH}", f"{root}/wp-content/plugins/sqlite-database-integration").replace("{SQLITE_PLUGIN}", "sqlite-database-integration/load.php")
open(f"{root}/wp-content/db.php", "w").write(src)
PY

mkdir -p "${ROOT}/wp-content/plugins/babilim"
for item in babilim.php includes admin languages uninstall.php readme.txt; do
	cp -r "${PLUGIN_ROOT}/${item}" "${ROOT}/wp-content/plugins/babilim/${item}"
done

# Plain fixture plugins for the list scenario (each is only a header; none
# is activated). Their slugs are what the local mock service keys on.
fixture_plugin() { # <slug> <name>
	mkdir -p "${ROOT}/wp-content/plugins/$1"
	printf '<?php\n/**\n * Plugin Name: %s\n * Version: 1.0.0\n * Text Domain: %s\n */\n' "$2" "$1" > "${ROOT}/wp-content/plugins/$1/$1.php"
}
# A fresh WordPress download has no languages directory yet; sites that
# ever installed a language have one.
mkdir -p "${ROOT}/wp-content/languages"
fixture_plugin woocommerce "WooCommerce"
fixture_plugin queued-demo "Queued Demo"
fixture_plugin newer-demo "Newer Demo"
for letter in a b c d e f g h i j k l; do
	fixture_plugin "bulk-${letter}" "Bulk ${letter}"
done
fixture_plugin long-name-demo "A Remarkably Long Plugin Name That Has To Wrap Instead Of Being Cut Off In The List"

cat > "${ROOT}/wp-config.php" <<EOF
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '${WORK}/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', '${SITE_URL}' );
define( 'WP_SITEURL', '${SITE_URL}' );
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', false );
define( 'BABILIM_API_BASE', 'http://127.0.0.1:${MOCK_PORT}' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
EOF

PASS="neutral-$(head -c8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
export BABILIM_UX_PASS="${PASS}"
# The l10n scenario's expected strings, read from the bundled file itself.
BABILIM_UX_EXPECT_ENABLE="$(php -r 'echo (include $argv[1])["messages"]["Enable Babilim"];' "${PLUGIN_ROOT}/languages/babilim-zh_TW.l10n.php")"
BABILIM_UX_EXPECT_SETTINGS_TITLE="$(php -r 'echo (include $argv[1])["messages"]["Babilim settings"];' "${PLUGIN_ROOT}/languages/babilim-zh_TW.l10n.php")"
export BABILIM_UX_EXPECT_ENABLE BABILIM_UX_EXPECT_SETTINGS_TITLE

wp() { php_run -d memory_limit=512M "${CACHE}/wp-cli-${WPCLI_VERSION}.phar" --path="${ROOT}" --url="${SITE_URL}" "$@"; }

RECORDED_PIDS=()
descendants() {
	local child
	for child in $(pgrep -P "$1" 2>/dev/null); do
		descendants "${child}"
		echo "${child}"
	done
}
kill_tree() {
	[ -n "${1:-}" ] || return 0
	local pids
	pids="$(descendants "$1") $1"
	RECORDED_PIDS+=(${pids})
	kill -TERM ${pids} 2>/dev/null
	sleep 1
	kill -KILL ${pids} 2>/dev/null
	return 0
}
cleanup() {
	kill_tree "$(cat "${WORK}/site.pid" 2>/dev/null)"
	kill_tree "$(cat "${WORK}/mock.pid" 2>/dev/null)"
}
trap cleanup EXIT

( BABILIM_PKG_DIR="${WORK}/pkgs" php -S 127.0.0.1:${MOCK_PORT} "${SCRIPT_DIR}/mock-server/router.php" \
	> "${WORK}/mock.log" 2>&1 & echo $! > "${WORK}/mock.pid" )
mkdir -p "${WORK}/pkgs"

# A fresh database for every run; then the server.
fresh_site() { # <scenario>
	local profile=""
	[ "$1" = list ] && profile=',"profile":"list"'
	# bulk: bulk-e answers its download with a 500; every download waits a little.
	local failing_build
	failing_build="bd_$(printf '%s' bulk-ede_DE | md5sum | cut -c1-12)"
	[ "$1" = bulk ] && profile=",\"profile\":\"bulk\",\"download_delay\":0.3,\"fail_builds\":[\"${failing_build}\"]"
	[ "$1" = rowactions ] && profile=',"profile":"list"'
	[ "$1" = l10n ] && profile=',"profile":"list"'
	kill_tree "$(cat "${WORK}/site.pid" 2>/dev/null)"
	: > "${WORK}/site.pid"
	rm -f "${ROOT}"/wp-content/database/.ht.sqlite*
	rm -rf "${ROOT}/wp-content/languages/babilim" "${ROOT}/wp-content/languages/zh_TW.mo"
	wp core install --title=Neutral --admin_user=admin --admin_password="${PASS}" --admin_email=admin@example.org --skip-email > "${WORK}/install.log" 2>&1 || return 1
	wp plugin activate babilim >> "${WORK}/install.log" 2>&1 || return 1
	# l10n: a zh_TW site. WPLANG alone installs no core language pack (no download), so core strings stay English and only the plugin's own bundled file is translated.
	# core only accepts a WPLANG whose "{locale}.mo" exists in the languages directory, so an empty, header-only one stands in for the pack.
	if [ "$1" = l10n ]; then
		php -r 'define("ABSPATH", __DIR__ . "/"); require $argv[1]; file_put_contents($argv[2], Babilim_Mo_Writer::write(array(), array()));' "${PLUGIN_ROOT}/includes/class-babilim-mo-writer.php" "${ROOT}/wp-content/languages/zh_TW.mo" || return 1
		wp option update WPLANG zh_TW >> "${WORK}/install.log" 2>&1 || return 1
	fi
	# Pretty permalinks, so that /wp-json/ (the challenge endpoint the mock
	# calls back) is routed by php -S's index.php fallback.
	wp option update permalink_structure '/%postname%/' >> "${WORK}/install.log" 2>&1 || return 1
	# A long native name for one language, so the list is checked with a wide first column.
	wp option update babilim_locale_names '{"es_DO":"Español de República Dominicana"}' --format=json >> "${WORK}/install.log" 2>&1 || return 1
	curl -s -XPOST "127.0.0.1:${MOCK_PORT}/_mock/reset" -d "{\"callback\":true${profile}}" > /dev/null
	if [ "$1" = bulk ]; then
		local letter
		for letter in a b c d e f g h i j k l; do
			local id="bd_$(printf '%s' "bulk-${letter}de_DE" | md5sum | cut -c1-12)"
			printf '{"server_build_id":"%s","kind":"plugin","slug":"bulk-%s","locale":"de_DE","version":"1.0.0","entries":{"Cart":"Warenkorb"}}' "${id}" "${letter}" > "${WORK}/pkg-spec.json"
			php "${SCRIPT_DIR}/mock-server/build-package.php" "${WORK}/pkgs/${id}.zip" "${WORK}/pkg-spec.json" > /dev/null
		done
	fi
	if [ "$1" = list ] || [ "$1" = rowactions ] || [ "$1" = l10n ]; then
		local spec="${WORK}/pkg-spec.json"
		printf '{"server_build_id":"bd_%s","kind":"plugin","slug":"woocommerce","locale":"de_DE","version":"1.0.0","entries":{"Cart":"Warenkorb"}}' "$(printf '%s' woocommercede_DE | md5sum | cut -c1-12)" > "${spec}"
		php "${SCRIPT_DIR}/mock-server/build-package.php" "${WORK}/pkgs/bd_$(printf '%s' woocommercede_DE | md5sum | cut -c1-12).zip" "${spec}" > /dev/null
		printf '{"server_build_id":"bd_%s","kind":"plugin","slug":"newer-demo","locale":"de_DE","version":"0.9","entries":{"Cart":"Warenkorb"}}' "$(printf '%s' newer-demode_DE | md5sum | cut -c1-12)" > "${spec}"
		php "${SCRIPT_DIR}/mock-server/build-package.php" "${WORK}/pkgs/bd_$(printf '%s' newer-demode_DE | md5sum | cut -c1-12).zip" "${spec}" > /dev/null
	fi
	( PHP_CLI_SERVER_WORKERS="${WORKERS}" php "${PHP_EXT[@]}" -S 127.0.0.1:${SITE_PORT} -t "${ROOT}" \
		> "${WORK}/site.log" 2>&1 & echo $! > "${WORK}/site.pid" )
	local i
	for i in $(seq 1 30); do
		[ "$(curl -s -o /dev/null -w '%{http_code}' "${SITE_URL}/wp-login.php")" = 200 ] && return 0
		sleep 1
	done
	return 1
}

for run in $(seq 1 "${RUNS}"); do
	for scenario in ${SCENARIOS}; do
		for flag in "" "--no-js"; do
			if ! fresh_site "${scenario}"; then
				echo "FAIL infra the site did not start (run ${run}, ${scenario} ${flag}; see ${WORK}/install.log)" >> "${RESULTS}"
				continue
			fi
			# The site hides PHP messages from the page (WP_DEBUG_DISPLAY is off), so they only reach this log; start it empty.
			rm -f "${WORK}/debug.log"
			# shellcheck disable=SC2086
			node "${SCRIPT_DIR}/browser/ux-flow.mjs" "${SITE_URL}" "${RESULTS}" ${scenario} ${flag} > "${WORK}/flow-${run}-${scenario}${flag}.log" 2>&1
			echo "run ${run} ${scenario}${flag:+ ${flag}}: exit $?" >&2
			if [ "${scenario}" = l10n ]; then
				# The translated pages must not have logged a PHP warning, notice or deprecation that involves the plugin.
				bad="$(grep -iE 'PHP (Warning|Notice|Deprecated|Fatal error)' "${WORK}/debug.log" 2>/dev/null | grep -i babilim | head -3)"
				if [ -z "${bad}" ]; then
					echo "PASS browser-l10n${flag:+ ${flag}} no PHP warning, notice or deprecation involving babilim in debug.log" >> "${RESULTS}"
				else
					echo "FAIL browser-l10n${flag:+ ${flag}} debug.log has a PHP message involving babilim: ${bad:0:300}" >> "${RESULTS}"
				fi
			fi
		done
	done
done

cleanup
trap - EXIT
cat "${RESULTS}"

left=""
for pid in "${RECORDED_PIDS[@]}"; do
	kill -0 "${pid}" 2>/dev/null && left="${left} ${pid}"
done
if [ -z "${left}" ]; then echo "clean" >&2; else echo "still running:${left}" >&2; fi

status=0
if [ ! -s "${RESULTS}" ] || grep -q '^FAIL' "${RESULTS}"; then
	status=1
fi
exit "${status}"
