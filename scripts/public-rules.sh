#!/usr/bin/env bash
# The one shared rule engine behind this repo's public-repo discipline
# checks (see CLAUDE.md's "Public-repo discipline"): no internal
# hostnames, no internal product/service names, no internal task/stage/
# document-reference codes, no Chinese/Japanese/Korean prose, no internal
# filesystem paths, no leaked quality-grading vocabulary.
#
# Every checker calls THIS script, so a file, a commit message, and a
# blob pulled out of history are all judged by exactly the same rules and
# the same exemptions -- nothing is copied between checkers:
#
#   scripts/public-check.sh          -> scan-files over the working tree
#   scripts/public-history-check.sh  -> scan-files over every historical
#                                       blob (materialized at its own
#                                       path) + scan-message over every
#                                       commit message, author/committer
#                                       and annotated tag
#   scripts/commit-msg-check.sh      -> scan-message over the message
#                                       being committed
#
# Usage:
#   public-rules.sh scan-files <root-dir> <list-file>
#       Scans the files named in <list-file> (one path per line, relative
#       to <root-dir>). Paths matter: the exemptions below are keyed on
#       them, which is why the history check rebuilds each blob at the
#       path it was committed under instead of scanning it anonymously.
#   public-rules.sh scan-message <message-file>
#       Scans a commit message (already cleaned the way git cleans it --
#       the commit-msg hook does that part, since only it sees the raw
#       editor buffer). No file-level exemption ever applies to a
#       message. The only special case is the mandatory two-line commit
#       attribution trailer, recognized by its exact whole-line shape
#       (see normalize_attribution below).
#
# Exit status: 0 clean, 1 violations found (listed on stderr), 2 the
# check itself could not run correctly (bad usage, a grep error, a
# missing tool). Callers treat anything non-zero as "not clean".
#
# Exemptions (scan-files only; messages get none of them):
#   - LICENSE is skipped entirely, but only while it is byte-for-byte the
#     published GPL-2.0 text (sha256 below). Its boilerplate uses an
#     ordinary English computing term that collides with one of the
#     banned words. An edited LICENSE is scanned like anything else.
#   - Two kinds of file that carry non-English text as real data are
#     exempt from the CJK check only (every other check still runs on
#     them): the one test fixture with native locale names, and the
#     bundled translation files languages/babilim-<locale>.po and
#     .l10n.php. Compiled languages/babilim-<locale>.mo files are binary
#     and not scanned at all; the .po they are built from is.
#   - In THIS script only, a line whose last characters are `#selfdata`
#     is excused from the banned word/phrase/path/section-mark checks --
#     the ban list has to live somewhere as literal data, and this is the
#     only place it lives. The marker never excuses a hostname, an IP, a
#     task/stage code, or CJK text, and it excuses nothing in any other
#     file.
#
# No network, no Python dependency -- pure grep. Every grep here checks
# BOTH its exit code and its stderr: 0 (matched) and 1 (no match) are the
# only acceptable outcomes; anything else is a broken check, and a broken
# check must never read as "clean". That is also why no grep result below
# is passed straight into another function as an argument (a failing
# `$(...)` used as an argument is silently ignored by `set -e`) -- each
# one is assigned to a variable first, where a failure does abort.
set -euo pipefail
shopt -s inherit_errexit

self_script="scripts/public-rules.sh"
selfdata_marker="#selfdata"
license_gpl2_sha256="8177f97513213526df2cf6184d8ff986c675afb514d4e68a404010521b880643"
cjk_exempt_pattern='^(tests/contract/v1/examples/meta\.locales\.ok\.json|languages/babilim-[A-Za-z0-9_]+\.(po|l10n\.php))$'
cjk_class='[\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7a3}\x{3000}-\x{303f}\x{ff00}-\x{ffef}]'

die() {
  echo "FAIL: $*" >&2
  exit 2
}

