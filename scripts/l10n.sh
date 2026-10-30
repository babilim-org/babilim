#!/usr/bin/env bash
# Rebuilds the bundled translation files in languages/ from a translation
# run directory.
#
# Usage: scripts/l10n.sh <src-dir> <locales-file>
#
#   <src-dir>       directory holding babilim-<locale>.po files
#   <locales-file>  one WordPress locale per line; '#' starts a comment
#
# For every listed locale this writes languages/babilim-<locale>.po (run
# annotations stripped, aligned to languages/babilim.pot), .mo and
# .l10n.php. A locale with no source file is reported and skipped; the
# script still exits 0 so one missing language never blocks the others.
#
# BABILIM_L10N_REPO overrides the repository root (default: the repo this
# script lives in); the pot and tools/wp-cli.phar are read from there.
set -euo pipefail

if [ "$#" -ne 2 ]; then
	echo "usage: $0 <src-dir> <locales-file>" >&2
	exit 2
fi

src_dir="$1"
locales_file="$2"
repo="${BABILIM_L10N_REPO:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
pot="$repo/languages/babilim.pot"
wpcli="$repo/tools/wp-cli.phar"
out_dir="$repo/languages"

[ -d "$src_dir" ] || { echo "FAIL: source directory not found: $src_dir" >&2; exit 2; }
[ -f "$locales_file" ] || { echo "FAIL: locales file not found: $locales_file" >&2; exit 2; }
[ -f "$pot" ] || { echo "FAIL: $pot not found -- run 'make pot'" >&2; exit 2; }
[ -f "$wpcli" ] || { echo "FAIL: $wpcli not found -- run 'make tools'" >&2; exit 2; }

flags=()
if [ "$(id -u)" = 0 ]; then
	flags+=(--allow-root)
fi

wp() {
	php "$wpcli" "$@" "${flags[@]}"
}

locale_re='^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$'
missing=()
failed=()

while IFS= read -r line || [ -n "$line" ]; do
	locale="${line%%#*}"
	locale="${locale//[[:space:]]/}"
	[ -n "$locale" ] || continue
	if ! [[ "$locale" =~ $locale_re ]]; then
		echo "FAIL: invalid locale in $locales_file: $locale" >&2
		exit 2
	fi

	src="$src_dir/babilim-$locale.po"
	if [ ! -f "$src" ]; then
		echo "SKIP $locale (no source)"
		missing+=("$locale")
		continue
	fi

	# Build in a staging directory; only a fully built locale is moved
	# into languages/, so a failure never leaves a partial result.
	stage="$(mktemp -d "$out_dir/l10n_stage_XXXXXX")"
	po="$stage/babilim-$locale.po"
	# Drop run annotations: comment lines starting "#. babilim:", "# babilim:"
	# (and the "babilim-" variants).
	sed -E '/^#\.? babilim[:-]/d' "$src" > "$po"
	if ! { wp i18n update-po "$pot" "$po" \
		&& wp i18n make-mo "$po" "$stage/" \
		&& wp i18n make-php "$po" "$stage/" \
		&& [ -s "$stage/babilim-$locale.mo" ] \
		&& [ -s "$stage/babilim-$locale.l10n.php" ]; } >/dev/null 2>&1; then
		rm -rf "$stage"
		echo "FAIL $locale (wp-cli error)"
		failed+=("$locale")
		continue
	fi

	untranslated="$(awk '
		function flush() { if (inentry && !header && !has) n++ }
		/^msgid / { flush(); inentry = 1; has = 0; header = ($0 == "msgid \"\""); instr = 0; next }
		/^msgstr(\[[0-9]+\])? / { instr = 1 }
		instr && /^(msgstr(\[[0-9]+\])? )?".+"$/ { sub(/^msgstr(\[[0-9]+\])? /, ""); if ($0 != "\"\"") has = 1 }
		END { flush(); print n + 0 }
	' "$po")"
	mv -f "$po" "$stage/babilim-$locale.mo" "$stage/babilim-$locale.l10n.php" "$out_dir/"
	rm -rf "$stage"
	echo "OK $locale (untranslated=$untranslated)"
done < "$locales_file"

if [ "${#missing[@]}" -gt 0 ]; then
	echo "missing: ${missing[*]}"
fi

if [ "${#failed[@]}" -gt 0 ]; then
	echo "failed: ${failed[*]}"
	exit 1
fi
