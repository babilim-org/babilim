#!/usr/bin/env bash
# Cloud session bootstrap. Runs on every SessionStart; does nothing outside
# Claude Code on the web (CLAUDE_CODE_REMOTE=true), so local clones are untouched.
set -u
[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0
cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/..}" || exit 0

# Commit identity for this repository (repo-local, never global).
git config user.name "Babilim"
git config user.email "dev@babilim.org"

# Commit-message and discipline hooks (see CLAUDE.md).
make install-hooks >/dev/null 2>&1 || echo "cloud-setup: make install-hooks failed" >&2

# PHP CLI is required for the test suite; install it if the image lacks it.
if ! command -v php >/dev/null 2>&1; then
  if command -v apt-get >/dev/null 2>&1; then
    SUDO=""; [ "$(id -u)" -ne 0 ] && SUDO="sudo -n"
    $SUDO apt-get install -y -qq php-cli php-xml php-mbstring >/dev/null 2>&1 \
      || echo "cloud-setup: could not install php-cli" >&2
  fi
fi

# Official WordPress agent skills (plugin development, PHPStan, Plugin Check
# guidelines, ...). Cloned outside the repository and linked into the user's
# skills directory; the SKILL.md files are also readable directly from the clone.
WP_SKILLS_DIR="${HOME}/.cache/wp-agent-skills"
if [ ! -d "${WP_SKILLS_DIR}/skills" ]; then
  git clone -q --depth 1 https://github.com/WordPress/agent-skills "${WP_SKILLS_DIR}" 2>/dev/null \
    || echo "cloud-setup: could not fetch WordPress agent skills" >&2
fi
if [ -d "${WP_SKILLS_DIR}/skills" ]; then
  mkdir -p "${HOME}/.claude/skills"
  for s in "${WP_SKILLS_DIR}"/skills/*/; do
    ln -sfn "${s%/}" "${HOME}/.claude/skills/$(basename "${s}")"
  done
fi
exit 0