# The CJK check's \x{...} escapes above 0xFF (cjk_class, above) only
# work when PCRE runs in UTF-8 mode, and glibc grep decides that from
# the process's own locale -- not from the caller's terminal or file
# encoding. A caller in a non-UTF-8 locale (observed: some cloud
# sessions default to one) makes every one of those escapes fail with
# "character code point value in \x{} or \o{} is too large", turning
# this script into a broken check (exit 2, see below) instead of a real
# judgment. Force a UTF-8 locale here so behavior never depends on the
# caller's environment; if neither candidate is installed, fail loudly
# (die) rather than silently falling back to a locale where the CJK
# check can't work at all.
ensure_utf8_locale() {
  local loc
  for loc in C.UTF-8 en_US.UTF-8; do
    if LC_ALL="$loc" LANG="$loc" bash -c 'printf "\xe4\xb8\x80" | grep -qP "[\x{4e00}]"' 2>/dev/null; then
      export LC_ALL="$loc"
      export LANG="$loc"
      return 0
    fi
  done
  die "no usable UTF-8 locale found (tried C.UTF-8, en_US.UTF-8) -- the CJK check needs one"
}

# This system's grep must support -P (PCRE), which the CJK check needs.
require_pcre() {
  local errfile err rc
  errfile="$(mktemp)"
  set +e
  printf 'test\n' | grep -P 'e' >/dev/null 2>"$errfile"
  rc=$?
  set -e
  err="$(cat "$errfile")"
  rm -f "$errfile"
  if [ "$rc" -ne 0 ] || [ -n "$err" ]; then
    die "this grep doesn't support -P (PCRE), which the CJK check requires (exit $rc)${err:+: $err}"
  fi
}

# grep over a string instead of a file: grep_text <text> <grep args...>.
# Prints the matches; exit 1 from grep just means "no match" (prints
# nothing); anything else aborts the whole check.
grep_text() {
  local text="$1"; shift
  local out rc errfile err
  errfile="$(mktemp)"
  set +e
  out="$(printf '%s\n' "$text" | grep "$@" 2>"$errfile")"
  rc=$?
  set -e
  err="$(cat "$errfile")"
  rm -f "$errfile"
  if [ "$rc" -gt 1 ] || [ -n "$err" ]; then
    die "grep exited $rc while filtering text${err:+: $err}"
  fi
  printf '%s' "$out"
}

# Binary files are scanned as text: callers must not pass -I, which would
# make a binary file silently match nothing.
# Runs `grep -H --binary-files=text "$@" -- <file>` once per file in $1 (a newline-separated
# list) and prints the combined matches, UNFILTERED. Deliberately one
# call per file, never an xargs batch: per file, grep's exit code stays
# unambiguous (xargs collapses every non-zero outcome into 123).
run_grep() {
  local filelist="$1"; shift
  local f out out_all="" errfile err rc
  errfile="$(mktemp)"
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    set +e
    out="$(grep -H --binary-files=text "$@" -- "$f" 2>>"$errfile")"
    rc=$?
    set -e
    case "$rc" in
      0|1) : ;;
      *)
        err="$(cat "$errfile")"
        rm -f "$errfile"
        die "grep exited $rc while scanning $f (not a normal match/no-match result)${err:+: $err}"
        ;;
    esac
    if [ -n "$out" ]; then
      out_all="${out_all}${out}
"
    fi
  done <<< "$filelist"
  err="$(cat "$errfile")"
  rm -f "$errfile"
  if [ -n "$err" ]; then
    die "grep error while scanning: $err"
  fi
  printf '%s' "$out_all"
}

# Drops "file:line:..." hits that fall in $self_script on a line ending in
# $selfdata_marker. Only the banned word/phrase/path/section-mark checks
# call this.
drop_self_marked() {
  local hits="$1" kept="" hit file rest lineno srcline
  while IFS= read -r hit; do
    [ -z "$hit" ] && continue
    file="${hit%%:*}"
    if [ "$file" = "$self_script" ]; then
      rest="${hit#*:}"
      lineno="${rest%%:*}"
      srcline="$(sed -n "${lineno}p" -- "$file")"
      case "$srcline" in
        *"$selfdata_marker") continue ;;
      esac
    fi
    kept="${kept}${hit}
