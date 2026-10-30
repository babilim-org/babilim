#!/usr/bin/env bash
# Runs this plugin's own WordPress Playground verification checks against
# one WordPress version: builds a clean plugin-source copy, builds the two fixture
# packages the checks need, starts a local
# 127.0.0.1-only mock service, boots a real WordPress Playground `server`
# (main checks, then a second, dedicated multisite boot for b10), reads
# back the results file each boot wrote through a host-mounted directory,
# and tears every background process down before exiting -- nothing is
# left running.
#
# Usage: run-checks.sh <wp-version> <playground-cli-version> <out-dir> [php-version]
#   wp-version:            e.g. 6.6, 6.8, latest
#   playground-cli-version: the pinned @wp-playground/cli version (see
#                           docs/playground-results.md for why 3.1.54,
#                           not @latest)
#   out-dir:                where per-version results/logs land (created
#                           if missing; never committed -- see .gitignore)
#   php-version:            optional, defaults to 8.1 -- e.g. 7.4 to check
#                           against this plugin's own declared PHP floor
#                           (see docs/playground-results.md's own PHP 7.4
#                           section).
#
# Every node invocation carries NODE_OPTIONS=--max-old-space-size=3072.
# Each boot is bounded by its own poll loop (up to 180 seconds, watching
# the boot's own log for "Ready!"/an error line), not a `timeout`
# wrapping npx directly -- either way, this script's own process never
# waits on a hung boot forever, and only one Playground instance ever
# runs at a time.

set -uo pipefail

WP_VERSION="${1:?usage: run-checks.sh <wp-version> <playground-cli-version> <out-dir> [php-version]}"
CLI_VERSION="${2:?usage: run-checks.sh <wp-version> <playground-cli-version> <out-dir> [php-version]}"
OUT_DIR="${3:?usage: run-checks.sh <wp-version> <playground-cli-version> <out-dir> [php-version]}"
PHP_VERSION="${4:-8.1}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

mkdir -p "${OUT_DIR}"
RESULTS_FILE="${OUT_DIR}/results-${WP_VERSION}.txt"
LOG_MAIN="${OUT_DIR}/server-${WP_VERSION}-main.log"
LOG_MS="${OUT_DIR}/server-${WP_VERSION}-multisite.log"
: > "${RESULTS_FILE}"

WORK="${OUT_DIR}/work-${WP_VERSION}"
rm -rf "${WORK}"
mkdir -p "${WORK}/plugin-src" "${WORK}/mnt-results" "${WORK}/mnt-results-ms" "${WORK}/pkgs" "${WORK}/lang-stub"

# switch_to_locale() (WP_Locale_Switcher, wp-includes/class-wp-locale-switcher.php)
# only ever switches to a locale present in its own $available_languages
# list -- built once, at boot, from get_available_languages()'s scan of
# WP_LANG_DIR's top level for "{locale}.mo" files. Mounted
# --mount-before-install (so it's already there the moment WordPress
# itself boots, not merely by the time our own wp-cli step runs) --
# get_available_languages() only globs filenames, but the CONTENT still
# matters (see the loop's own comment below): never a real WordPress core
# translation, just a real, valid, empty one.
for locale in ar da_DK de_DE en_GB es_ES fr_FR it_IT ja ko_KR nl_NL pt_PT sv_SE th zz_ZZ; do
	# A real, minimal, valid empty MO (Babilim_Mo_Writer's own header-only
	# output) -- NOT a bare empty file. A 0-byte file parses inconsistently
	# across WordPress core versions (observed directly: WP 6.5 treats it
	# as invalid and correctly finds nothing; WP 6.8/7.1 apparently accept
	# it as a valid-but-empty translation file, which changes downstream
	# load_plugin_textdomain()/is_readable() candidate behavior this
	# script's own checks were never meant to exercise). A real MO header
	# is unambiguous input on every version.
	php -r '
		if (!defined("ABSPATH")) { define("ABSPATH", __DIR__ . "/"); }
		require $argv[1];
		// A real wp.org "ar" core language pack carries a "text
		// direction" => "rtl" entry (WP_Locale reads it, via
		// _x("ltr","text direction"), to decide is_rtl()) -- every other
		// stub locale here stays content-free.
		$entries = ("ar" === $argv[3]) ? array( "text direction\4ltr" => "rtl" ) : array();
		file_put_contents($argv[2], Babilim_Mo_Writer::write($entries, array()));
	' "${PLUGIN_ROOT}/includes/class-babilim-mo-writer.php" "${WORK}/lang-stub/${locale}.mo" "${locale}"
done

echo "== ${WP_VERSION}: building clean plugin-source copy ==" >&2
for item in babilim.php includes admin languages uninstall.php readme.txt; do
	cp -r "${PLUGIN_ROOT}/${item}" "${WORK}/plugin-src/${item}"
