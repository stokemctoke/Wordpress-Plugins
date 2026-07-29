#!/usr/bin/env bash
#
# Release one plugin from this repo.
#
#   tools/release.sh <plugin-dir> <version> [--dry-run] [--yes] [--latest]
#
#   tools/release.sh Gallus-QR 2.1.2
#   tools/release.sh Stoke-Chat 1.2.0 --dry-run
#
# Bumps the version everywhere it is written, builds an installable zip from
# the COMMITTED tree, verifies the payload, tags, pushes, and cuts a GitHub
# release using the plugin's own changelog entry as the notes.
#
# Why each guard exists is noted inline — they are all things that have
# actually gone wrong in this repo.

set -euo pipefail

# --- Arguments ---------------------------------------------------------------

DRY_RUN=0
ASSUME_YES=0
MARK_LATEST=0
PLUGIN_DIR=""
VERSION=""

usage() {
	sed -n '3,12p' "$0" | sed 's/^# \{0,1\}//'
	exit "${1:-1}"
}

while [ $# -gt 0 ]; do
	case "$1" in
		--dry-run) DRY_RUN=1 ;;
		--yes|-y)  ASSUME_YES=1 ;;
		--latest)  MARK_LATEST=1 ;;
		-h|--help) usage 0 ;;
		-*)        echo "Unknown option: $1" >&2; usage ;;
		*)
			if [ -z "$PLUGIN_DIR" ]; then PLUGIN_DIR="${1%/}"
			elif [ -z "$VERSION" ]; then VERSION="$1"
			else echo "Unexpected argument: $1" >&2; usage
			fi
			;;
	esac
	shift
done

[ -n "$PLUGIN_DIR" ] && [ -n "$VERSION" ] || usage

die() { echo "error: $*" >&2; exit 1; }
note() { echo "  $*"; }
step() { echo; echo "==> $*"; }

# WordPress compares version strings, so keep them plain numeric semver.
echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' \
	|| die "version must look like 1.2.3, got '$VERSION'"

# --- Repo state --------------------------------------------------------------

cd "$(git rev-parse --show-toplevel)" || die "not inside a git repository"

[ -d "$PLUGIN_DIR" ] || die "no such directory: $PLUGIN_DIR"
command -v gh >/dev/null || die "the gh CLI is required"
command -v zip >/dev/null || die "zip is required"

# The build reads HEAD, not the working tree, so uncommitted edits would be
# silently left out of the zip. Refuse rather than ship a surprise.
[ -z "$(git status --porcelain)" ] || die "working tree is dirty — commit or stash first"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "$BRANCH" != "master" ]; then
	echo "warning: releasing from '$BRANCH', not master" >&2
fi

# --- Identify the plugin -----------------------------------------------------

# The install folder name comes from the main plugin FILE, not the directory:
# Globe-Custom-World/world-builder-globe.php installs as world-builder-globe.
MAIN_FILE="$(grep -l 'Plugin Name:' "$PLUGIN_DIR"/*.php 2>/dev/null | head -1)" \
	|| die "no file with a 'Plugin Name:' header in $PLUGIN_DIR"
[ -n "$MAIN_FILE" ] || die "no file with a 'Plugin Name:' header in $PLUGIN_DIR"

SLUG="$(basename "$MAIN_FILE" .php)"
DISPLAY_NAME="$(sed -n 's/^ \* Plugin Name: *//p' "$MAIN_FILE" | head -1 | sed 's/ *$//')"
OLD_VERSION="$(sed -n 's/^ \* Version: *//p' "$MAIN_FILE" | head -1 | sed 's/ *$//')"
README="$PLUGIN_DIR/readme.txt"
TAG="${SLUG}-v${VERSION}"

[ -n "$DISPLAY_NAME" ] || die "could not read 'Plugin Name:' from $MAIN_FILE"
[ -n "$OLD_VERSION" ] || die "could not read 'Version:' from $MAIN_FILE"
[ -f "$README" ] || die "missing $README"

step "$DISPLAY_NAME  $OLD_VERSION -> $VERSION"
note "directory : $PLUGIN_DIR"
note "main file : $MAIN_FILE"
note "slug      : $SLUG   (install folder, and zip's top-level directory)"
note "tag       : $TAG"

[ "$OLD_VERSION" != "$VERSION" ] || die "$MAIN_FILE is already at $VERSION"

# WordPress only offers an update when the new version sorts higher, so a
# typo'd downgrade would ship an unreachable release. Easy to do: the digits
# are adjacent on the keyboard and the plugin dir gives no hint of its version.
HIGHEST="$(printf '%s\n%s\n' "$OLD_VERSION" "$VERSION" | sort -V | tail -1)"
[ "$HIGHEST" = "$VERSION" ] \
	|| die "$VERSION is lower than the current $OLD_VERSION — WordPress would never offer it as an update"

git rev-parse -q --verify "refs/tags/$TAG" >/dev/null \
	&& die "tag $TAG already exists locally"
if git ls-remote --exit-code --tags origin "$TAG" >/dev/null 2>&1; then
	die "tag $TAG already exists on origin"
fi

# --- Release notes, written by a human before we get here --------------------

# Pulled from the plugin's own changelog so the release page and the readme
# users see after installing cannot disagree.
NOTES_FILE="$(mktemp)"
trap 'rm -f "$NOTES_FILE"' EXIT

awk -v ver="$VERSION" '
	$0 == "= " ver " =" { grabbing = 1; next }
	grabbing && /^= .* =$/ { exit }
	grabbing { print }
