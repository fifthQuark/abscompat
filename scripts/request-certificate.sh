#!/usr/bin/env bash
# Step 1 of App Store publishing: create the app's private key and a certificate signing request.
# The key stays on this machine (back it up: losing it means asking for a new certificate).
set -euo pipefail
DIR=${CERT_DIR:-$HOME/.nextcloud/certificates}
mkdir -p "$DIR" && chmod 700 "$DIR"
if [ -e "$DIR/abscompat.key" ]; then
  echo "$DIR/abscompat.key already exists; not overwriting." >&2
  exit 1
fi
openssl req -nodes -newkey rsa:4096 -keyout "$DIR/abscompat.key" -out "$DIR/abscompat.csr" -subj "/CN=abscompat"
chmod 600 "$DIR/abscompat.key"
cat <<MSG

Created:
  $DIR/abscompat.key   (private, keep it safe)
  $DIR/abscompat.csr

Next: open a pull request to https://github.com/nextcloud/app-certificate-requests adding
abscompat/abscompat.csr (the file above). When it is merged, save the issued certificate as
$DIR/abscompat.crt and run scripts/release.sh.
MSG
