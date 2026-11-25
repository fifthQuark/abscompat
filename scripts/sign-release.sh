#!/usr/bin/env bash
# Signs an unsigned release tarball without a running Nextcloud: integrity signature inside, App
# Store signature next to it. The key is only visible to a container without network.
#   scripts/sign-release.sh unsigned.tar.gz abscompat.key outdir
# Writes outdir/abscompat-X.Y.Z.tar.gz and outdir/signature.txt. DOCKER=podman to use podman.
set -euo pipefail
IN=$(realpath "$1"); KEY=$(realpath "$2"); mkdir -p "$3"; OUT=$(realpath "$3")
CRT=$(cd "$(dirname "$0")" && pwd)/abscompat.crt
IMAGE=${SIGN_IMAGE:-docker.io/library/nextcloud@sha256:515c072ea1cf82ece3503f301fdf75dd5a7148abcc8fa10aaefd5ec56643b703}
D=${DOCKER:-docker}

pub() { openssl x509 -in "$CRT" -pubkey -noout; }
[ "$(openssl pkey -in "$KEY" -pubout)" = "$(pub)" ] || { echo "The key does not belong to $CRT" >&2; exit 1; }
[ -z "$(tar -tzf "$IN" | grep -vE '^abscompat/' || true)" ] || { echo "Files outside abscompat/ in the tarball" >&2; exit 1; }
[ -z "$(tar -tzf "$IN" | grep -E '(^|/)\.\.(/|$)' || true)" ] || { echo "Unsafe path in the tarball" >&2; exit 1; }
[ -z "$(tar -tvzf "$IN" | grep -vE '^[-d]' || true)" ] || { echo "Links or special files in the tarball" >&2; exit 1; }

W=$(mktemp -d)
mkdir "$W/app" "$W/key"
cp "$KEY" "$W/key/abscompat.key"
cp "$CRT" "$W/key/abscompat.crt"
trap 'rm -f "$W/key/abscompat.key"' EXIT
# -p: keep the packaged modes whatever the umask is.
tar -xzpf "$IN" -C "$W/app" --no-same-owner --exclude 'abscompat/appinfo/signature.json'
V=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$W/app/abscompat/appinfo/info.xml" | head -1)

# occ can exit 0 although it failed, so the result is checked afterwards.
$D run --rm --network none --entrypoint sh -v "$W/app:/app:Z" -v "$W/key:/key:ro,Z" "$IMAGE" -c \
  'cp -r /usr/src/nextcloud /tmp/nc && cd /tmp/nc && php occ integrity:sign-app --privateKey=/key/abscompat.key --certificate=/key/abscompat.crt --path=/app/abscompat && chmod 644 /app/abscompat/appinfo/signature.json'
python3 -I - "$W/app/abscompat" "$CRT" <<'PY'
import hashlib, json, os, sys
root, crt = sys.argv[1:]
sig = json.load(open(root + '/appinfo/signature.json'))
files = {}
for d, _, names in os.walk(root):
    for n in names:
        p = os.path.join(d, n)
        r = os.path.relpath(p, root)
        if r != 'appinfo/signature.json':
            files[r] = hashlib.sha512(open(p, 'rb').read()).hexdigest()
assert sig['hashes'] == files, 'signature.json does not cover exactly the packaged files'
plain = lambda pem: ''.join(pem.split())  # line breaks differ
assert plain(sig['certificate']) == plain(open(crt).read()), 'signature.json has another certificate'
assert sig['signature'], 'empty signature'
PY

tar -C "$W/app" -czf "$OUT/abscompat-$V.tar.gz" --owner=0 --group=0 --sort=name abscompat
openssl dgst -sha512 -sign "$KEY" -out "$W/sig.bin" "$OUT/abscompat-$V.tar.gz"
openssl dgst -sha512 -verify <(pub) -signature "$W/sig.bin" "$OUT/abscompat-$V.tar.gz"
openssl base64 -A -in "$W/sig.bin" > "$OUT/signature.txt"
echo "Signed $OUT/abscompat-$V.tar.gz"
