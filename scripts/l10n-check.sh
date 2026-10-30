#!/usr/bin/env bash
# Checks that the bundled translation files in languages/ are complete and
# current. Read-only: everything is rebuilt in a temporary directory and
# compared with what is committed.
#
# Usage: scripts/l10n-check.sh <locales-file>
#
#   <locales-file>  one WordPress locale per line; '#' starts a comment
#
# For every listed locale, one line per problem (every locale is checked;
# the first failure does not stop the run):
#
#   MISSING L                     the .po, .mo or .l10n.php is absent
#   STALE L (+added/-removed)     update-po against babilim.pot changes the .po
#   UNTRANSLATED L n              more empty msgstr entries than allowed
#   PLACEHOLDER L "<msgid>"       %s / %d / %1$s / %l set differs from the msgstr
#   DERIVED L (mo|l10n.php)       the file is not what the .po produces
#
# L10N_MAX_UNTRANSLATED is the allowed number of empty entries (the make
# targets default it to 15; run directly, the default is 0).
# The summary line is "OK: N locales" or "FAIL: ..." (HINT lines on how to
# recover follow it when any locale is STALE). Exit 0 only when clean.
#
# Recovering from STALE: when the pot changed only in line references,
# STALE shows (+0/-0) and nothing needs translating. Rebuild from the
# committed .po files: make l10n L10N_SRC=languages
#
# BABILIM_L10N_REPO overrides the repository root (default: the repo this
# script lives in).
set -euo pipefail

if [ "$#" -ne 1 ]; then
	echo "usage: $0 <locales-file>" >&2
	exit 2
fi

locales_file="$1"
repo="${BABILIM_L10N_REPO:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
pot="$repo/languages/babilim.pot"
wpcli="$repo/tools/wp-cli.phar"
lang_dir="$repo/languages"
max_untranslated="${L10N_MAX_UNTRANSLATED:-0}"

[ -f "$locales_file" ] || { echo "FAIL: locales file not found: $locales_file" >&2; exit 2; }
[ -f "$pot" ] || { echo "FAIL: $pot not found -- run 'make pot'" >&2; exit 2; }
[ -f "$wpcli" ] || { echo "FAIL: $wpcli not found -- run 'make tools'" >&2; exit 2; }
[[ "$max_untranslated" =~ ^[0-9]+$ ]] || { echo "FAIL: L10N_MAX_UNTRANSLATED must be a number" >&2; exit 2; }

flags=()
if [ "$(id -u)" = 0 ]; then
	flags+=(--allow-root)
fi

wp() {
	php "$wpcli" "$@" "${flags[@]}"
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# Prints UNTRANSLATED / PLACEHOLDER lines for one .po.
cat > "$tmp/check-po.php" <<'PHP'
<?php
// Invoked through `wp eval-file --skip-wordpress`: $args = [ locale, po, max ].
list( $locale, $po_file, $max ) = $args;
$placeholders = static function ( array $strings ): array {
	$found = array();
	foreach ( $strings as $s ) {
		if ( preg_match_all( '/%[0-9]*\$?[sd]|%l/', $s, $m ) ) {
			foreach ( $m[0] as $p ) {
				$found[ $p ] = true;
			}
		}
	}
	$found = array_keys( $found );
	sort( $found );
	return $found;
};
$translations = Gettext\Translations::fromPoFile( $po_file );
$empty        = 0;
foreach ( $translations as $t ) {
	if ( '' === $t->getOriginal() ) {
		continue;
	}
	$forms = array_merge( array( $t->getTranslation() ), $t->getPluralTranslations() );
	$forms = array_values( array_filter( $forms, static function ( $f ) {
		return '' !== $f;
	} ) );
	if ( ! $forms ) {
		++$empty;
		continue;
	}
	$source = array( $t->getOriginal() );
	if ( '' !== $t->getPlural() ) {
		$source[] = $t->getPlural();
	}
	if ( $placeholders( $source ) !== $placeholders( $forms ) ) {
		echo 'PLACEHOLDER ' . $locale . ' "' . str_replace( array( "\n", '"' ), array( '\n', '\"' ), $t->getOriginal() ) . "\"\n";
	}
}
if ( $empty > (int) $max ) {
	echo 'UNTRANSLATED ' . $locale . ' ' . $empty . "\n";
}
PHP

locale_re='^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$'
count=0
stale=0
bad=()

while IFS= read -r line || [ -n "$line" ]; do
	locale="${line%%#*}"
	locale="${locale//[[:space:]]/}"
	[ -n "$locale" ] || continue
	if ! [[ "$locale" =~ $locale_re ]]; then
		echo "FAIL: invalid locale in $locales_file: $locale" >&2
		exit 2
	fi
	count=$((count + 1))

	base="$lang_dir/babilim-$locale"
	if [ ! -s "$base.po" ] || [ ! -s "$base.mo" ] || [ ! -s "$base.l10n.php" ]; then
		echo "MISSING $locale"
		bad+=("$locale")
		continue
	fi

	problems=0
	work="$tmp/$locale"
	mkdir -p "$work/derived"

	# STALE: bring a copy of the .po up to the pot and compare.
	cp "$base.po" "$work/babilim-$locale.po"
	if wp i18n update-po "$pot" "$work/babilim-$locale.po" >/dev/null 2>&1; then
		if ! cmp -s "$base.po" "$work/babilim-$locale.po"; then
			added="$(diff <(grep '^msgid ' "$base.po") <(grep '^msgid ' "$work/babilim-$locale.po") | grep -c '^>' || true)"
			removed="$(diff <(grep '^msgid ' "$base.po") <(grep '^msgid ' "$work/babilim-$locale.po") | grep -c '^<' || true)"
			echo "STALE $locale (+$added/-$removed)"
			stale=1
			problems=1
		fi
	else
		echo "STALE $locale (update-po failed)"
		stale=1
		problems=1
	fi

	# UNTRANSLATED and PLACEHOLDER, judged on the committed .po.
	if out="$(wp eval-file "$tmp/check-po.php" "$locale" "$base.po" "$max_untranslated" --skip-wordpress 2>&1)"; then
		if [ -n "$out" ]; then
			echo "$out"
			problems=1
		fi
	else
		echo "PLACEHOLDER $locale \"(po parse failed)\""
		problems=1
	fi

	# DERIVED: rebuild .mo and .l10n.php from the committed .po.
	if wp i18n make-mo "$base.po" "$work/derived/" >/dev/null 2>&1 \
		&& cmp -s "$base.mo" "$work/derived/babilim-$locale.mo"; then
		:
	else
		echo "DERIVED $locale (mo)"
		problems=1
	fi
	if wp i18n make-php "$base.po" "$work/derived/" >/dev/null 2>&1 \
		&& cmp -s "$base.l10n.php" "$work/derived/babilim-$locale.l10n.php"; then
		:
	else
		echo "DERIVED $locale (l10n.php)"
		problems=1
	fi

	if [ "$problems" -ne 0 ]; then
		bad+=("$locale")
	fi
done < "$locales_file"

if [ "${#bad[@]}" -gt 0 ]; then
	echo "FAIL: ${#bad[@]} of $count locales need attention: ${bad[*]}"
	if [ "$stale" -ne 0 ]; then
		echo "HINT: STALE with (+0/-0) means only the pot's line references or comments changed, not the strings: no new translation run is needed. Rebuild from the committed .po files with: make l10n L10N_SRC=languages"
		echo "HINT: STALE with added or removed strings means the pot gained or lost strings; rebuilding the same way aligns the files, and new strings stay untranslated until the next translation run."
	fi
	exit 1
fi
echo "OK: $count locales"
