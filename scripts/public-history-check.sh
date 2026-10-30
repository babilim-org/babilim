#!/usr/bin/env bash
# History-wide public-repo discipline check: every commit message and
# every blob ever committed, on every ref -- not just the working tree
# (that's scripts/public-check.sh).
#
# Judged by the same rule engine as everything else
# (scripts/public-rules.sh), with the same exemptions:
#   - each distinct (blob, path) pair found in any commit's tree is
#     rebuilt in a scratch directory at the path it was committed under
#     and run through `scan-files`, so path-keyed exemptions (the
#     unmodified LICENSE, the CJK fixture, the rule engine's own
#     `#selfdata` ban-list lines) apply exactly as they do to the working
#     tree -- and to nothing else;
#   - each commit message goes through `scan-message`, which only
#     recognizes the exact two-line commit attribution trailer;
#   - so do each commit's author and committer ("name <email>"), and
#     each annotated tag's message and tagger -- they are published
#     along with the history just the same.
# So a history squashed down to one clean commit (whose tree passes
# `make public-check` and whose message is clean) passes this too.
#
# It is EXPECTED TO FAIL on this repo's current history: early commits
# predate the discipline and still carry internal names/codes. Before the
# repo (or its history) is made public or pushed anywhere reachable from
# outside, someone must deliberately rewrite history (squash to one clean
# initial commit, or a filter-repo/filter-branch pass with both a message
# and a tree filter) and re-run this until it's green. Not part of
# `make public-check`, not run per commit.
set -euo pipefail
shopt -s inherit_errexit
cd "$(dirname "${BASH_SOURCE[0]}")/.."
rules="$PWD/scripts/public-rules.sh"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail=0

# Runs the rule engine; prints its findings under $1 (a label) if it
# fails. Exit 2 (the check itself broke) aborts everything.
run_rules() {
  local label="$1"; shift
  local rc
  set +e
  bash "$rules" "$@" 2>"$work/out"
  rc=$?
  set -e
  case "$rc" in
    0) : ;;
    1)
      echo "FAIL [$label]:" >&2
      sed 's/^/    /' "$work/out" >&2
      fail=1
      ;;
    *)
      cat "$work/out" >&2
      echo "FAIL: rule engine could not run (exit $rc) while checking $label" >&2
      exit 2
      ;;
  esac
}

commits="$(git rev-list --all)"

echo "== Scanning commit messages, authors and committers (all refs) =="
commit_count=0
while IFS= read -r sha; do
  [ -z "$sha" ] && continue
  commit_count=$((commit_count + 1))
  git log -1 --format=%B "$sha" > "$work/msg"
  run_rules "commit $sha message" scan-message "$work/msg"
  git log -1 --format='%an <%ae>%n%cn <%ce>' "$sha" > "$work/msg"
  run_rules "commit $sha author/committer" scan-message "$work/msg"
done <<< "$commits"
echo "   $commit_count commit(s) checked."

echo "== Scanning annotated tags (message + tagger) =="
tag_count=0
tag_objects="$(git for-each-ref --format='%(objecttype) %(objectname) %(refname)' refs/tags)"
while IFS= read -r line; do
  [ -z "$line" ] && continue
  read -r otype osha oref <<< "$line"
  [ "$otype" = "tag" ] || continue
  tag_count=$((tag_count + 1))
  git cat-file tag "$osha" > "$work/tag"
  # Message: everything after the header's first blank line.
  sed '1,/^$/d' "$work/tag" > "$work/msg"
  run_rules "tag $oref ($osha) message" scan-message "$work/msg"
  sed -n 's/^tagger \(.*>\) [0-9][0-9]* [-+][0-9]*$/\1/p' "$work/tag" > "$work/msg"
  run_rules "tag $oref ($osha) tagger" scan-message "$work/msg"
done <<< "$tag_objects"
echo "   $tag_count annotated tag(s) checked."

echo "== Scanning blob content (every path of every commit on any ref) =="
declare -A seen=()
pairs="$work/pairs"
: > "$pairs"
while IFS= read -r sha; do
  [ -z "$sha" ] && continue
  git ls-tree -r -z --full-tree "$sha" >> "$pairs"
done <<< "$commits"

blob_count=0
while IFS= read -r -d '' entry; do
  meta="${entry%%$'\t'*}"
  path="${entry#*$'\t'}"
  read -r _mode otype osha <<< "$meta"
  [ "$otype" = "blob" ] || continue
  key="$osha $path"
  [ -n "${seen[$key]+x}" ] && continue
  seen[$key]=1
  blob_count=$((blob_count + 1))
  root="$work/tree"
  rm -rf "$root"
  mkdir -p "$root/$(dirname -- "$path")"
  git cat-file blob "$osha" > "$root/$path"
  printf '%s\n' "$path" > "$work/list"
  run_rules "blob $osha ($path)" scan-files "$root" "$work/list"
done < "$pairs"
echo "   $blob_count blob/path pair(s) checked."

if [ "$fail" -ne 0 ]; then
  echo >&2
  echo "FAIL: history is not clean -- expected until the pre-launch history rewrite (see CLAUDE.md)." >&2
  exit 1
fi
echo "OK: full history (all commit messages, authors/committers, annotated tags + all blobs on all refs) carries none of the banned public-repo strings"
