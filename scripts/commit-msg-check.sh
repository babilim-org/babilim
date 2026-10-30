#!/usr/bin/env bash
# Body of this repo's two opt-in git hooks (see CLAUDE.md, "Public-repo
# discipline"). The rules are the shared ones in scripts/public-rules.sh
# (scan-message) -- the same engine `make public-check` and
# `make public-history-check` use, IPv4 check included.
#
#   commit-msg-check.sh record-source <msg-file> [<source> [<sha>]]
#       Called by .githooks/prepare-commit-msg. Records where git says
#       the message comes from (git's own second argument: `message` for
#       -m/-F, `template`, `merge`, `squash`, `commit`, or empty for a
#       plain editor commit), tied to the git process running the
#       commit. Never blocks a commit: if recording fails, the check
#       below simply falls back to its strictest mode.
#   commit-msg-check.sh <msg-file>
#       Called by .githooks/commit-msg. Rejects the commit if the message
#       breaks the rules.
#
# What gets scanned is exactly what git is going to store -- never less.
# git only removes text from a message in two cases, and this script
# only removes it when it can establish that git will:
#
#   1. Comment lines (starting with core.commentChar / commentString)
#      are dropped only under the `strip` cleanup mode: --cleanup=strip /
#      commit.cleanup=strip, or the default mode on a commit that opens
#      the editor. With -m / -F (and no -e) git's default is
#      `whitespace`, which KEEPS `#` lines -- so they are scanned. So
#      they are under `verbatim`, `whitespace` and `scissors`.
#   2. Everything from the scissors line down is dropped only when git
#      cuts there: with -v / commit.verbose, or under --cleanup=scissors
#      on an editor commit. A scissors line typed into a -m message is
#      otherwise kept by git, and scanned here.
#
# To tell which case applies, this script reads the command line of the
# git process that invoked the hook (/proc/<parent>/cmdline) and checks
# it against the source prepare-commit-msg recorded for that same
# process. Whatever it can't establish -- no /proc (non-Linux), a
# missing or mismatched record, a git subcommand other than `commit`, an
# option it doesn't recognize (abbreviated long options included),
# --fixup/--squash, core.commentChar=auto, an unrecognized config value
# -- means nothing is removed and every line is scanned (stricter, never
# looser). The cost of that fallback is at worst a false rejection of
# git's own comment block, e.g. when a branch name trips a rule.
#
# Any error -- unreadable message file, unreadable git config, a failing
# grep/sed/awk, the rule engine being unable to run -- rejects the commit.
# Nothing here falls back to "let it through".
#
# Not installed by default -- a git hook is local-machine config. Opt in
# with `make install-hooks` (or `git config core.hooksPath .githooks`).
set -euo pipefail
shopt -s inherit_errexit

record_name="babilim-commit-source"

# Identity of the git process running the hooks: "<pid> <starttime>
# <sha256 of its NUL-separated argv>". Prints nothing if unreadable.
git_process_identity() {
  local pid="$1" stat rest start sum
  [ -r "/proc/$pid/stat" ] && [ -r "/proc/$pid/cmdline" ] || return 0
  stat="$(cat "/proc/$pid/stat" 2>/dev/null)" || return 0
  rest="${stat##*) }"
  # Field 22 of stat is starttime; $rest begins at field 3.
  start="$(printf '%s\n' "$rest" | awk '{print $20}')" || return 0
  sum="$(sha256sum < "/proc/$pid/cmdline" 2>/dev/null)" || return 0
  sum="${sum%% *}"
  [ -n "$start" ] && [ -n "$sum" ] || return 0
  printf '%s %s %s' "$pid" "$start" "$sum"
}

if [ "${1:-}" = "record-source" ]; then
  # Never fails the commit; the commit-msg side is the fail-closed one.
  (
    set +e
    rec="$(git rev-parse --git-path "$record_name" 2>/dev/null)" || exit 0
    [ -n "$rec" ] || exit 0
    rm -f "$rec"
    src="${3:-}"
    case "$src" in
      ""|message|template|merge|squash|commit) ;;
      *) exit 0 ;;
    esac
    ident="$(git_process_identity "$PPID")"
    [ -n "$ident" ] || exit 0
    printf '%s\n%s\n' "$ident" "source=$src" > "$rec.tmp" && mv -f "$rec.tmp" "$rec"
  ) || true
  exit 0
fi

reject() {
  echo "COMMIT REJECTED: $*" >&2
  exit 1
}
tmp=""
trap 'rc=$?; [ -n "$tmp" ] && rm -f "$tmp"; if [ "$rc" -ne 0 ] && [ "$rc" -ne 1 ]; then echo "COMMIT REJECTED: commit-msg-check.sh failed (exit $rc) -- refusing to let the commit through unchecked." >&2; exit 1; fi' EXIT

