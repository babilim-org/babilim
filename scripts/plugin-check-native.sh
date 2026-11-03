#!/usr/bin/env bash
# Runs the phpcs-based Plugin Check checks natively against a directory.
#
# Usage: scripts/plugin-check-native.sh <plugin-dir> [report-file]
#
# Uses Plugin Check's own bundled phpcs (tools/plugin-check/vendor/bin/phpcs)
# and each check's exact --standard/--sniffs/--runtime-set pair as listed in
# docs/plugin-check-results.md, plus --runtime-set minimum_wp_version 6.6.
# Prints one line per check: "check_id errors warnings". The full phpcs
# reports are appended to the report file (default: native-report.txt in
# $TMPDIR). Exits non-zero when any check reports any error or warning.
#
# This is a thin wrapper around the documented command, not a new grader.
# minified_files has no ordinary sniff (phpcs answers "No sniffs were
# registered"), so it is replaced by a direct file scan, see below.

set -u

DIR="${1:-}"
REPORT="${2:-${TMPDIR:-/tmp}/plugin-check-native-report.txt}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHPCS="${PHPCS:-$ROOT/tools/plugin-check/vendor/bin/phpcs}"
RULESET="${PLUGIN_REVIEW_RULESET:-$ROOT/tools/plugin-check/phpcs-rulesets/plugin-review.xml}"
MIN_WP="6.6"

if [ -z "$DIR" ] || [ ! -d "$DIR" ]; then
	echo "usage: $0 <plugin-dir> [report-file]" >&2
	exit 2
fi
if [ ! -f "$PHPCS" ] || [ ! -f "$RULESET" ]; then
	echo "Plugin Check phpcs or ruleset not found (run make tools): $PHPCS" >&2
	exit 2
fi
if [ ! -f "$DIR/babilim.php" ]; then
	echo "no babilim.php in $DIR: wrong directory or a failed unzip" >&2
	exit 2
fi

: > "$REPORT"
JSON="$(mktemp "${TMPDIR:-/tmp}/pcn-json.XXXXXX")"
trap 'rm -f "$JSON"' EXIT

failed=0
rows=0

# run_check <check_id> <standard> <sniffs|-> [extra runtime-set value ...]
run_check() {
	local id="$1" standard="$2" sniffs="$3"
	shift 3
	local args=( "--standard=$standard" --runtime-set minimum_wp_version "$MIN_WP" )
	if [ "$sniffs" != "-" ]; then
		args+=( "--sniffs=$sniffs" )
	fi
	while [ "$#" -gt 0 ]; do
		args+=( --runtime-set "$1" "$2" )
		shift 2
	done
	args+=( --extensions=php "--report-full=$REPORT.part" "--report-json=$JSON" -q "$DIR" )

	rm -f "$REPORT.part"
	: > "$JSON"
	"$PHP_BIN" "$PHPCS" "${args[@]}" > /dev/null 2>> "$REPORT"
	local rc=$?
	# phpcs exits 0 (clean), 1/2 (findings), 3+ (tool failure).
	if [ "$rc" -ge 3 ]; then
		echo "$id ERROR(phpcs exit $rc)"
		failed=1
		rows=$((rows + 1))
		return
	fi
	local counts
	counts="$("$PHP_BIN" -r '$j = json_decode( (string) file_get_contents( $argv[1] ), true ); echo (int) ( $j["totals"]["errors"] ?? -1 ), " ", (int) ( $j["totals"]["warnings"] ?? -1 );' "$JSON")"
	{
		echo "=== $id ($counts) ==="
		if [ -f "$REPORT.part" ]; then cat "$REPORT.part"; fi
	} >> "$REPORT"
	rm -f "$REPORT.part"
	echo "$id $counts"
	rows=$((rows + 1))
	case "$counts" in
		"0 0") ;;
		*) failed=1 ;;
	esac
}

PHP_BIN="${PHP_BIN:-php}"

run_check plugin_review_phpcs "$RULESET" -
run_check direct_db_queries WordPress WordPress.DB.DirectDatabaseQuery
run_check direct_db PluginCheck PluginCheck.Security.DirectDB
run_check late_escaping WordPress WordPress.Security.EscapeOutput
run_check i18n_usage WordPress WordPress.WP.I18n text_domain babilim
run_check enqueued_scripts_in_footer WordPress WordPress.WP.EnqueuedResourceParameters
run_check performant_wp_query_params "WordPress,WordPressVIPMinimum" "WordPress.DB.SlowDBQuery,WordPressVIPMinimum.Performance.WPQueryParams"
run_check enqueued_resources WordPress WordPress.WP.EnqueuedResources
run_check setting_sanitization PluginCheck PluginCheck.CodeAnalysis.SettingSanitization
run_check write_file PluginCheck PluginCheck.CodeAnalysis.WriteFile
run_check prefixing WordPress WordPress.NamingConventions.PrefixAllGlobals prefixes babilim
run_check verify_nonce PluginCheck PluginCheck.Security.VerifyNonce
run_check safe_redirect WordPress WordPress.Security.SafeRedirect
run_check offloading_files PluginCheck "PluginCheck.CodeAnalysis.EnqueuedResourceOffloading,PluginCheck.CodeAnalysis.Offloading"
run_check ai_provider PluginCheck PluginCheck.CodeAnalysis.AIProvider
run_check localhost PluginCheck PluginCheck.CodeAnalysis.Localhost
run_check php_error_reporting PluginCheck PluginCheck.CodeAnalysis.PHPErrorReporting

# minified_files: phpcs cannot run it (see header), so scan the files the
# way docs/plugin-check-results.md describes: any .js/.css file that is
# named *.min.* or has a line over 1000 characters is a finding. A tree
# with no .js/.css file at all is trivially clean.
min_errors=0
{
	echo "=== minified_files (file scan) ==="
	while IFS= read -r -d '' f; do
		case "$f" in
			*.min.js | *.min.css)
				echo "$f: minified file name"
				min_errors=$((min_errors + 1))
				;;
		esac
		if awk 'length($0) > 1000 { found = 1 } END { exit found ? 0 : 1 }' "$f"; then
			echo "$f: line longer than 1000 characters"
			min_errors=$((min_errors + 1))
		fi
	done < <(find "$DIR" -type f \( -name '*.js' -o -name '*.css' \) -print0)
} >> "$REPORT"
echo "minified_files $min_errors 0"
rows=$((rows + 1))
if [ "$min_errors" -ne 0 ]; then
	failed=1
fi

echo "checks: $rows, report: $REPORT" >&2
exit "$failed"
