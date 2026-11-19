#!/usr/bin/env bash
# Builds, integrity-signs and App-Store-signs a release using the local podman test container
# (abs-nc34) for php/composer/occ. Prints the values to paste into the App Store upload form.
#   scripts/release.sh                 (needs ~/.nextcloud/certificates/abscompat.{key,crt})
#   UNSIGNED=1 scripts/release.sh      (build only, e.g. to test the tarball)
set -euo pipefail
cd "$(dirname "$0")/.."
DIR=${CERT_DIR:-$HOME/.nextcloud/certificates}
KEY=$DIR/abscompat.key
CRT=$DIR/abscompat.crt
C=${CONTAINER:-abs-nc34}
APPDIR=/var/www/html/custom_apps/abscompat

if [ -z "${UNSIGNED:-}" ]; then
  [ -f "$KEY" ] && [ -f "$CRT" ] || { echo "Missing $KEY or $CRT (see scripts/request-certificate.sh)" >&2; exit 1; }
  podman exec "$C" mkdir -p /tmp/abssign
  podman cp "$KEY" "$C:/tmp/abssign/abscompat.key"
  podman cp "$CRT" "$C:/tmp/abssign/abscompat.crt"
  podman exec "$C" chown -R www-data /tmp/abssign
  podman exec "$C" chmod -R go-rwx /tmp/abssign
  trap 'podman exec "$C" rm -rf /tmp/abssign' EXIT
  OUT=$(podman exec -e NC_OCC=/var/www/html/occ -e APP_KEY=/tmp/abssign/abscompat.key -e APP_CERT=/tmp/abssign/abscompat.crt "$C" "$APPDIR/build-release.sh" | tail -1)
else
  OUT=$(podman exec "$C" "$APPDIR/build-release.sh" | tail -1)
fi
TARBALL=abscompat/$OUT
echo "Tarball: $TARBALL"
if [ -z "${UNSIGNED:-}" ]; then
  echo
  echo "Signature for the App Store upload form:"
  openssl dgst -sha512 -sign "$KEY" "$TARBALL" | openssl base64 -A
  echo
fi
