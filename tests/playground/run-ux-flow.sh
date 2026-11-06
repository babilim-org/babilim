#!/usr/bin/env bash
# Runs the browser flow (tests/playground/browser/ux-flow.mjs) against a real
# WordPress Playground server with the packaged plugin and a local
# 127.0.0.1-only mock service.
#
# Usage: run-ux-flow.sh <wp-version> <playground-cli-version> <out-dir> <callback|noverify>
#   callback:  the mock really calls the site's challenge endpoint and checks
#              the signature (the same as the real service does)
#   noverify:  the mock answers "verify" without calling back; results are then
#              a mock without a real callback, not proof of verification
#
# BABILIM_UX_PG_ARGS adds arguments to the Playground server command (for
# example --workers=1).
# BABILIM_UX_SCENARIOS (default "enable settings") limits which scenarios run;
# seam (it needs a real service; see run-seam.sh) and l10n (it needs the zh_TW
# site of run-neutral-ux-flow.sh) are not run here and leave a SKIP line in the
# results instead.
#
# Needs Playwright on NODE_PATH (checked first; "FAIL infra playwright
# missing" and exit 1 otherwise). Exits non-zero when any result line is a
# FAIL, the results file is empty, or it holds no PASS at all (only SKIP
# lines: nothing was tested). Each scenario (with and without the admin
# script) gets its own clean boot. Every process started here is stopped by
# recorded PID, and the script prints whether anything is left.

set -uo pipefail

WP_VERSION="${1:?usage: run-ux-flow.sh <wp-version> <playground-cli-version> <out-dir> <callback|noverify>}"
CLI_VERSION="${2:?}"
OUT_DIR="${3:?}"
MODE="${4:?}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
MOCK_PORT=8795
PORT=9510
RESULTS="${OUT_DIR}/ux-results-${WP_VERSION}-${MODE}.txt"
WORK="${OUT_DIR}/ux-work-${WP_VERSION}-${MODE}"

mkdir -p "${OUT_DIR}"
: > "${RESULTS}"

# Without Playwright nothing can be driven: say so in the results file
# and fail, rather than leave an empty file that looks like a pass.
if ! node -e "require('playwright')" > /dev/null 2>&1; then
	echo "FAIL infra playwright missing (set NODE_PATH to a directory that contains it)" >> "${RESULTS}"
	cat "${RESULTS}"
	exit 1
fi
rm -rf "${WORK}"
mkdir -p "${WORK}/plugin-src" "${WORK}/pkgs"
for item in babilim.php includes admin languages uninstall.php readme.txt; do
	cp -r "${PLUGIN_ROOT}/${item}" "${WORK}/plugin-src/${item}"
done

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
	kill_tree "$(cat "${WORK}/server.pid" 2>/dev/null)"
	kill_tree "$(cat "${WORK}/mock.pid" 2>/dev/null)"
}
trap cleanup EXIT

( BABILIM_PKG_DIR="${WORK}/pkgs" BABILIM_MOCK_LOG="${OUT_DIR}/ux-mock-received-${WP_VERSION}-${MODE}.jsonl" php -S 127.0.0.1:${MOCK_PORT} "${SCRIPT_DIR}/mock-server/router.php" \
	> "${OUT_DIR}/ux-mock-${WP_VERSION}-${MODE}.log" 2>&1 & echo $! > "${WORK}/mock.pid" )
sleep 1
# The report and errconsent scenarios read the mock's state and save screenshots.
export BABILIM_UX_MOCK="http://127.0.0.1:${MOCK_PORT}"
export BABILIM_UX_SHOTS="${BABILIM_UX_SHOTS:-${OUT_DIR}/shots-${WP_VERSION}-${MODE}}"

if [ "${MODE}" = "callback" ]; then
	RESET='{"callback":true}'
else
	RESET='{"verify_ok":true}'
fi

cat > "${WORK}/blueprint.json" <<EOF
{
  "preferredVersions": { "php": "8.1", "wp": "${WP_VERSION}" },
  "constants": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "BABILIM_API_BASE": "http://127.0.0.1:${MOCK_PORT}"
  },
  "login": true,
  "steps": [
    { "step": "activatePlugin", "pluginPath": "babilim/babilim.php" }
  ]
}
EOF

