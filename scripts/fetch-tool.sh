#!/usr/bin/env bash
# Downloads one file for `make tools` and verifies its SHA256 before
# leaving it in place. Used only by the `tools` Makefile target -- this
# is the one place in the normal dev/test loop that touches the network
# (see CLAUDE.md's Testing rules: zero network in the tests themselves).
#
# Usage: fetch-tool.sh <url> <dest-path> <expected-sha256>
set -euo pipefail

url="$1"
dest="$2"
expected="$3"

mkdir -p "$(dirname "$dest")"

if [ -f "$dest" ]; then
  actual="$(sha256sum "$dest" | awk '{print $1}')"
  if [ "$actual" = "$expected" ]; then
    echo "OK (cached): $dest"
    exit 0
  fi
  echo "Stale/corrupt $dest (sha256 $actual != $expected) -- refetching" >&2
  rm -f "$dest"
fi

tmp="$(mktemp "${dest}.XXXXXX")"
trap 'rm -f "$tmp"' EXIT

curl -fsSL --max-time 120 -o "$tmp" "$url"

actual="$(sha256sum "$tmp" | awk '{print $1}')"
if [ "$actual" != "$expected" ]; then
  echo "FAIL: $url sha256 mismatch" >&2
  echo "  expected: $expected" >&2
  echo "  actual:   $actual" >&2
  exit 1
fi

mv "$tmp" "$dest"
trap - EXIT
echo "OK: $dest"
