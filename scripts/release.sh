#!/usr/bin/env bash
# One release, one SVN commit: trunk + assets + tags/<version>.
#
# Usage: scripts/release.sh [--dry-run] [--skip-checks]
#
# Environment:
#   WPORG_SVN_URL        SVN repository (default: the plugin directory repository)
#   WPORG_SVN_USER       optional SVN username
#   BABILIM_RELEASE_WORK work directory (default: a fresh mktemp -d)
#
# --skip-checks exists for the test suite only; `make release` never passes it.
set -euo pipefail

DRY_RUN=0
SKIP_CHECKS=0
for arg in "$@"; do
	case "$arg" in
		--dry-run) DRY_RUN=1 ;;
		--skip-checks) SKIP_CHECKS=1 ;;
		*) echo "unknown argument: $arg" >&2; exit 2 ;;
	esac
done

cd "$(dirname "${BASH_SOURCE[0]}")/.."

URL=${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/babilim}
SVN_AUTH=()
if [ -n "${WPORG_SVN_USER:-}" ]; then
	SVN_AUTH=(--username "$WPORG_SVN_USER")
fi

echo "==> Checking the working tree"
if [ "$DRY_RUN" -eq 0 ]; then
	[ -z "$(git status --porcelain)" ] || { echo "FAIL: working tree is not clean" >&2; exit 1; }
fi

echo "==> Reading the version and running the release checks"
V=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' readme.txt | head -n 1)
[ -n "$V" ] || { echo "FAIL: no Stable tag in readme.txt" >&2; exit 1; }
if [ "$SKIP_CHECKS" -eq 0 ]; then
	make release-check
fi
[ -f "build/babilim-$V.zip" ] || { echo "FAIL: build/babilim-$V.zip does not exist" >&2; exit 1; }

echo "==> Checking out $URL"
WORK=${BABILIM_RELEASE_WORK:-$(mktemp -d)}
svn checkout --depth immediates "$URL" "$WORK/svn" ${SVN_AUTH[@]+"${SVN_AUTH[@]}"}
svn update --set-depth infinity "$WORK/svn/trunk" "$WORK/svn/assets" ${SVN_AUTH[@]+"${SVN_AUTH[@]}"}
svn update --set-depth immediates "$WORK/svn/tags" ${SVN_AUTH[@]+"${SVN_AUTH[@]}"}

echo "==> Checking that tag $V is free"
[ ! -e "$WORK/svn/tags/$V" ] || { echo "FAIL: tag $V already exists; bump the version in readme.txt and babilim.php" >&2; exit 1; }

echo "==> Staging trunk and assets"
rm -rf "$WORK/unzipped" "$WORK/gitassets"
mkdir -p "$WORK/gitassets"
php -r '$z = new ZipArchive(); $z->open( $argv[1] ) === true && $z->extractTo( $argv[2] ) || exit( 1 );' "build/babilim-$V.zip" "$WORK/unzipped"
# Assets come from the committed tree, so an ignored or untracked file in the
# working directory can never reach the public assets directory.
git archive HEAD .wordpress-org | tar -x -C "$WORK/gitassets"
rsync -a --delete --exclude .svn "$WORK/unzipped/babilim/" "$WORK/svn/trunk/"
rsync -a --delete --exclude .svn --exclude src/ "$WORK/gitassets/.wordpress-org/" "$WORK/svn/assets/"

echo "==> Registering additions, removals and MIME types"
svn add --force "$WORK/svn/trunk" "$WORK/svn/assets"
svn status "$WORK/svn" | awk '$1=="!"{print $2}' | xargs -r svn rm
find "$WORK/svn/assets" -name '*.png' -exec svn propset svn:mime-type image/png {} +
find "$WORK/svn/assets" -name '*.svg' -exec svn propset svn:mime-type image/svg+xml {} +
find "$WORK/svn/assets" -path '*/blueprints/blueprint.json' -exec svn propset svn:mime-type application/json {} +

echo "==> Tagging $V"
svn cp "$WORK/svn/trunk" "$WORK/svn/tags/$V"
TAG_STABLE=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$WORK/svn/tags/$V/readme.txt" | head -n 1)
[ "$TAG_STABLE" = "$V" ] || { echo "FAIL: tags/$V/readme.txt has Stable tag '$TAG_STABLE'" >&2; exit 1; }

NEXT="Wait for the Release Confirmation email, open the Release Management page from it, confirm the release and choose 'Delay auto-updates for 24 hours'. The release reaches sites after the directory's release delay; watch the support forum and the service's error rate for 24-48 hours."

echo "==> Pending changes"
svn status "$WORK/svn"
if [ "$DRY_RUN" -eq 1 ]; then
	echo "dry run: nothing committed"
	echo "after a real release, run: git push origin \"v$V\""
	echo "$NEXT"
	exit 0
fi

echo "==> Committing"
svn commit -m "Release $V" "$WORK/svn" ${SVN_AUTH[@]+"${SVN_AUTH[@]}"}

echo "==> Tagging the git commit"
git tag -a "v$V" -m "Release $V"
echo "run: git push origin \"v$V\""
echo "$NEXT"