"
  done <<< "$hits"
  printf '%s' "$kept"
}

# ---------------------------------------------------------------------
# Hostnames. A deny-list of our real internal names can't live in a
# public script (it would leak them by definition), so this is an
# allow-list: anything hostname-shaped (label.tld) fails unless listed.
# Matched case-insensitively -- DNS is case-insensitive.
#
# The TLD list is deliberately not the full IANA list: short TLDs that
# collide with everyday identifiers and English words (`.id`, `.name`,
# `.is`, `.it`, `.as`, `.to`, `.in`, `.be`, `.do`, ...) are left out,
# because prose files scan "anywhere in the file". `co`/`xyz`/`site`/
# `me`/`info` are in despite that risk: code files never scan "anywhere"
# (see below), and in prose a short word glued to the previous one by a
# dot is rare. `.md`/`.sh`/`.py` are out because they're our own file
# extensions. `test`/`invalid`/`example` are in so the IANA-reserved
# allow-list entries can ever match.
#
# Code files (.php/.js/.jsx/.ts/.tsx/.css/.scss/.py) get a narrower scan:
# a hostname only counts right after `http://`/`https://`/`//`, or as the
# ENTIRE contents of a quoted string literal. Everything else keeps the
# plain "anywhere" scan.
#
# The commit-attribution vendor domains are NOT on this list: file
# content has no reason to carry them, and a commit message only gets
# them through the exact trailer lines (see normalize_attribution).
hostname_tlds="com|org|net|design|shop|cloud|ai|io|dev|app|tv|tw|cn|jp|hk|sg|kr|uk|de|fr|us|ca|au|co|xyz|site|me|info|test|invalid|example"
hostname_core="([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\\.)+(${hostname_tlds})"
code_ext_pattern='\.(php|js|jsx|ts|tsx|css|scss|py)$'
qpat=$'[\'"]'

hostname_allowed() {
  case "$1" in
    babilim.org|*.babilim.org) return 0 ;;
    wordpress.org|*.wordpress.org|wp.org|w.org) return 0 ;;
    php.net) return 0 ;;
    gnu.org|*.gnu.org) return 0 ;;
    github.com) return 0 ;;
    raw.githubusercontent.com) return 0 ;;
    phar.phpunit.de) return 0 ;;
    json-schema.org) return 0 ;;
    paddle.com|*.paddle.com) return 0 ;;
    example.com|*.example.com|example.org|*.example.org|example.net|*.example.net) return 0 ;;
    *.test|*.invalid|*.example) return 0 ;;
  esac
  return 1
}

# ---------------------------------------------------------------------
# IPv4 literals. Always allowed: RFC 5737 documentation ranges, loopback,
# and 0.0.0.0. Every IP-shaped token on a line is judged on its own.
#
# "This is a version number, not an IP" is only accepted when an explicit
# version context sits DIRECTLY in front of that exact number, in one of
# these shapes (case-insensitive; the marker must start at the beginning
# of the line or right after a character that is not a letter or digit,
# so `Conversion:` or `srv10...` never count):
#   vN.N.N.N
#   version<quote?><space*><: | = | =>><space*><quote?>N.N.N.N
#   version<quote><space*>,<space*><quote>N.N.N.N    (define( 'X_VERSION', 'x' ))
#   Tested up to: / Requires at least: / Stable tag:  N.N.N.N
# That covers `Version: x`, `version=x`, `const version = "x"`,
# `$version = 'x'`, `define( 'X_VERSION', 'x' )`, `"version": "x"`,
# `'version' => 'x'`, and the readme headers -- and nothing looser: a
# bare `requires x`, an unquoted `version, x`, the marker merely
# appearing earlier in the line, or with other words in between
# ("requires host x", "version lives at x"), excuses nothing, and neither
# does a marker AFTER the number. An IP glued to a preceding letter
# (`srv` directly followed by an address) is still found and judged.
#
# In code files an IP inside a URL (`http://x`, `//x`) never gets the
# version exception (a version-looking query string must not launder a
# URL); an IP that is the entire contents of a quoted string literal gets
# the same version check as prose -- that's how `version = "x"` and
# `define( ..., 'x' )` look.
ip_core="([0-9]{1,3}\\.){3}[0-9]{1,3}"
version_marker_re="(^|[^a-z0-9])((version[\"']?[[:space:]]*(=>|[:=])|tested up to[[:space:]]*:|requires at least[[:space:]]*:|stable tag[[:space:]]*:)[[:space:]]*[\"']?|version[\"'][[:space:]]*,[[:space:]]*[\"'])"
version_v_prefix_re="(^|[^A-Za-z0-9])v"

