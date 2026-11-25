#!/usr/bin/env bash
# Installs a built release tarball into fresh Nextcloud containers and runs all test suites.
#   dev/test-matrix.sh abscompat/build/abscompat-X.Y.Z.tar.gz [versions...]   (default: 33 34 35)
#   DB=sqlite|mysql|pgsql (default sqlite; mysql = MariaDB), DOCKER=docker to use docker, KEEP=1
#   to keep the containers. Needs node, ffmpeg; CROSSPOINT_DIR optional.
set -euo pipefail
cd "$(dirname "$0")/.."
TARBALL=$(realpath "$1"); shift
VERSIONS=${*:-33 34 35}
D=${DOCKER:-podman}
DB=${DB:-sqlite}
dev/make-test-books.sh >/dev/null
rc=0
pw() { head -c 18 /dev/urandom | base64 | tr -d '/+='; }
for v in $VERSIONS; do
  name=abs-matrix-$v-$DB
  port=$((18000 + v))
  envf=$(mktemp)
  A=$(pw); T=$(pw); P=$(pw); DBPW=$(pw)
  echo "=== Nextcloud $v, $DB, on :$port"
  $D rm -f "$name" >/dev/null 2>&1 || true
  $D rm -f "$name-db" >/dev/null 2>&1 || true
  # The database container owns the network namespace, so Nextcloud reaches it on 127.0.0.1.
  case $DB in
    sqlite)
      net=(-p "$port:80"); dbenv=(-e SQLITE_DATABASE=nextcloud) ;;
    mysql)
      $D run -d --name "$name-db" -p "$port:80" -e MARIADB_DATABASE=nextcloud -e MARIADB_USER=nextcloud \
        -e MARIADB_PASSWORD="$DBPW" -e MARIADB_RANDOM_ROOT_PASSWORD=1 docker.io/library/mariadb:lts \
        --transaction-isolation=READ-COMMITTED --binlog-format=ROW >/dev/null
      net=(--network "container:$name-db")
      dbenv=(-e MYSQL_HOST=127.0.0.1 -e MYSQL_DATABASE=nextcloud -e MYSQL_USER=nextcloud -e MYSQL_PASSWORD="$DBPW")
      for i in $(seq 1 60); do $D exec "$name-db" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break; sleep 2; done ;;
    pgsql)
      $D run -d --name "$name-db" -p "$port:80" -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud \
        -e POSTGRES_PASSWORD="$DBPW" docker.io/library/postgres:17 >/dev/null
      net=(--network "container:$name-db")
      dbenv=(-e POSTGRES_HOST=127.0.0.1 -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud -e POSTGRES_PASSWORD="$DBPW")
      for i in $(seq 1 60); do $D exec "$name-db" pg_isready -h 127.0.0.1 -U nextcloud >/dev/null 2>&1 && break; sleep 2; done ;;
    *) echo "unknown DB=$DB" >&2; exit 2 ;;
  esac
  $D run -d --name "$name" "${net[@]}" "${dbenv[@]}" -e NEXTCLOUD_ADMIN_USER=admin \
    -e NEXTCLOUD_ADMIN_PASSWORD="$A" -e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" \
    "docker.io/library/nextcloud:$v-apache" >/dev/null
  for i in $(seq 1 120); do
    $D exec -u www-data "$name" php occ status 2>/dev/null | grep -q 'installed: true' && break
    sleep 3
  done
  $D exec -u www-data "$name" php occ status | grep versionstring
  # Prove which database is really in use.
  $D exec -u www-data "$name" php occ config:system:get dbtype
  # Wait for the web server too: "installed" turns true shortly before Apache starts.
  for i in $(seq 1 40); do curl -sf "http://localhost:$port/status.php" >/dev/null && break; sleep 2; done
  $D cp "$TARBALL" "$name:/tmp/app.tar.gz"
  $D exec "$name" bash -c 'tar -xzf /tmp/app.tar.gz -C /var/www/html/custom_apps && chown -R www-data: /var/www/html/custom_apps/abscompat'
  $D exec -u www-data "$name" php occ app:enable abscompat
  $D exec -e OC_PASS="$T" -u www-data "$name" php occ user:add --password-from-env tfauser >/dev/null
  $D exec -e OC_PASS="$P" -u www-data "$name" php occ user:add --password-from-env plainuser >/dev/null
  $D exec -u www-data "$name" php -r 'define("OC_CONSOLE", 1); require "/var/www/html/lib/base.php"; \OCP\Server::get(\OCP\IDBConnection::class)->insertIfNotExist("*PREFIX*twofactor_providers", ["provider_id" => "totp", "uid" => "tfauser", "enabled" => 1], ["provider_id", "uid"]);'
  APPPW=$($D exec -u www-data "$name" php occ user:add-app-password admin </dev/null 2>/dev/null | tail -1)
  $D cp dev/books/Audiobooks "$name:/var/www/html/data/admin/files/"
  $D exec "$name" chown -R www-data: /var/www/html/data/admin/files/Audiobooks
  $D exec -u www-data "$name" php occ files:scan admin -q
  printf 'NC_URL=http://localhost:%s\nNC_ADMIN_USER=admin\nNC_ADMIN_PASSWORD=%s\nNC_APP_PASSWORD=%s\nNC_TFA_USER=tfauser\nNC_TFA_PASSWORD=%s\nNC_PLAIN_USER=plainuser\nNC_PLAIN_PASSWORD=%s\n' \
    "$port" "$A" "$APPPW" "$T" "$P" > "$envf"
  # Show failures and the summary, not every passing line.
  quiet() { grep -v '^  ok  ' | grep -v '^$' | grep -v '^== ' || true; }
  echo "- api";      ENV_FILE=$envf node dev/e2e.mjs | quiet || rc=1
  echo "- settings"; ENV_FILE=$envf node dev/settings-e2e.mjs | quiet || rc=1
  echo "- scanner";  ENV_FILE=$envf NC_CONTAINER=$name DOCKER=$D node dev/scan-e2e.mjs | quiet || rc=1
  if [ -n "${CROSSPOINT_DIR:-}" ]; then
    echo "- crosspoint"
    ENV_FILE=$envf CROSSPOINT_DIR=$CROSSPOINT_DIR "$CROSSPOINT_DIR/node_modules/.bin/tsx" dev/crosspoint-e2e.mts | quiet || rc=1
  fi
  # Any warning from the app, or any error that mentions it, whatever app it was logged under.
  problems=$($D exec -u www-data "$name" cat /var/www/html/data/nextcloud.log 2>/dev/null \
    | grep -iE 'abscompat' | grep -E '"level":[34]|"level":2.*"app":"abscompat"' || true)
  if [ -n "$problems" ]; then echo "LOG PROBLEMS:"; echo "$problems" | cut -c1-900 | head -5; rc=1; else echo "log clean"; fi
  if [ -z "${KEEP:-}" ]; then
    $D rm -f "$name" >/dev/null
    if [ "$DB" != sqlite ]; then $D rm -f -v "$name-db" >/dev/null; fi
  fi
  rm -f "$envf"
done
exit $rc
