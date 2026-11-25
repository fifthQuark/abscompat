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

## Automatic releases

`.github/workflows/release.yml` releases whenever a tag like `v1.0.0` is pushed (or a release with a
new tag is created on the GitHub website). It builds without secrets, signs, creates the GitHub
release and submits it to the App Store. It refuses a tag that does not match `<version>` in
`appinfo/info.xml`, is not on `main`, or whose `CHANGELOG.md` entry has no date. It does not wait for
CI or for approval: check that CI is green before tagging.

Setup, once. Add the repository secrets yourself:

```bash
gh secret set APP_PRIVATE_KEY --repo fifthQuark/abscompat < ~/.nextcloud/certificates/abscompat.key
```

```bash
gh secret set APPSTORE_TOKEN --repo fifthQuark/abscompat
```

The second asks for the token, which is under My account → API token on
[apps.nextcloud.com](https://apps.nextcloud.com). Without it the App Store step is skipped.

To release: bump the version, date the changelog, push to `main`, wait for CI, then push the tag:

```bash
git tag v1.0.0 && git push origin v1.0.0
```

If the App Store refuses a release, the GitHub release already exists and is signed: upload it by
hand with its URL and the `signature.txt` attached to it. Don't re-run the job, and don't replace
the files: a new build needs a new signature.

`scripts/abscompat.crt` is the public certificate. Replace it when it is renewed (valid to
2037-02-28). `SIGN_IMAGE` in `scripts/sign-release.sh` and the actions are pinned; update them now
and then.

## Manual release

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
