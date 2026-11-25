#!/usr/bin/env bash
# Reads the app's user settings inside real Nextclouds under many PHP JIT settings. 0.7.0 and older
# fail this (see abscompat/lib/Service/UserValue.php); it must report 0 failing settings.
#   dev/jit-check.sh abscompat/build/abscompat-X.Y.Z.tar.gz [versions...]   (default: 33 34 35)
#   DOCKER=docker to use docker. Takes a few minutes per version.
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
TARBALL=$(realpath "$1"); shift
D=${DOCKER:-podman}
W=$(mktemp -d); chmod 755 "$W"
cp "$TARBALL" "$W/app.tgz"; cp "$HERE/jit-check.php" "$W/"; chmod 644 "$W"/*
cat > "$W/go.sh" <<'IN'
cp -r /usr/src/nextcloud /tmp/nc && cd /tmp/nc && mkdir -p data && tar -xzf /t/app.tgz -C apps
php -d memory_limit=512M occ maintenance:install --database sqlite --admin-user admin --admin-pass "x$(head -c 12 /dev/urandom | base64)" >/dev/null
php occ app:enable abscompat
php /t/jit-check.php init
n=0; b=0
for hl in 0 3 16 64; do for hf in 1 5 16 127; do for hr in 0 1 8; do for hs in 1 8; do
  o=$(php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 -d opcache.jit=1255 -d opcache.jit_buffer_size=64M -d opcache.jit_hot_loop=$hl -d opcache.jit_hot_func=$hf -d opcache.jit_hot_return=$hr -d opcache.jit_hot_side_exit=$hs /t/jit-check.php 2>&1 | tail -1)
  n=$((n+1)); [ $n = 1 ] && echo "$o"
  case "$o" in *"jit=true wrong=0 "*) ;; *) b=$((b+1)); [ $b -le 5 ] && echo "FAIL hl=$hl hf=$hf hr=$hr hs=$hs $o";; esac
done; done; done; done
echo "$b of $n settings fail"
[ $b = 0 ]
IN
rc=0
for v in ${*:-33 34 35}; do
  echo "=== Nextcloud $v"
  $D run --rm --network none --user www-data -e HOME=/tmp --entrypoint sh -v "$W:/t:ro,z" "docker.io/library/nextcloud:$v-apache" /t/go.sh || rc=1
done
exit $rc