done

echo "== ${WP_VERSION}: building fixture packages ==" >&2
MOCK_PORT=8791
echo "== ${WP_VERSION}: starting mock service on 127.0.0.1:${MOCK_PORT} ==" >&2
BABILIM_PKG_DIR="${WORK}/pkgs" php -S 127.0.0.1:${MOCK_PORT} "${SCRIPT_DIR}/mock-server/router.php" \
	> "${OUT_DIR}/mock-${WP_VERSION}.log" 2>&1 &
MOCK_PID=$!
# Every PID this script starts or tears down, for the final check below.
RECORDED_PIDS=("${MOCK_PID}")
sleep 1
if ! kill -0 "${MOCK_PID}" 2>/dev/null; then
	echo "FAIL infra mock service failed to start (see ${OUT_DIR}/mock-${WP_VERSION}.log)" >> "${RESULTS_FILE}"
	cat "${OUT_DIR}/mock-${WP_VERSION}.log" >&2
	exit 1
fi

# Teardown is by recorded PID only, never by matching command lines: a
# command-line pattern also matches any unrelated process whose own
# command line happens to contain it -- including the shell that
# invoked this script. Every background process this script starts has
# its PID recorded; npx starts its own children (npm exec, sh, node), so
# the whole tree under a recorded PID is collected first (by parent PID,
# `pgrep -P`), then sent SIGTERM, and only whatever is still running
# after a grace period is sent SIGKILL.
descendants() {
	local child
	for child in $(pgrep -P "$1" 2>/dev/null); do
		descendants "${child}"
		echo "${child}"
	done
}
kill_tree() {
	[ -n "${1:-}" ] || return 0
	local pids alive pid i
	pids="$(descendants "$1") $1"
	RECORDED_PIDS+=(${pids})
	kill -TERM ${pids} 2>/dev/null
	for i in $(seq 1 10); do
		alive=""
		for pid in ${pids}; do
			kill -0 "${pid}" 2>/dev/null && alive="${alive} ${pid}"
		done
		[ -z "${alive}" ] && return 0
		sleep 0.5
	done
	kill -KILL ${alive} 2>/dev/null
	return 0
}
cleanup() {
	kill_tree "$(cat "${WORK}/main.pid" 2>/dev/null)"
	kill_tree "$(cat "${WORK}/ms.pid" 2>/dev/null)"
	kill_tree "${MOCK_PID}"
}
trap cleanup EXIT

# WooCommerce's own current release requires WordPress 7.0 -- higher
# than two of the three WordPress versions this script boots (6.6, 6.8;
# it would activate fine on "latest", since 7.0 <= 7.1.2). This suite
# runs the exact same check across all three boots, which means the
# exact same WooCommerce release too -- so it has to be one whose own
# "Requires at least" is no higher than the lowest version tested (6.6).
# Pinned here to 9.5.0 (confirmed against its own readme.txt on wp.org:
# every release from 9.5.0 through 9.8.7 requires 6.6; 9.9.0 raised that
# to 6.7) -- installable on every WordPress version this suite boots.
cat > "${WORK}/blueprint-main.json" <<EOF
{
  "preferredVersions": { "php": "${PHP_VERSION}", "wp": "${WP_VERSION}" },
  "constants": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "BABILIM_API_BASE": "http://127.0.0.1:${MOCK_PORT}",
    "BABILIM_PG_RESULTS_FILE": "/wordpress/wp-content/babilim-results/results.txt",
    "BABILIM_PG_EXPECTED_PHP_VERSION": "${PHP_VERSION}"
  },
  "login": true,
  "steps": [
    { "step": "activatePlugin", "pluginPath": "babilim/babilim.php" },
    { "step": "installPlugin", "pluginData": { "resource": "url", "url": "https://downloads.wordpress.org/plugin/woocommerce.9.5.0.zip" }, "options": { "activate": true } },
    { "step": "installTheme", "themeData": { "resource": "wordpress.org/themes", "slug": "astra" }, "options": { "activate": true } },
    { "step": "wp-cli", "command": "wp eval-file /wordpress/wp-content/babilim-checks/controller.php main" }
  ]
}
EOF

PORT_MAIN=9500
echo "== ${WP_VERSION}: main checks boot (port ${PORT_MAIN}) ==" >&2
( NODE_OPTIONS=--max-old-space-size=3072 npx "@wp-playground/cli@${CLI_VERSION}" server \
	--blueprint="${WORK}/blueprint-main.json" \
	--mount="${WORK}/plugin-src:/wordpress/wp-content/plugins/babilim" \
	--mount="${SCRIPT_DIR}/checks:/wordpress/wp-content/babilim-checks" \
	--mount="${WORK}/mnt-results:/wordpress/wp-content/babilim-results" \
	--mount-before-install="${WORK}/lang-stub:/wordpress/wp-content/languages" \
	--port=${PORT_MAIN} --php="${PHP_VERSION}" --verbosity=debug \
	> "${LOG_MAIN}" 2>&1 & echo $! > "${WORK}/main.pid" )

