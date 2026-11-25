#!/usr/bin/env bash
# Is the checked-out commit a new release? Prints "release=true|false" and "version=X.Y.Z" (for
# $GITHUB_OUTPUT). It is one when the version in info.xml has no tag yet and its CHANGELOG entry
# is dated; "unreleased" means not ready. Needs the repository's tags.
set -euo pipefail
cd "$(dirname "$0")/.."
V=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' abscompat/appinfo/info.xml | head -1)
no() { echo "$1" >&2; printf 'release=false\nversion=%s\n' "$V"; exit 0; }
[[ $V =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Not a release version: $V" >&2; exit 1; }
git rev-parse -q --verify "refs/tags/v$V" >/dev/null && no "v$V is already released"
grep -qE "^## ${V//./\\.} [–-] [0-9]{4}-[0-9]{2}-[0-9]{2}\$" abscompat/CHANGELOG.md || no "The CHANGELOG entry for $V has no date yet"
LAST=$(git tag -l 'v[0-9]*' | sed 's/^v//' | sort -V | tail -1)
if [ -n "$LAST" ] && [ "$(printf '%s\n%s\n' "$LAST" "$V" | sort -V | tail -1)" != "$V" ]; then
  echo "$V is older than the released $LAST" >&2
  exit 1
fi
printf 'release=true\nversion=%s\n' "$V"