# Plain fixture plugins for the list-style scenarios (each is only a header;
# none is activated). Their slugs are what the mock service keys on; the
# neutral runner creates the same set. MOUNT_FIXTURES holds the extra --mount
# arguments for the scenario being booted.
MOUNT_FIXTURES=()
fixture_plugin() { # <slug> <name>
	mkdir -p "${WORK}/fixtures/$1"
	printf '<?php\n/**\n * Plugin Name: %s\n * Version: 1.0.0\n * Text Domain: %s\n */\n' "$2" "$1" > "${WORK}/fixtures/$1/$1.php"
	MOUNT_FIXTURES+=("--mount=${WORK}/fixtures/$1:/wordpress/wp-content/plugins/$1")
}

boot_server() {
	: > "${WORK}/server.log"
	( NODE_OPTIONS=--max-old-space-size=3072 npx "@wp-playground/cli@${CLI_VERSION}" server \
		--blueprint="${WORK}/blueprint.json" \
		--mount="${WORK}/plugin-src:/wordpress/wp-content/plugins/babilim" \
		"${MOUNT_FIXTURES[@]}" \
		--port=${PORT} --php=8.1 --verbosity=debug ${BABILIM_UX_PG_ARGS:-} \
		> "${WORK}/server.log" 2>&1 & echo $! > "${WORK}/server.pid" )
	local i
	for i in $(seq 1 180); do
		grep -qE "Ready!|^Error" "${WORK}/server.log" 2>/dev/null && break
		sleep 1
	done
	grep -q "Ready!" "${WORK}/server.log"
}

for scenario in ${BABILIM_UX_SCENARIOS:-enable settings}; do
if [ "${scenario}" = seam ]; then
	# seam talks to a real local instance of the service, never this mock:
	# only run-seam.sh runs it. Said in the results, not counted as a pass.
	echo "SKIP browser-seam runs only through run-seam.sh, against a real local service" >> "${RESULTS}"
	continue
fi
if [ "${scenario}" = l10n ]; then
	# l10n needs a zh_TW site, its expected strings and a debug.log check
	# that only the neutral runner sets up. Said in the results, not a pass.
	echo "SKIP browser-l10n runs only through run-neutral-ux-flow.sh, which sets up the zh_TW site" >> "${RESULTS}"
	continue
fi
MOUNT_FIXTURES=()
case "${scenario}" in
list | rowactions | noquota)
	fixture_plugin woocommerce "WooCommerce"
	fixture_plugin queued-demo "Queued Demo"
	fixture_plugin newer-demo "Newer Demo"
	fixture_plugin long-name-demo "A Remarkably Long Plugin Name That Has To Wrap Instead Of Being Cut Off In The List"
	;;
bulk)
	# bulk-a ... bulk-l, the same set the neutral runner creates.
	for letter in a b c d e f g h i j k l; do
		fixture_plugin "bulk-${letter}" "Bulk ${letter}"
	done
	;;
report)
	fixture_plugin report-demo "Report Demo"
	;;
errconsent)
	# A stand-in for the twice-daily WP-Cron run. In this Playground setup a
	# request to wp-cron.php takes the cron lock but never reaches the plugin's
	# `babilim_sync` event (cause not determined), so the scenario asks an
	# administrator-only URL to run that same event: the plugin's own sync
	# handler, exactly what cron would call. Test infrastructure only.
	mkdir -p "${WORK}/mu"
	cat > "${WORK}/mu/e2e-run-sync.php" <<'MUPHP'
<?php
add_action( 'admin_init', static function () {
	if ( isset( $_GET['e2e_run_sync'] ) && current_user_can( 'manage_options' ) ) {
		do_action( 'babilim_sync' );
	}
} );
MUPHP
	MOUNT_FIXTURES+=("--mount=${WORK}/mu:/wordpress/wp-content/mu-plugins")
	fixture_plugin errors-demo "Errors Demo"
	;;
notices)
	# Administrator-only URLs that seed and read back the plugin's own state
	# (see the file's header). Test infrastructure only.
	mkdir -p "${WORK}/mu"
	cp "${SCRIPT_DIR}/mu-plugins/e2e-notices.php" "${WORK}/mu/e2e-notices.php"
	MOUNT_FIXTURES+=("--mount=${WORK}/mu:/wordpress/wp-content/mu-plugins")
	fixture_plugin errors-demo "Errors Demo"
	;;