[ $# -eq 1 ] || reject "usage: commit-msg-check.sh <path-to-commit-message-file>"
msg_file="$1"
[ -f "$msg_file" ] && [ -r "$msg_file" ] || reject "couldn't read the message file: $msg_file"
rules="$(dirname "${BASH_SOURCE[0]}")/public-rules.sh"
[ -f "$rules" ] || reject "rule engine not found: $rules"

# `git config` exits 1 when the key is unset; anything else is an error
# (e.g. a malformed config file or value).
read_config() {
  local out rc
  set +e
  out="$(git config "$@" 2>/dev/null)"
  rc=$?
  set -e
  case "$rc" in
    0) printf '%s' "$out" ;;
    1) : ;;
    *) reject "couldn't read git config ${*: -1} (exit $rc)" ;;
  esac
}
comment="$(read_config --get core.commentString)"
[ -n "$comment" ] || comment="$(read_config --get core.commentChar)"
[ -n "$comment" ] || comment="#"
cfg_cleanup="$(read_config --get commit.cleanup)"
cfg_verbose="$(read_config --type=bool-or-int --get commit.verbose)"

# The recorded source, if it belongs to the git process running us.
# The record is consumed either way, so a stale one is never reused.
recorded_source="?"
rec="$(git rev-parse --git-path "$record_name" 2>/dev/null)" || rec=""
if [ -n "$rec" ] && [ -f "$rec" ]; then
  ident="$(git_process_identity "$PPID")"
  rec_ident="$(sed -n 1p "$rec")"
  rec_src="$(sed -n 2p "$rec")"
  rm -f "$rec"
  if [ -n "$ident" ] && [ "$ident" = "$rec_ident" ]; then
    case "$rec_src" in
      source=*) recorded_source="${rec_src#source=}" ;;
    esac
  fi
fi

# Parses the invoking git's argv (git's option grammar for `commit`).
# Returns 1 for anything not fully understood; on success sets:
#   has_mf      -m/--message or -F/--file given
#   reuse_noedit  -C/--reuse-message given (message reused, editor off)
#   reuses_commit -C/-c/--amend (git reports source `commit`)
#   edit_flag   1 for -e/--edit, 0 for --no-edit, empty if neither
#   verbose     1/0 from -v/--verbose/--no-verbose, empty if neither
#   cleanup     value of --cleanup, empty if not given
has_mf=0; reuse_noedit=0; reuses_commit=0; edit_flag=""; verbose=""; cleanup=""
parse_commit_argv() {
  local -a argv=("$@")
  local n=${#argv[@]} i=1 a j ch
  [ "$n" -ge 2 ] || return 1
  [ "${argv[0]##*/}" = "git" ] || return 1
  # git's own options, before the subcommand.
  while [ "$i" -lt "$n" ]; do
    a="${argv[$i]}"
    case "$a" in
      -c|-C|--git-dir|--work-tree|--namespace|--config-env|--attr-source) i=$((i + 2)) ;;
      --git-dir=*|--work-tree=*|--namespace=*|--config-env=*|--attr-source=*|--exec-path=*) i=$((i + 1)) ;;
      -p|-P|--paginate|--no-pager|--bare|--no-replace-objects|--literal-pathspecs|--glob-pathspecs|--noglob-pathspecs|--icase-pathspecs|--no-optional-locks) i=$((i + 1)) ;;
      -*) return 1 ;;
      *) break ;;
    esac
  done
  [ "$i" -lt "$n" ] && [ "${argv[$i]}" = "commit" ] || return 1
  i=$((i + 1))
  while [ "$i" -lt "$n" ]; do
    a="${argv[$i]}"
    i=$((i + 1))
    case "$a" in
      --) break ;;
      --message=*|--file=*) has_mf=1 ;;
      --message|--file) has_mf=1; [ "$i" -lt "$n" ] || return 1; i=$((i + 1)) ;;
      --reuse-message=*) reuse_noedit=1; reuses_commit=1 ;;
      --reuse-message) reuse_noedit=1; reuses_commit=1; [ "$i" -lt "$n" ] || return 1; i=$((i + 1)) ;;
      --reedit-message=*) reuses_commit=1 ;;
      --reedit-message) reuses_commit=1; [ "$i" -lt "$n" ] || return 1; i=$((i + 1)) ;;
      --template=*|--author=*|--date=*|--trailer=*|--pathspec-from-file=*|--untracked-files=*|--gpg-sign=*) ;;
      --template|--author|--date|--trailer|--pathspec-from-file) [ "$i" -lt "$n" ] || return 1; i=$((i + 1)) ;;
      --cleanup=*) cleanup="${a#--cleanup=}" ;;
      --cleanup) [ "$i" -lt "$n" ] || return 1; cleanup="${argv[$i]}"; i=$((i + 1)) ;;
      --edit) edit_flag=1 ;;
      --no-edit) edit_flag=0 ;;
      --verbose) verbose=1 ;;
      --no-verbose) verbose=0 ;;
      --amend) reuses_commit=1 ;;
      --all|--patch|--interactive|--include|--only|--signoff|--no-signoff|--verify|--no-verify|--allow-empty|--allow-empty-message|--quiet|--dry-run|--status|--no-status|--short|--porcelain|--long|--branch|--null|--reset-author|--no-post-rewrite|--gpg-sign|--no-gpg-sign|--untracked-files|--pathspec-file-nul) ;;
      --*) return 1 ;;   # includes --fixup/--squash and abbreviations
      -) return 1 ;;
      -*)
        j=1
        while [ "$j" -lt "${#a}" ]; do
          ch="${a:$j:1}"
          j=$((j + 1))
          case "$ch" in
            m|F|C|c|t)
              case "$ch" in
                m|F) has_mf=1 ;;
                C) reuse_noedit=1; reuses_commit=1 ;;
                c) reuses_commit=1 ;;
              esac
              # The value is the rest of this word, or the next word.
              if [ "$j" -ge "${#a}" ]; then
                [ "$i" -lt "$n" ] || return 1
                i=$((i + 1))
              fi
              break ;;
            u|S) break ;;   # optional value, only ever attached
            e) edit_flag=1 ;;
            v) verbose=1 ;;
            a|i|o|p|q|s|n|z) ;;
            *) return 1 ;;
          esac
        done ;;
      *) ;;   # pathspec
    esac
  done
  return 0
}

