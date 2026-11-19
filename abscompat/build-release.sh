#!/usr/bin/env bash
# Builds the App Store tarball: build/abscompat-<version>.tar.gz with a single top-level
# "abscompat/" folder, runtime files only, and getID3 vendored (no Composer autoloader needed).
# Needs: php, composer, tar, rsync. Optional integrity signing: set NC_OCC (path to a Nextcloud
# occ), APP_KEY and APP_CERT; then appinfo/signature.json is created before packing.
set -euo pipefail
cd "$(dirname "$0")"
APP=abscompat
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)
STAGE=build/stage/$APP
rm -rf build/stage && mkdir -p "$STAGE"

rsync -a \
  --exclude '/build' --exclude '/tests' --exclude '/vendor' --exclude '/.git*' \
  --exclude '/build-release.sh' --exclude '/composer.lock' --exclude '/.php-cs-fixer*' \
  --exclude '/node_modules' --exclude '*.swp' --exclude '/screenshots' \
  ./ "$STAGE/"

# Runtime dependency: only getID3's library folder and licence.
tmp=$(mktemp -d)
cp composer.json "$tmp/" && { [ -f composer.lock ] && cp composer.lock "$tmp/" || true; }
(cd "$tmp" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-autoloader --no-scripts --quiet)
mkdir -p "$STAGE/vendor/james-heinrich/getid3"
cp -r "$tmp/vendor/james-heinrich/getid3/getid3" "$STAGE/vendor/james-heinrich/getid3/"
cp "$tmp/vendor/james-heinrich/getid3/"license*.txt "$tmp/vendor/james-heinrich/getid3/"README* "$STAGE/vendor/james-heinrich/getid3/" 2>/dev/null || true
cp -r "$tmp/vendor/james-heinrich/getid3/licenses" "$STAGE/vendor/james-heinrich/getid3/" 2>/dev/null || true
rm -rf "$tmp"
rm -f "$STAGE/composer.json"

find "$STAGE" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

if [ -n "${NC_OCC:-}" ] && [ -n "${APP_KEY:-}" ] && [ -n "${APP_CERT:-}" ]; then
  # occ must run as the owner of Nextcloud's config (www-data in the official image).
  OCC_USER=${OCC_USER:-$(stat -c %U "$(dirname "$NC_OCC")/config/config.php")}
  if [ "$(id -un)" != "$OCC_USER" ]; then
    chown -R "$OCC_USER" "$STAGE"
    runuser -u "$OCC_USER" -- php "$NC_OCC" integrity:sign-app --privateKey="$APP_KEY" --certificate="$APP_CERT" --path="$(pwd)/$STAGE"
    chown -R "$(id -u):$(id -g)" "$STAGE"
  else
    php "$NC_OCC" integrity:sign-app --privateKey="$APP_KEY" --certificate="$APP_CERT" --path="$(pwd)/$STAGE"
  fi
  test -s "$STAGE/appinfo/signature.json"
fi

mkdir -p build
tar -C build/stage -czf "build/$APP-$VERSION.tar.gz" --owner=0 --group=0 --sort=name "$APP"
echo "build/$APP-$VERSION.tar.gz"