esac
for flag in "" "--no-js"; do
	if ! boot_server; then
		echo "FAIL infra playground did not boot (see ${WORK}/server.log)" >> "${RESULTS}"
		kill_tree "$(cat "${WORK}/server.pid" 2>/dev/null)"
		continue
	fi
	# The list and rowactions scenarios need the mock's "list" profile
	# and the two packages its Download buttons fetch (same as the neutral runner).
	profile=""
	case "${scenario}" in
	list | rowactions) profile=',"profile":"list"' ;;
	errconsent) profile=',"profile":"errors","fail_builds":["bd_0badc0de0bad"]' ;;
	notices) profile=',"profile":"errors"' ;;
	noquota) profile=',"profile":"noquota"' ;;
	bulk)
		# bulk-e answers its download with a 500; every download waits a little.
		profile=",\"profile\":\"bulk\",\"download_delay\":0.3,\"fail_builds\":[\"bd_$(printf '%s' bulk-ede_DE | md5sum | cut -c1-12)\"]"
		for letter in a b c d e f g h i j k l; do
			build="bd_$(printf '%s' "bulk-${letter}de_DE" | md5sum | cut -c1-12)"
			printf '{"server_build_id":"%s","kind":"plugin","slug":"bulk-%s","locale":"de_DE","version":"1.0.0","entries":{"Cart":"Warenkorb"}}' "${build}" "${letter}" > "${WORK}/pkg-spec.json"
			php "${SCRIPT_DIR}/mock-server/build-package.php" "${WORK}/pkgs/${build}.zip" "${WORK}/pkg-spec.json" > /dev/null
		done
		;;
	esac
	if [ "${scenario}" = report ]; then
		# The pack the report panel searches: one plain string and one with a
		# context. The mock's default profile offers it for the site language.
		build="bd_$(printf '%s' report-demoen_US | md5sum | cut -c1-12)"
		printf '{"server_build_id":"%s","kind":"plugin","slug":"report-demo","locale":"en_US","version":"1.0.0","entries":{"Add to cart":"Warenkorb: Hinzufügen","button\\u0004Save changes":"Änderungen sichern"}}' "${build}" > "${WORK}/pkg-spec.json"
		php "${SCRIPT_DIR}/mock-server/build-package.php" "${WORK}/pkgs/${build}.zip" "${WORK}/pkg-spec.json" > /dev/null
	fi
	if [ -n "${profile}" ]; then
		for slug in woocommerce newer-demo; do
			build="bd_$(printf '%s' "${slug}de_DE" | md5sum | cut -c1-12)"
			ver=1.0.0
			[ "${slug}" = newer-demo ] && ver=0.9
			printf '{"server_build_id":"%s","kind":"plugin","slug":"%s","locale":"de_DE","version":"%s","entries":{"Cart":"Warenkorb"}}' "${build}" "${slug}" "${ver}" > "${WORK}/pkg-spec.json"
			php "${SCRIPT_DIR}/mock-server/build-package.php" "${WORK}/pkgs/${build}.zip" "${WORK}/pkg-spec.json" > /dev/null
		done
	fi
	curl -s -XPOST "127.0.0.1:${MOCK_PORT}/_mock/reset" -d "${RESET%\}}${profile}}" > /dev/null
	# shellcheck disable=SC2086
	node "${SCRIPT_DIR}/browser/ux-flow.mjs" "http://127.0.0.1:${PORT}" "${RESULTS}" ${scenario} ${flag} > "${WORK}/flow-${scenario}${flag}.log" 2>&1
	kill_tree "$(cat "${WORK}/server.pid" 2>/dev/null)"
	: > "${WORK}/server.pid"
done
done

cp "${WORK}/pkgs/_registrations.json" "${OUT_DIR}/ux-mock-state-${WP_VERSION}-${MODE}.json" 2>/dev/null
cleanup
trap - EXIT
cat "${RESULTS}"

left=""
for pid in "${RECORDED_PIDS[@]}"; do
	kill -0 "${pid}" 2>/dev/null && left="${left} ${pid}"
done
if [ -z "${left}" ]; then echo "clean" >&2; else echo "still running:${left}" >&2; fi

# The exit code is the result: any FAIL line, or no results at all, is a failure.
status=0
if [ ! -s "${RESULTS}" ] || grep -q '^FAIL' "${RESULTS}"; then
	status=1
fi
# Only SKIP lines (say only seam was asked for) means nothing was tested:
# that is not a pass either.
if [ -s "${RESULTS}" ] && ! grep -q '^PASS' "${RESULTS}"; then
	echo "nothing was tested: every result is a SKIP" >&2
	status=1
fi
exit "${status}"
