#!/usr/bin/env bash
# Verifies that tests/contract/v1/ (a manual copy of the service repo's
# contract/v1/) still matches what tests/contract/PINNED says it should be,
# and that every file inside it still matches its own recorded sha256.
#
# No network, no Python/jsonschema dependency on purpose — this repo (the
# public wp.org plugin) should not need a Python toolchain just to check its
# contract copy is in sync.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

if [ ! -f PINNED ]; then
  echo "FAIL: tests/contract/PINNED is missing" >&2
  exit 1
fi
if [ ! -f v1/SHA256SUMS ]; then
  echo "FAIL: tests/contract/v1/SHA256SUMS is missing (copy the contract in full)" >&2
  exit 1
fi

pinned_version="$(grep '^version=' PINNED | cut -d= -f2-)"
pinned_sha="$(grep '^sha256sums_sha256=' PINNED | cut -d= -f2-)"

if [ ! -f v1/VERSION ]; then
  echo "FAIL: tests/contract/v1/VERSION is missing" >&2
  exit 1
fi
actual_version="$(cat v1/VERSION)"
if [ "$actual_version" != "$pinned_version" ]; then
  echo "FAIL: copied contract version ($actual_version) != PINNED version ($pinned_version)" >&2
  exit 1
fi

actual_sha="$(sha256sum v1/SHA256SUMS | awk '{print $1}')"
if [ "$actual_sha" != "$pinned_sha" ]; then
  echo "FAIL: v1/SHA256SUMS hash mismatch (pinned=$pinned_sha actual=$actual_sha)" >&2
  echo "The contract copy was likely updated without re-pinning; sync from the service repo's contract/v1/ and update PINNED." >&2
  exit 1
fi

echo "== v1/: per-file integrity against v1/SHA256SUMS =="
(cd v1 && sha256sum -c SHA256SUMS)

echo "== v1/: file set on disk matches SHA256SUMS listing =="
# sha256sum -c alone only checks the files it's told about; it says nothing
# about a file that exists on disk but isn't listed (or vice versa). Compare
# the two sets explicitly so a stray extra/missing file doesn't pass silently.
# Covers everything under v1/ (not just schemas/examples/VERSION/package-layout.md)
# except SHA256SUMS itself and a tools/ dir if one ever gets copied here --
# same rule server-side's validate_examples.py uses, so a file added to the
# canonical contract (e.g. status_index.json, which lives at v1/ root on
# purpose) is caught here too, not just on the server side.
listed="$(mktemp)"
actual="$(mktemp)"
diffout="$(mktemp)"
trap 'rm -f "$listed" "$actual" "$diffout"' EXIT
awk '{ $1=""; sub(/^ /, ""); print }' v1/SHA256SUMS | sort > "$listed"
find v1 -type f ! -name SHA256SUMS ! -path 'v1/tools/*' | sed 's#^v1/##' | sort > "$actual"
if ! diff -u "$listed" "$actual" > "$diffout" 2>&1; then
  echo "FAIL: v1/ file set does not match v1/SHA256SUMS listing:" >&2
  cat "$diffout" >&2
  exit 1
fi

echo "OK: plugin contract copy matches PINNED v$actual_version"