' "$README" | sed -e 's/[[:space:]]*$//' > "$NOTES_FILE"

# Trim leading/trailing blank lines.
sed -i -e '/./,$!d' "$NOTES_FILE"
[ -s "$NOTES_FILE" ] || die "no '= $VERSION =' section in $README — write the changelog entry first"

step "Release notes from $README"
sed 's/^/  | /' "$NOTES_FILE"

# --- Bump the version everywhere it is written -------------------------------

esc() { printf '%s' "$1" | sed 's/[.[\*^$/]/\\&/g'; }
OLD_RE="$(esc "$OLD_VERSION")"

step "Bumping version"

sed -i "s/^\( \* Version: *\)${OLD_RE}[[:space:]]*$/\1${VERSION}/" "$MAIN_FILE"
# Requiring the OLD value protects sibling constants such as
# GALLUS_QR_DB_VERSION, which track the schema and must not move.
sed -i "s/\(define( '[A-Z_]*VERSION', '\)${OLD_RE}\(' );\)/\1${VERSION}\2/" "$MAIN_FILE"
sed -i "s/^Stable tag: ${OLD_RE}[[:space:]]*$/Stable tag: ${VERSION}/" "$README"

git --no-pager diff --stat
git --no-pager diff -U0 | grep -E '^[+-] ' | grep -vE '^[+-]{3}' | sed 's/^/  /'

grep -q "^ \* Version: *${VERSION}$" "$MAIN_FILE" || die "header bump failed in $MAIN_FILE"
grep -q "^Stable tag: ${VERSION}$" "$README" || die "stable tag bump failed in $README"

if [ "$DRY_RUN" = "1" ]; then
	step "Dry run — reverting the bump, nothing else was touched"
	git checkout -- "$MAIN_FILE" "$README"
	exit 0
fi

# --- Commit, so the build has something to archive ---------------------------

step "Committing the bump"
git add "$MAIN_FILE" "$README"
git commit -q -m "Release ${DISPLAY_NAME} ${VERSION}"
note "$(git log --oneline -1)"

# --- Build -------------------------------------------------------------------

step "Building ${SLUG}-${VERSION}.zip"

BUILD="$(mktemp -d)"
DIST="$(pwd)/dist"
ZIP="${DIST}/${SLUG}-${VERSION}.zip"
mkdir -p "$BUILD/$SLUG" "$DIST"
rm -f "$ZIP"

# From HEAD, never the working tree — vendor/ and node_modules/ live there.
git archive "HEAD:${PLUGIN_DIR}" | tar -x -C "$BUILD/$SLUG"

# Development tooling that must never reach a WordPress install.
( cd "$BUILD/$SLUG" && rm -rf tests node_modules vendor && rm -f \
	composer.json composer.lock package.json package-lock.json \
	phpunit.xml.dist .wp-env.json .wp-env.override.json \
	.gitignore .gitattributes .phpunit.result.cache )

( cd "$BUILD" && zip -rq -X "$ZIP" "$SLUG" )
rm -rf "$BUILD"

# --- Verify the artifact, rather than trusting the build ---------------------

step "Verifying the zip"

unzip -tq "$ZIP" >/dev/null || die "zip failed its integrity check"

TOP="$(unzip -Z1 "$ZIP" | cut -d/ -f1 | sort -u)"
[ "$TOP" = "$SLUG" ] || die "expected a single top-level '$SLUG/' folder, found: $TOP"

STRAYS="$(unzip -Z1 "$ZIP" | grep -E '(^|/)(tests|node_modules|vendor)/|composer\.(json|lock)|package(-lock)?\.json|phpunit|\.wp-env|\.git' || true)"
[ -z "$STRAYS" ] || die "development files leaked into the zip:"$'\n'"$STRAYS"

ZIP_VERSION="$(unzip -p "$ZIP" "${SLUG}/$(basename "$MAIN_FILE")" | sed -n 's/^ \* Version: *//p' | head -1 | sed 's/ *$//')"
[ "$ZIP_VERSION" = "$VERSION" ] \
	|| die "zip reports version '$ZIP_VERSION', expected '$VERSION'"

note "$(unzip -Z1 "$ZIP" | grep -vc '/$') files, $(du -h "$ZIP" | cut -f1)"
note "top-level folder: ${SLUG}/"
note "header version:   ${ZIP_VERSION}"
note "artifact:         ${ZIP}"

# --- Everything past here is public ------------------------------------------

if [ "$ASSUME_YES" != "1" ]; then
	echo
	echo "About to push ${BRANCH} and publish ${TAG} — this is public and hard to undo."
	printf 'Continue? [y/N] '
	read -r reply
	case "$reply" in
		[yY]|[yY][eE][sS]) ;;
		*) echo "Stopped. The bump is committed locally; nothing was pushed."; exit 1 ;;
	esac
fi

step "Tagging and pushing"
git tag -a "$TAG" -m "${DISPLAY_NAME} v${VERSION}"
git push origin "$BRANCH"
git push origin "$TAG"

step "Creating the GitHub release"
RELEASE_ARGS=( "$TAG" "$ZIP" --title "${DISPLAY_NAME} v${VERSION}" --notes-file "$NOTES_FILE" )
[ "$MARK_LATEST" = "1" ] && RELEASE_ARGS+=( --latest )
gh release create "${RELEASE_ARGS[@]}"

step "Done"
note "$(gh release view "$TAG" --json url --jq .url)"
