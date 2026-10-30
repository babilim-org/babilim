#!/usr/bin/env bash
# Public-repo discipline guard for the working tree -- the regression test
# for the rule set CLAUDE.md spells out under "Public-repo discipline".
# The rules themselves live in scripts/public-rules.sh (shared with the
# history check and the commit-msg hook); this script only decides WHAT
# to scan: every file this repo tracks OR could accidentally commit next
# (`git ls-files -co --exclude-standard`: tracked + untracked-but-not-
# gitignored), this script and scripts/public-rules.sh included.
#
# It does NOT cover commit messages or history -- see
# `make public-history-check` and the commit-msg hook (CLAUDE.md,
# "`make public-check` only covers the working tree").
set -euo pipefail
shopt -s inherit_errexit
cd "$(dirname "${BASH_SOURCE[0]}")/.."

files="$(git ls-files -co --exclude-standard)"
if [ -z "$files" ]; then
  echo "FAIL: nothing found to scan (git ls-files -co --exclude-standard)" >&2
  exit 1
fi

listfile="$(mktemp)"
trap 'rm -f "$listfile"' EXIT
printf '%s\n' "$files" > "$listfile"

set +e
bash scripts/public-rules.sh scan-files . "$listfile"
rc=$?
set -e
if [ "$rc" -ne 0 ]; then
  exit "$rc"
fi
echo "OK: tracked files carry none of the banned public-repo strings"