ip_allowed() {
  case "$1" in
    192.0.2.*|198.51.100.*|203.0.113.*|127.0.0.1|0.0.0.0) return 0 ;;
    *) return 1 ;;
  esac
}

# Prints every IP in $1 that sits directly after a version marker.
version_marked_ips() {
  local content="$1" m1 m2 out=""
  m1="$(grep_text "$content" -oE "${version_v_prefix_re}${ip_core}")"
  m2="$(grep_text "$content" -oiE "${version_marker_re}${ip_core}")"
  if [ -n "$m1" ]; then
    out="$(grep_text "$m1" -oE "$ip_core")"
  fi
  if [ -n "$m2" ]; then
    out="${out}
$(grep_text "$m2" -oE "$ip_core")"
  fi
  printf '%s' "$out"
}

# Appends to the global $ip_violations. $1: "file:line:content" hits
# (full line content); $2: 1 to allow the version exception, 0 not to.
collect_ip_violations() {
  local line_hits="$1" allow_version_exception="$2"
  local hit lfile lrest llineno lcontent all_ips ver_ips ip
  [ -z "$line_hits" ] && return 0
  while IFS= read -r hit; do
    [ -z "$hit" ] && continue
    lfile="${hit%%:*}"
    lrest="${hit#*:}"
    llineno="${lrest%%:*}"
    lcontent="${lrest#*:}"
    all_ips="$(grep_text "$lcontent" -oE "$ip_core")"
    [ -z "$all_ips" ] && continue
    ver_ips=""
    if [ "$allow_version_exception" -eq 1 ]; then
      ver_ips="$(version_marked_ips "$lcontent")"
    fi
    while IFS= read -r ip; do
      [ -z "$ip" ] && continue
      if ip_allowed "$ip"; then continue; fi
      case $'\n'"$ver_ips"$'\n' in
        *$'\n'"$ip"$'\n'*) continue ;;
      esac
      ip_violations="${ip_violations}${lfile}:${llineno}:${ip}
"
    done <<< "$all_ips"
  done <<< "$line_hits"
}