strip_comments=0
cut_scissors=0
git_argv=()
if [ "$recorded_source" != "?" ] && [ -r "/proc/$PPID/cmdline" ]; then
  mapfile -d "" -t git_argv < "/proc/$PPID/cmdline" || git_argv=()
fi
if [ "${#git_argv[@]}" -gt 0 ] && parse_commit_argv "${git_argv[@]}"; then
  # git: -m/-F/-C turn the editor off, a plain commit, -c and --amend
  # leave it on, and -e/--no-edit override both.
  if [ -n "$edit_flag" ]; then
    use_editor="$edit_flag"
  elif [ "$has_mf" -eq 1 ] || [ "$reuse_noedit" -eq 1 ]; then
    use_editor=0
  else
    use_editor=1
  fi
  # The recorded source must agree with what the command line says.
  consistent=1
  if [ "$has_mf" -eq 1 ]; then
    [ "$recorded_source" = "message" ] || consistent=0
  elif [ "$reuses_commit" -eq 1 ]; then
    [ "$recorded_source" = "commit" ] || consistent=0
  else
    case "$recorded_source" in
      ""|template|merge|squash) ;;
      *) consistent=0 ;;
    esac
  fi
  if [ "$consistent" -eq 1 ]; then
    mode="${cleanup:-${cfg_cleanup:-default}}"
    case "$mode" in
      default) if [ "$use_editor" -eq 1 ]; then mode=strip; else mode=whitespace; fi ;;
      scissors) [ "$use_editor" -eq 1 ] || mode=whitespace ;;
      strip|whitespace|verbatim) ;;
      *) mode=unknown ;;
    esac
    if [ -z "$verbose" ]; then
      case "$cfg_verbose" in
        ""|false|0) verbose=0 ;;
        true) verbose=1 ;;
        -[0-9]*) verbose=0 ;;
        *[!0-9]*) verbose=unknown ;;
        *) verbose=1 ;;
      esac
    fi
    if [ "$mode" != "unknown" ] && [ "$verbose" != "unknown" ]; then
      [ "$mode" = "strip" ] && strip_comments=1
      if [ "$verbose" = "1" ] || [ "$mode" = "scissors" ]; then cut_scissors=1; fi
    fi
  fi
fi

tmp="$(mktemp)"

# Scissors line: "<comment> ------------------------ >8 ------------------------".
# With commentChar=auto, the line may start with any of the characters
# git's auto mode picks from -- but no comment line is stripped, since
# which one git picked is unknowable here.
PR_COMMENT="$comment" PR_STRIP="$strip_comments" PR_CUT="$cut_scissors" awk '
  BEGIN {
    c = ENVIRON["PR_COMMENT"]
    strip = (ENVIRON["PR_STRIP"] == "1")
    cutting = (ENVIRON["PR_CUT"] == "1")
    cut = " ------------------------ >8 ------------------------"
    auto = (c == "auto")
  }
  {
    if (cutting) {
      if (auto) {
        if (length($0) == length(cut) + 1 && substr($0, 2) == cut && index("#;@!$%^&|:", substr($0, 1, 1)) > 0) exit
      } else if ($0 == c cut) exit
    }
    if (strip && !auto && index($0, c) == 1) next
    print
  }
' "$msg_file" > "$tmp"

set +e
bash "$rules" scan-message "$tmp"
rc=$?
set -e
if [ "$rc" -ne 0 ]; then
  echo >&2
  if [ "$rc" -eq 1 ]; then
    reject "the message above breaks public-repo discipline. See CLAUDE.md's \"Public-repo discipline\" section, edit the message and try again."
  fi
  reject "the discipline check itself failed (exit $rc) -- refusing to let the commit through unchecked."
fi
exit 0
