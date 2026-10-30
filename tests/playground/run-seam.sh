#!/usr/bin/env bash
# Seam run: this working tree's plugin, in a neutral local WordPress, talking
# to a real local instance of the service. Starting that service (and its
# database, registry and builds) is the caller's job; this script only needs
# to be told where it is and how to nudge it.
#
# Usage: run-seam.sh <out-dir>
#
# Environment (all required):
#   BABILIM_SEAM_WP_SERVER   command that builds a neutral WordPress and starts
#                            it with php -S: it is called as
#                            $BABILIM_SEAM_WP_SERVER <out-dir> <port> --plugin-src <this tree>
#                            --api-base <service url>
#   BABILIM_SEAM_API         base URL of the running service
#   BABILIM_SEAM_SLUG        plugin slug the service has a build for (de_DE)
#   BABILIM_SEAM_OLD_VERSION version that build was made for
#   BABILIM_SEAM_NEW_VERSION a newer version the service knows about
#   BABILIM_SEAM_RUN_DUE_CMD shell command that makes the service evaluate its
#                            scheduled candidates once
#   BABILIM_UX_PASS          administrator password of the neutral WordPress
#                            (set by the caller; there is no default)
#   NODE_PATH                must contain Playwright
#
# The plugin's own fixture component (a header-only plugin at the old
# version) is created here; the "upgrade" edits its header. The WordPress
# server is stopped by its recorded PID. Exit code: non-zero on any FAIL line.

set -uo pipefail

OUT_DIR="${1:?usage: run-seam.sh <out-dir>}"
for var in BABILIM_SEAM_WP_SERVER BABILIM_SEAM_API BABILIM_SEAM_SLUG BABILIM_SEAM_OLD_VERSION BABILIM_SEAM_NEW_VERSION BABILIM_SEAM_RUN_DUE_CMD BABILIM_UX_PASS; do
	[ -n "${!var:-}" ] || { echo "FAIL infra ${var} is not set" >&2; exit 2; }
done
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PORT="${BABILIM_SEAM_WP_PORT:-8790}"
mkdir -p "${OUT_DIR}"
OUT_DIR="$(cd "${OUT_DIR}" && pwd)"
RESULTS="${OUT_DIR}/seam-results.txt"
: > "${RESULTS}"

# shellcheck disable=SC2086
${BABILIM_SEAM_WP_SERVER} "${OUT_DIR}/wpenv" "${PORT}" --plugin-src "${PLUGIN_ROOT}" --api-base "${BABILIM_SEAM_API}" > "${OUT_DIR}/wp-env.log" 2>&1
rc=$?
PID_FILE="${OUT_DIR}/wpenv/php-s.pid"
stop_wp() {
	local pid
	pid="$(cat "${PID_FILE}" 2>/dev/null)"
	[ -n "${pid}" ] || return 0
	kill -TERM "${pid}" 2>/dev/null
	for _ in $(seq 1 20); do kill -0 "${pid}" 2>/dev/null || break; sleep 0.25; done
	kill -0 "${pid}" 2>/dev/null && kill -KILL "${pid}" 2>/dev/null
	kill -0 "${pid}" 2>/dev/null && echo "wordpress pid ${pid} STILL ALIVE" >&2 || echo "wordpress pid ${pid} stopped" >&2
}
trap stop_wp EXIT
[ "${rc}" -eq 0 ] || { tail -5 "${OUT_DIR}/wp-env.log" >&2; echo "FAIL infra the WordPress did not start" >> "${RESULTS}"; cat "${RESULTS}"; exit 2; }

ROOT="${OUT_DIR}/wpenv/wp"
WP="${OUT_DIR}/wpenv/wp-cli.sh"
SLUG="${BABILIM_SEAM_SLUG}"
mkdir -p "${ROOT}/wp-content/languages" "${ROOT}/wp-content/plugins/${SLUG}"
header() { printf '<?php\n/**\n * Plugin Name: Seam Fixture\n * Version: %s\n * Text Domain: %s\n */\n' "$1" "${SLUG}" > "${ROOT}/wp-content/plugins/${SLUG}/${SLUG}.php"; }
header "${BABILIM_SEAM_OLD_VERSION}"
# The site's language is German: WordPress only accepts a language it can see
# a core .mo for, so an empty one is put in place (no translation content).
php -r 'echo pack("V*", 0x950412de, 0, 0, 28, 0, 0, 0);' > "${ROOT}/wp-content/languages/de_DE.mo"
"${WP}" option update WPLANG de_DE > "${OUT_DIR}/language.log" 2>&1
"${WP}" plugin activate "$(basename "${PLUGIN_ROOT}")" > "${OUT_DIR}/activate.log" 2>&1 || { tail -5 "${OUT_DIR}/activate.log" >&2; echo "FAIL infra the plugin did not activate" >> "${RESULTS}"; cat "${RESULTS}"; exit 2; }

# The "upgrade" the browser flow triggers: rewrite the fixture's header.
export BABILIM_SEAM_UPGRADE_CMD="printf '<?php\n/**\n * Plugin Name: Seam Fixture\n * Version: ${BABILIM_SEAM_NEW_VERSION}\n * Text Domain: ${SLUG}\n */\n' > '${ROOT}/wp-content/plugins/${SLUG}/${SLUG}.php' && '${WP}' transient delete --all"
export BABILIM_SEAM_RUN_DUE_CMD
export BABILIM_UX_PASS
node "${SCRIPT_DIR}/browser/ux-flow.mjs" "http://compat.localhost:${PORT}" "${RESULTS}" seam > "${OUT_DIR}/flow-seam.log" 2>&1
flow=$?
cat "${RESULTS}"
[ "${flow}" -eq 0 ] && ! grep -q '^FAIL' "${RESULTS}" || exit 1