# ---------------------------------------------------------------------
# The scan itself. Must be called with the current directory = the root
# the paths in $1 are relative to. Sets the global $fail.
scan_paths() {
  local files="$1" f hits raw
  local code_files="" prose_files="" cjk_files="" review_jargon_files=""

  # A listed path that doesn't exist can't be scanned -- say so plainly
  # instead of surfacing a generic grep "No such file" error.
  local missing=""
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    if [ ! -e "$f" ]; then
      missing="${missing}${f}
"
    fi
  done <<< "$files"
  if [ -n "$missing" ]; then
    echo "FAIL: listed file(s) missing from disk (for a working tree: \`git rm\` or restore them first):" >&2
    printf '%s' "$missing" >&2
    exit 1
  fi

  # Literal identifiers -- case-insensitive, word-boundary matched. Only
  # generic words: a public script can't carry a list of our actual
  # internal names, codenames, or tool names (the list itself would be
  # the leak). Those are checked by a scan kept outside this repo, run
  # before anything is published. The two `[...]` patterns never match
  # their own source line, so they need no marker.
  local literal_word_patterns=(
    "kernel"     #selfdata
    "babilim[-_ ]server"
    "quality[-_]?level"
    "compat-admin-pas[s]"
  )
  local w
  for w in "${literal_word_patterns[@]}"; do
    raw="$(run_grep "$files" -niE "\\b${w}\\b")"
    hits="$(drop_self_marked "$raw")"
    if [ -n "$hits" ]; then
      echo "FAIL: banned word pattern '$w' found:" >&2
      echo "$hits" >&2
      fail=1
    fi
  done

  local literal_phrases=(
    "quality signal"    #selfdata
    "quality evidence"  #selfdata
  )
  local p
  for p in "${literal_phrases[@]}"; do
    raw="$(run_grep "$files" -ni -F "$p")"
    hits="$(drop_self_marked "$raw")"
    if [ -n "$hits" ]; then
      echo "FAIL: banned phrase '$p' found:" >&2
      echo "$hits" >&2
      fail=1
    fi
  done

  local path_literals=(
    "translator/out"  #selfdata
    "/home/"          #selfdata
    "~/dev"           #selfdata
  )
  for p in "${path_literals[@]}"; do
    raw="$(run_grep "$files" -n -F "$p")"
    hits="$(drop_self_marked "$raw")"
    if [ -n "$hits" ]; then
      echo "FAIL: banned path fragment '$p' found:" >&2
      echo "$hits" >&2
      fail=1
    fi
  done

  # CLAUDE.md is a dev-only planning document (never shipped -- see
  # .distignore) that sits beside this repo's actual plugin code and
  # test suite. A citation to it from a comment in includes/, admin/,
  # tests/, or the two root PHP entry points reads, to anyone reading
  # just that file, as a reference to something absent or private --
  # every comment there states the behavior or reasoning it needs
  # directly instead, so it stands on its own. Scoped narrowly: docs/
  # and the Makefile are themselves dev-only documents sitting right
  # beside CLAUDE.md and may cite it like any sibling document would,
  # so they're deliberately not in this scope. The only exemption is
  # tests/unit/test-plugin-check.php itself, whole-file: its own
  # assertions that CLAUDE.md is excluded from the release zip
  # necessarily name the real file, as a literal array entry
  # ('babilim/CLAUDE.md', 'CLAUDE.md', ...) -- a per-line "is this
  # actually a citation or a literal" pattern was tried and rejected: a
  # literal path element like 'babilim/CLAUDE.md' isn't quote-adjacent
  # on the CLAUDE.md side (there's a `/` there instead), and a prose
  # citation folded into a translatable string, e.g.
  # `__( 'See CLAUDE.md' )`, has a quote immediately after the filename
  # the same way a literal does -- no lookaround here reliably tells
  # those apart, so the one file that legitimately needs the literal is
  # exempted outright instead; every other file still gets the full
  # check, including every other line of this same file.
  local claude_md_scope_files
  claude_md_scope_files="$(grep_text "$files" -E '^(includes/|admin/|tests/)|^(babilim|uninstall)\.php$')"
  claude_md_scope_files="$(grep_text "$claude_md_scope_files" -v -x 'tests/unit/test-plugin-check.php')"
  if [ -n "$claude_md_scope_files" ]; then
    hits="$(run_grep "$claude_md_scope_files" -noF "CLAUDE.md")"
    if [ -n "$hits" ]; then
      echo "FAIL: a comment cites CLAUDE.md, a dev-only document this file must read independently of:" >&2
      echo "$hits" >&2
      fail=1
    fi
  fi

  # A shipped-code comment pointing at a tests/ or docs/ path -- either
  # a FILE (with an extension, so a plain English word like
  # "documentation" never matches) or a DIRECTORY (ending in `/`, e.g.
  # "see tests/contract/v1/ for the schema" -- no filename at all, but
  # still a path a shipped-code comment has no business citing). Neither
  # directory ships in the release zip (see .distignore), so from a
  # wp.org user's perspective that path doesn't exist either way.
  # Scoped to shipped code only (includes/, admin/, the two root PHP
  # entry points) -- tests/ referencing its own fixtures, and docs/
  # referencing other docs/ files, are both normal and untouched here.
  local shipped_code_files
  shipped_code_files="$(grep_text "$files" -E '^(includes/|admin/)|^(babilim|uninstall)\.php$')"
  if [ -n "$shipped_code_files" ]; then
    hits="$(run_grep "$shipped_code_files" -noE '\b(tests|docs)/[A-Za-z0-9_./-]*[A-Za-z0-9_-](\.[a-z]+|/)')"
    if [ -n "$hits" ]; then
      echo "FAIL: shipped code cites a tests/ or docs/ path (file or directory), neither of which ships in the release zip:" >&2
      echo "$hits" >&2
      fail=1
    fi
  fi

  # Internal task/stage/research-report codes -- case-sensitive on
  # purpose (the convention is always uppercase). Their regex source
  # never matches itself, so no marker is involved.
  hits="$(run_grep "$files" -noE '\b[ABCFH][0-9]{1,2}\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: task-code-shaped token found:" >&2
    echo "$hits" >&2
    fail=1
  fi
  hits="$(run_grep "$files" -noE '\bS[1-5]\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: stage-code-shaped token found:" >&2
    echo "$hits" >&2
    fail=1
  fi
  hits="$(run_grep "$files" -noE '\bR[0-9]{1,2}\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: research-report-code-shaped token found:" >&2
    echo "$hits" >&2
    fail=1
  fi
  # Review-process jargon (a code review's own vocabulary, not a
  # task/stage/research-report code shape) -- catches the same class of
  # private-process leak the checks above miss: a comment that cites a
  # private review's own numbering or terminology by name, instead
  # of just stating the underlying reasoning directly. task-report.md is
  # the one place this history legitimately belongs written out in plain
  # language (it never ships -- see .distignore), so it's excluded from
  # this group of checks only; every other check above and below still
  # runs on it. Each pattern's own source text below is written so it
  # never matches itself (like the task/stage/research-report patterns
  # above), except the "re-examination" one, whose target phrase has no
  # such escape and so is marked #selfdata instead like the section-mark
  # check further down.
  review_jargon_files="$(grep_text "$files" -v -x 'task-report.md')"
  hits="$(run_grep "$review_jargon_files" -noE '\bMinor [0-9]')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a numbered \"Minor\" finding):" >&2
    echo "$hits" >&2
    fail=1
  fi
  hits="$(run_grep "$review_jargon_files" -noE '\b[Ff]ix (round|cycle)\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a private fix-cycle reference):" >&2
    echo "$hits" >&2
    fail=1
  fi
  hits="$(run_grep "$review_jargon_files" -noE '\bruling [A-Z]-?[0-9]')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a lettered/numbered process citation):" >&2
    echo "$hits" >&2
    fail=1
  fi
  raw="$(run_grep "$review_jargon_files" -noE '\bre-review\b')"  #selfdata
  hits="$(drop_self_marked "$raw")"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a private re-examination reference):" >&2
    echo "$hits" >&2
    fail=1
  fi
  hits="$(run_grep "$review_jargon_files" -noE '\b([Tt]he|[Tt]his|task|task.s|original|own) brief\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a reference to a planning document):" >&2
    echo "$hits" >&2
    fail=1
  fi
  # A plan's own unit of work referred to as such, by a demonstrative
  # plus "task" -- the code and its docs describe behavior, not the
  # piece of work that produced it.
  hits="$(run_grep "$review_jargon_files" -noE '\b[Tt]his tasks?\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a reference to a unit of planned work):" >&2
    echo "$hits" >&2
    fail=1
  fi
  # A future unit of work referred to by name, the other direction in
  # time from the check just above -- a feature that has since shipped
  # (a settings screen, say) must be described as what it is now, not as
  # something still owed to a not-yet-written piece of work.
  hits="$(run_grep "$review_jargon_files" -noiE '\blater tasks?\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a reference to a future unit of planned work):" >&2
    echo "$hits" >&2
    fail=1
  fi
  # A comment that narrates its own edit history -- what a change was
  # called, or that it postdates some other change -- instead of just
  # stating the behavior/invariant that holds now.
  hits="$(run_grep "$review_jargon_files" -noiE '\bthis fix\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a comment naming its own change instead of stating current behavior):" >&2
    echo "$hits" >&2
    fail=1
  fi
  hits="$(run_grep "$review_jargon_files" -noiE '\bearlier version\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a comment dated relative to a prior revision instead of stating current behavior):" >&2
    echo "$hits" >&2
    fail=1
  fi
  # A review/fix iteration referred to by its position in a sequence
  # (an ordinal or "earlier"/"previous"/... before the word) or in the
  # possessive. Deliberately not the bare word: "round trip" is ordinary
  # technical English used throughout this repo.
  hits="$(run_grep "$review_jargon_files" -noE '\b([Ee]arlier|[Ff]irst|[Ss]econd|[Tt]hird|[Pp]revious|[Pp]rior|[Tt]his|[Ll]ast|[Ll]ater|[Nn]ext|[Rr]eview|pre-fix)[- ]rounds?\b|\b[Rr]ound.s\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (a reference to a review iteration):" >&2
    echo "$hits" >&2
    fail=1
  fi
  # A bare letter+digit finding label from an I-/N-prefixed private
  # numbering scheme -- the same shape the task/stage/
  # research-report-code checks above catch for other letter sets,
  # extended to these two. Checked (and found clean) against the whole
  # repository before going live: a broader candidate covering more
  # letters turned out to match a large body of unrelated, legitimate
  # content elsewhere and was deliberately left out; only I/N are
  # deployed here.
  hits="$(run_grep "$review_jargon_files" -noE '\b[IN][0-9]{1,2}\b')"
  if [ -n "$hits" ]; then
    echo "FAIL: review-jargon found (an I-/N-prefixed finding label):" >&2
    echo "$hits" >&2
    fail=1
  fi
  # Section mark: almost always "see section N of some private document".
  raw="$(run_grep "$files" -nF '§')"  #selfdata
  hits="$(drop_self_marked "$raw")"
  if [ -n "$hits" ]; then
    echo "FAIL: section-mark character found (almost always a private-document citation):" >&2
    echo "$hits" >&2
    fail=1
  fi

  code_files="$(grep_text "$files" -iE "$code_ext_pattern")"
  prose_files="$(grep_text "$files" -ivE "$code_ext_pattern")"

  local hostname_hits="" domain domain_lc violations=""
  if [ -n "$prose_files" ]; then
    raw="$(run_grep "$prose_files" -noiE "\\b${hostname_core}\\b")"
    hostname_hits="${hostname_hits}${raw}
