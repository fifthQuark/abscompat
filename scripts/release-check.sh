#!/usr/bin/env bash
# Is the checked-out commit what the tag says it is? Prints "version=X.Y.Z" (for $GITHUB_OUTPUT).
# The tag must be v + the version in info.xml, the CHANGELOG entry must be dated, the commit must be
# on main, and no newer version may be tagged. Needs the repository's history and tags.
#   scripts/release-check.sh v1.2.3
set -euo pipefail
cd "$(dirname "$0")/.."
TAG=$1
no() { echo "$1" >&2; exit 1; }
V=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' abscompat/appinfo/info.xml | head -1)
[[ $V =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || no "Not a release version: $V"
[ "$TAG" = "v$V" ] || no "Tag $TAG does not match info.xml version $V"
grep -qE "^## ${V//./\\.} [–-] [0-9]{4}-[0-9]{2}-[0-9]{2}\$" abscompat/CHANGELOG.md || no "The CHANGELOG entry for $V has no date yet"
git merge-base --is-ancestor HEAD origin/main || no "$TAG is not on main"
LAST=$(git tag -l 'v[0-9]*' | sed 's/^v//' | sort -V | tail -1)
[ -z "$LAST" ] || [ "$(printf '%s\n%s\n' "$LAST" "$V" | sort -V | tail -1)" = "$V" ] || no "$V is older than the released $LAST"
echo "version=$V"
