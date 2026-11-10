#!/bin/bash
# Bumps the module version in postishipping.php and prepends a CHANGELOG.md
# section built from the commits made since the last release tag.
#
# Usage: prepare-release.sh [VERSION] [BUMP]
#   VERSION  explicit X.Y.Z version (optional; overrides BUMP)
#   BUMP     auto | patch | minor | major (default: auto)
#            auto = major if a breaking change ("type!:" or "BREAKING CHANGE"),
#                   minor if any "feat" commit, otherwise patch.
#
# Prints the chosen version on the last line of stdout and, when running in
# GitHub Actions, writes `version` and `last_tag` to $GITHUB_OUTPUT and the
# new changelog section to release-notes.md.

set -euo pipefail

VERSION="${1:-}"
BUMP="${2:-auto}"

ROOT="$(git rev-parse --show-toplevel)"
MODULE_FILE="$ROOT/postishipping/postishipping.php"
CHANGELOG="$ROOT/postishipping/CHANGELOG.md"
NOTES_FILE="$ROOT/release-notes.md"

fail() { echo "::error::$*" >&2; exit 1; }

CURRENT=$(grep -oP "\\\$this->version = '\K[^']+" "$MODULE_FILE" || true)
[ -n "$CURRENT" ] || fail "Could not read module version from $MODULE_FILE"

LAST_TAG=$(git tag -l 'v[0-9]*' --sort=-v:refname | head -n1)
if [ -n "$LAST_TAG" ]; then
    BASE_VERSION="${LAST_TAG#v}"
    RANGE="$LAST_TAG..HEAD"
else
    BASE_VERSION="$CURRENT"
    RANGE="HEAD"
fi

# Commits as "subject<US>body<RS>", oldest first, merges skipped.
COMMITS=$(git log --reverse --no-merges --format='%s%x1f%b%x1e' $RANGE)
[ -n "$COMMITS" ] || fail "No new commits since ${LAST_TAG:-the beginning}."

# --- Decide the version -----------------------------------------------------
if [ -z "$VERSION" ]; then
    if [ "$BUMP" = "auto" ]; then
        SUBJECTS=$(git log --no-merges --format='%s' $RANGE)
        BUMP=patch
        grep -qiP '^feat(\([^)]*\))?!?:' <<<"$SUBJECTS" && BUMP=minor
        if grep -qP '^[a-zA-Z]+(\([^)]*\))?!:' <<<"$SUBJECTS" \
            || git log --no-merges --format='%b' $RANGE | grep -q 'BREAKING CHANGE'; then
            BUMP=major
        fi
    fi
    IFS=. read -r MA MI PA <<<"$BASE_VERSION"
    case "$BUMP" in
        major) VERSION="$((MA + 1)).0.0" ;;
        minor) VERSION="$MA.$((MI + 1)).0" ;;
        patch) VERSION="$MA.$MI.$((PA + 1))" ;;
        *) fail "Unknown bump type '$BUMP' (expected auto, patch, minor or major)." ;;
    esac
fi

VERSION="${VERSION#v}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "Version '$VERSION' is not in X.Y.Z format."
git rev-parse -q --verify "refs/tags/v$VERSION" >/dev/null && fail "Tag v$VERSION already exists."
if [ -n "$LAST_TAG" ] && { [ "$BASE_VERSION" = "$VERSION" ] \
    || [ "$(printf '%s\n%s\n' "$BASE_VERSION" "$VERSION" | sort -V | tail -n1)" != "$VERSION" ]; }; then
    fail "Version $VERSION must be greater than the last release $BASE_VERSION."
fi
grep -qF "## [$VERSION]" "$CHANGELOG" && fail "CHANGELOG.md already has a section for $VERSION."

# --- Build the changelog section --------------------------------------------
ADDED=() FIXED=() CHANGED=()
while IFS=$'\037' read -r -d $'\036' SUBJECT _BODY; do
    SUBJECT="${SUBJECT#$'\n'}"
    [ -n "$SUBJECT" ] || continue
    if [[ "$SUBJECT" =~ ^([a-zA-Z]+)(\([^\)]*\))?\!?:[[:space:]]*(.+)$ ]]; then
        TYPE="${BASH_REMATCH[1],,}"
        DESC="${BASH_REMATCH[3]}"
    else
        TYPE=""
        DESC="$SUBJECT"
    fi
    DESC="${DESC^}"
    case "$TYPE" in
        feat) ADDED+=("$DESC") ;;
        fix) FIXED+=("$DESC") ;;
        ci|chore|test|tests|docs|build|style) ;;  # not relevant for module users
        *) CHANGED+=("$DESC") ;;
    esac
done <<<"$COMMITS"

{
    echo "## [$VERSION] - $(date -u +%Y-%m-%d)"
    if [ ${#ADDED[@]} -gt 0 ]; then echo "### Added"; printf -- '- %s\n' "${ADDED[@]}"; fi
    if [ ${#FIXED[@]} -gt 0 ]; then echo "### Fixed"; printf -- '- %s\n' "${FIXED[@]}"; fi
    if [ ${#CHANGED[@]} -gt 0 ]; then echo "### Changed"; printf -- '- %s\n' "${CHANGED[@]}"; fi
    if [ $((${#ADDED[@]} + ${#FIXED[@]} + ${#CHANGED[@]})) -eq 0 ]; then
        echo "### Changed"; echo "- Maintenance updates."
    fi
} >"$NOTES_FILE"

# Insert the section above the first existing "## [" heading (or at the end).
awk -v notes="$NOTES_FILE" '
    !done && /^## \[/ { while ((getline line < notes) > 0) print line; print ""; done = 1 }
    { print }
    END { if (!done) { print ""; while ((getline line < notes) > 0) print line } }
' "$CHANGELOG" >"$CHANGELOG.tmp"
mv "$CHANGELOG.tmp" "$CHANGELOG"

# --- Bump the module version ------------------------------------------------
sed -i -E "s/(\\\$this->version = ')[^']+(';)/\1$VERSION\2/" "$MODULE_FILE"
grep -qF "\$this->version = '$VERSION';" "$MODULE_FILE" || fail "Failed to update version in $MODULE_FILE"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
    echo "version=$VERSION" >>"$GITHUB_OUTPUT"
    echo "last_tag=$LAST_TAG" >>"$GITHUB_OUTPUT"
fi

echo "Version: $CURRENT -> $VERSION (last tag: ${LAST_TAG:-none})" >&2
cat "$NOTES_FILE" >&2
echo "$VERSION"
