# Releasing to the Nextcloud App Store

One-time setup:

1. Create the signing key and certificate request. The key stays in
   `~/.nextcloud/certificates/`; back it up.

   ```bash
   scripts/request-certificate.sh
   ```

2. Open a pull request to [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
   that adds `abscompat/abscompat.csr`. Once it is merged, save the issued certificate as
   `~/.nextcloud/certificates/abscompat.crt`.
3. Log in to [apps.nextcloud.com](https://apps.nextcloud.com) and register the app id
   (Developer → Register app). Paste the certificate and the signature of the app id:

   ```bash
   echo -n "abscompat" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/abscompat.key | openssl base64 -A
   ```

4. The repository is https://github.com/fifthQuark/abscompat (set in `abscompat/appinfo/info.xml`).

Each release:

1. Bump `<version>` in `abscompat/appinfo/info.xml` and add a `CHANGELOG.md` entry. Check that
   `<nextcloud min-version max-version>` matches the
   [maintained majors](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule),
   and update the CI matrix in `.github/workflows/ci.yml` to match.
2. Run the tests (`dev/test-matrix.sh` after an unsigned build, or let CI do it).
3. Build and sign. This needs the local `abs-nc34` container for `occ integrity:sign-app`:

   ```bash
   scripts/release.sh
   ```

   It prints the tarball path and its signature.
4. Upload the tarball to a GitHub release (the App Store downloads it from a public URL), then on
   apps.nextcloud.com → Upload app release, enter that URL and paste the signature.