"
  fi
  if [ -n "$code_files" ]; then
    raw="$(run_grep "$code_files" -noiE "(https?://|//)${hostname_core}")"
    hostname_hits="${hostname_hits}${raw}
"
    raw="$(run_grep "$code_files" -noiE "${qpat}${hostname_core}${qpat}")"
    hostname_hits="${hostname_hits}${raw}
"
  fi
  while IFS= read -r hits; do
    [ -z "$hits" ] && continue
    domain="${hits##*:}"
    domain="${domain#https://}"; domain="${domain#http://}"; domain="${domain#//}"
    domain="${domain#\'}"; domain="${domain#\"}"
    domain="${domain%\'}"; domain="${domain%\"}"
    domain_lc="$(printf '%s' "$domain" | tr 'A-Z' 'a-z')"
    if ! hostname_allowed "$domain_lc"; then
      violations="${violations}${hits}
"
    fi
  done <<< "$hostname_hits"
  if [ -n "$violations" ]; then
    echo "FAIL: hostname-shaped token not on the allow-list found:" >&2
    printf '%s' "$violations" >&2
    fail=1
  fi

  ip_violations=""
  if [ -n "$prose_files" ]; then
    raw="$(run_grep "$prose_files" -nE "(^|[^0-9])${ip_core}\\b")"
    collect_ip_violations "$raw" 1
  fi
  if [ -n "$code_files" ]; then
    raw="$(run_grep "$code_files" -niE "(https?://|//)${ip_core}")"
    collect_ip_violations "$raw" 0
    raw="$(run_grep "$code_files" -niE "${qpat}${ip_core}${qpat}")"
    collect_ip_violations "$raw" 1
  fi
  if [ -n "$ip_violations" ]; then
    echo "FAIL: IPv4 literal found (not an allowed documentation/loopback address):" >&2
    printf '%s' "$ip_violations" >&2
    fail=1
  fi

  cjk_files="$(grep_text "$files" -v -E "$cjk_exempt_pattern")"
  if [ -n "$cjk_files" ]; then
    hits="$(run_grep "$cjk_files" -nP "$cjk_class")"
    if [ -n "$hits" ]; then
      echo "FAIL: Chinese/Japanese/Korean characters found outside the exempt data files:" >&2
      echo "$hits" >&2
      fail=1
    fi
  fi
}

scan_files() {
  [ $# -eq 2 ] || die "usage: public-rules.sh scan-files <root-dir> <list-file>"
  local root="$1" listfile="$2" raw_files files="" f
  [ -d "$root" ] || die "scan root is not a directory: $root"
  [ -f "$listfile" ] || die "file list not found: $listfile"
  raw_files="$(cat -- "$listfile")"
  cd -- "$root"

  local license_exclude=""
  if [ -f LICENSE ]; then
    local license_sum
    license_sum="$(sha256sum LICENSE)"
    if [ "${license_sum%% *}" = "$license_gpl2_sha256" ]; then
      license_exclude="LICENSE"
    fi
  fi
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    if [ -n "$license_exclude" ] && [ "$f" = "$license_exclude" ]; then
      continue
    fi
    files="${files}${f}
"
  done <<< "$raw_files"
  # An empty list is a caller bug; a list holding only the exempt LICENSE
  # is simply clean.
  [ -n "$raw_files" ] || die "nothing to scan (empty file list)"
  [ -n "$files" ] || return 0

  # Compiled .mo files are binary; their text lives in the .po next to
  # them, which is still scanned.
  files="$(grep_text "$files" -v -E '^languages/babilim-[A-Za-z0-9_]+\.mo$')"
  [ -n "$files" ] || return 0

  scan_paths "$files"
}

# Rewrites ONLY the two mandatory commit-attribution trailer lines, and
# only when a whole line has exactly one of these shapes:
#   Co-Authored-By: Claude[ <name words>] <noreply@<vendor domain>>
#   Claude-Session: https://<vendor chat domain>/code/session_<id>
# In such a line the vendor address/URL is swapped for a neutral
# placeholder on an IANA-reserved domain; the rest of the line (the
# model name) is still scanned. Anywhere else -- a different line shape,
# extra text on the line, another path under the chat domain -- those
# domains are ordinary non-allow-listed hostnames and fail.
# (The domains below are written with escaped dots so this file's own
# hostname scan doesn't see them as hostnames.)
normalize_attribution() {
  sed -E \
    -e 's/^(Co-Authored-By: Claude( [A-Za-z0-9.]+){0,4} <)noreply@anthropic\.com>$/\1noreply@attribution.invalid>/' \
    -e 's#^(Claude-Session: )https://claude\.ai/code/session_[A-Za-z0-9_-]+$#\1https://attribution.invalid/session#' \
    -- "$1"
}

scan_message() {
  [ $# -eq 1 ] || die "usage: public-rules.sh scan-message <message-file>"
  local msg="$1" tmpdir
  [ -f "$msg" ] || die "message file not found: $msg"
  tmpdir="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$tmpdir'" EXIT
  mkdir "$tmpdir/root"
  normalize_attribution "$msg" > "$tmpdir/root/COMMIT_MSG"
  printf 'COMMIT_MSG\n' > "$tmpdir/list"
  scan_files "$tmpdir/root" "$tmpdir/list"
}

fail=0
ip_violations=""
ensure_utf8_locale
require_pcre
cmd="${1:-}"
[ $# -gt 0 ] && shift
case "$cmd" in
  scan-files) scan_files "$@" ;;
  scan-message) scan_message "$@" ;;
  *) die "usage: public-rules.sh scan-files <root-dir> <list-file> | scan-message <message-file>" ;;
esac

if [ "$fail" -ne 0 ]; then
  exit 1
fi
exit 0
