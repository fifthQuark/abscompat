#!/usr/bin/env bash
# Installs a built release tarball into fresh Nextcloud containers and runs all test suites.
#   dev/test-matrix.sh abscompat/build/abscompat-X.Y.Z.tar.gz [versions...]   (default: 33 34 35)
# Uses podman (or DOCKER=docker). Needs node, ffmpeg; CROSSPOINT_DIR optional.
set -euo pipefail
cd "$(dirname "$0")/.."
TARBALL=$(realpath "$1"); shift
VERSIONS=${*:-33 34 35}
D=${DOCKER:-podman}
dev/make-test-books.sh >/dev/null
rc=0
for v in $VERSIONS; do
  name=abs-matrix-$v
  port=$((18000 + v))
  envf=$(mktemp)
  pw() { head -c 18 /dev/urandom | base64 | tr -d '/+='; }
  A=$(pw); T=$(pw); P=$(pw)
  echo "=== Nextcloud $v on :$port"
  $D rm -f "$name" >/dev/null 2>&1 || true
  $D run -d --name "$name" -p "$port:80" -e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER=admin \
    -e NEXTCLOUD_ADMIN_PASSWORD="$A" -e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" \
    "docker.io/library/nextcloud:$v-apache" >/dev/null
  for i in $(seq 1 120); do
    $D exec -u www-data "$name" php occ status 2>/dev/null | grep -q 'installed: true' && break
    sleep 3
  done
  $D exec -u www-data "$name" php occ status | grep versionstring
  $D cp "$TARBALL" "$name:/tmp/app.tar.gz"
  $D exec "$name" bash -c 'tar -xzf /tmp/app.tar.gz -C /var/www/html/custom_apps && chown -R www-data: /var/www/html/custom_apps/abscompat'
  $D exec -u www-data "$name" php occ app:enable abscompat
  $D exec -e OC_PASS="$T" -u www-data "$name" php occ user:add --password-from-env tfauser >/dev/null
  $D exec -e OC_PASS="$P" -u www-data "$name" php occ user:add --password-from-env plainuser >/dev/null
  $D exec -u www-data "$name" php -r '$db=new PDO("sqlite:/var/www/html/data/nextcloud.db"); $db->exec("INSERT INTO oc_twofactor_providers (provider_id, uid, enabled) VALUES (\"totp\", \"tfauser\", 1)");'
  APPPW=$($D exec -u www-data "$name" php occ user:add-app-password admin </dev/null 2>/dev/null | tail -1)
  $D cp dev/books/Audiobooks "$name:/var/www/html/data/admin/files/"
  $D exec "$name" chown -R www-data: /var/www/html/data/admin/files/Audiobooks
  $D exec -u www-data "$name" php occ files:scan admin -q
  printf 'NC_URL=http://localhost:%s\nNC_ADMIN_USER=admin\nNC_ADMIN_PASSWORD=%s\nNC_APP_PASSWORD=%s\nNC_TFA_USER=tfauser\nNC_TFA_PASSWORD=%s\nNC_PLAIN_USER=plainuser\nNC_PLAIN_PASSWORD=%s\n' \
    "$port" "$A" "$APPPW" "$T" "$P" > "$envf"
  ENV_FILE=$envf node dev/e2e.mjs | tail -1 || rc=1
  ENV_FILE=$envf node dev/settings-e2e.mjs | tail -1 || rc=1
  if [ -n "${CROSSPOINT_DIR:-}" ]; then
    ENV_FILE=$envf CROSSPOINT_DIR=$CROSSPOINT_DIR "$CROSSPOINT_DIR/node_modules/.bin/tsx" dev/crosspoint-e2e.mts | tail -1 || rc=1
  fi
  if $D exec -u www-data "$name" grep -i '"app":"abscompat"' /var/www/html/data/nextcloud.log 2>/dev/null | grep -E '"level":[34]' | head -3; then rc=1; fi
  [ -n "${KEEP:-}" ] || $D rm -f "$name" >/dev/null
  rm -f "$envf"
done
exit $rc