for i in $(seq 1 180); do
	if grep -qE "Ready!|^Error" "${LOG_MAIN}" 2>/dev/null; then break; fi
	sleep 1
done
grep -qE "^Error" "${LOG_MAIN}" && echo "-- main boot log (Error seen) --" >&2 && tail -40 "${LOG_MAIN}" >&2

MAIN_PID=$(cat "${WORK}/main.pid" 2>/dev/null || echo "")
if [ -n "${MAIN_PID}" ]; then
	kill_tree "${MAIN_PID}"; : > "${WORK}/main.pid"
fi

if [ -f "${WORK}/mnt-results/results.txt" ]; then
	sed "s/^/[${WP_VERSION}] /" "${WORK}/mnt-results/results.txt" >> "${RESULTS_FILE}"
else
	echo "FAIL infra main boot produced no results.txt at all (see ${LOG_MAIN})" >> "${RESULTS_FILE}"
fi

cat > "${WORK}/blueprint-multisite.json" <<EOF
{
  "preferredVersions": { "php": "${PHP_VERSION}", "wp": "${WP_VERSION}" },
  "constants": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "BABILIM_API_BASE": "http://127.0.0.1:${MOCK_PORT}",
    "BABILIM_PG_RESULTS_FILE": "/wordpress/wp-content/babilim-results-ms/results.txt",
    "BABILIM_PG_EXPECTED_PHP_VERSION": "${PHP_VERSION}"
  },
  "login": true,
  "steps": [
    { "step": "activatePlugin", "pluginPath": "babilim/babilim.php" },
    { "step": "enableMultisite" },
    { "step": "wp-cli", "command": "wp eval-file /wordpress/wp-content/babilim-checks/controller.php multisite" }
  ]
}
EOF

PORT_MS=9501
echo "== ${WP_VERSION}: multisite (b10) boot (port ${PORT_MS}) ==" >&2
( NODE_OPTIONS=--max-old-space-size=3072 npx "@wp-playground/cli@${CLI_VERSION}" server \
	--blueprint="${WORK}/blueprint-multisite.json" \
	--mount="${WORK}/plugin-src:/wordpress/wp-content/plugins/babilim" \
	--mount="${SCRIPT_DIR}/checks:/wordpress/wp-content/babilim-checks" \
	--mount="${WORK}/mnt-results-ms:/wordpress/wp-content/babilim-results-ms" \
	--mount-before-install="${WORK}/lang-stub:/wordpress/wp-content/languages" \
	--port=${PORT_MS} --site-url="http://127.0.0.1" --php="${PHP_VERSION}" --verbosity=debug \
	> "${LOG_MS}" 2>&1 & echo $! > "${WORK}/ms.pid" )

for i in $(seq 1 180); do
	if grep -qE "Ready!|^Error" "${LOG_MS}" 2>/dev/null; then break; fi
	sleep 1
done
grep -qE "^Error" "${LOG_MS}" && echo "-- multisite boot log (Error seen) --" >&2 && tail -40 "${LOG_MS}" >&2

MS_PID=$(cat "${WORK}/ms.pid" 2>/dev/null || echo "")
if [ -n "${MS_PID}" ]; then
	kill_tree "${MS_PID}"; : > "${WORK}/ms.pid"
fi

if [ -f "${WORK}/mnt-results-ms/results.txt" ]; then
	sed "s/^/[${WP_VERSION}] /" "${WORK}/mnt-results-ms/results.txt" >> "${RESULTS_FILE}"
else
	echo "FAIL infra multisite boot produced no results.txt at all (see ${LOG_MS})" >> "${RESULTS_FILE}"
fi

cleanup
trap - EXIT

RESOLVED=$(grep -oE 'Resolved WordPress release URL: [^ ]+' "${LOG_MAIN}" | tail -1)
echo "== ${WP_VERSION}: done (${RESOLVED}) ==" >&2
echo "RESOLVED_URL ${RESOLVED#Resolved WordPress release URL: }" >> "${RESULTS_FILE}"

cat "${RESULTS_FILE}"

echo "== ${WP_VERSION}: verifying no lingering processes ==" >&2
# By the PIDs this script itself recorded -- never a command-line match.
left=""
for pid in "${RECORDED_PIDS[@]}"; do
	kill -0 "${pid}" 2>/dev/null && left="${left} ${pid}"
done
if [ -z "${left}" ]; then echo "clean" >&2; else echo "still running:${left}" >&2; fi
