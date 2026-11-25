#!/usr/bin/env python3
"""Submits a published GitHub release to the Nextcloud App Store, using the signature.txt attached
to it. The package is downloaded and checked first; the store gets one request, never repeated,
because it limits uploads.
   APPSTORE_TOKEN=... GH_REPO=owner/repo scripts/appstore-submit.py v1.2.3
"""
import json, os, re, subprocess, sys, tempfile, time, urllib.error, urllib.request

STORE = os.environ.get('APPSTORE_URL', 'https://apps.nextcloud.com/api/v1/apps/releases')
GITHUB = os.environ.get('GITHUB_URL', 'https://github.com')
CERT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'abscompat.crt')


def fail(text):
    print('::error::' + text)
    sys.exit(1)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None  # never send the token anywhere else


def download(url):
    for attempt in range(6):
        try:
            return urllib.request.urlopen(url, timeout=60).read()
        except OSError as e:  # GitHub may need a moment before an asset can be downloaded
            if attempt == 5:
                fail(f'{url}: {e}')
            time.sleep(float(os.environ.get('RETRY_SECONDS', '5')))


tag = sys.argv[1]
if not re.fullmatch(r'v\d+\.\d+\.\d+', tag):
    fail('Not a release tag: ' + tag)
token = os.environ.get('APPSTORE_TOKEN', '').strip()
if not token:
    print(f'::warning::No APPSTORE_TOKEN: {tag} was not submitted to the App Store (docs/RELEASING.md).')
    sys.exit(0)
if not re.fullmatch(r'[A-Za-z0-9]+', token):  # never print any of it: the log is public
    fail('APPSTORE_TOKEN is not just a token: it has spaces, line breaks or other characters in it. Set the secret again (docs/RELEASING.md). Nothing was submitted.')

base = f'{GITHUB}/{os.environ["GH_REPO"]}/releases/download/{tag}'
url = f'{base}/abscompat-{tag[1:]}.tar.gz'
signature = download(base + '/signature.txt').decode().strip()
with tempfile.TemporaryDirectory() as d:
    paths = {n: os.path.join(d, n) for n in ('pkg', 'sig', 'pub')}
    open(paths['pkg'], 'wb').write(download(url))
    subprocess.run(['openssl', 'base64', '-d', '-A', '-out', paths['sig']], input=signature.encode(), check=True)
    subprocess.run(['openssl', 'x509', '-in', CERT, '-pubkey', '-noout', '-out', paths['pub']], check=True)
    if subprocess.run(['openssl', 'dgst', '-sha512', '-verify', paths['pub'], '-signature', paths['sig'], paths['pkg']]).returncode:
        fail(f'signature.txt does not fit {url}; nothing was submitted.')

request = urllib.request.Request(STORE, method='POST',
    data=json.dumps({'download': url, 'signature': signature, 'nightly': False}).encode(),
    headers={'Authorization': 'Token ' + token, 'Content-Type': 'application/json', 'User-Agent': 'release-workflow'})
try:
    with urllib.request.build_opener(NoRedirect).open(request, timeout=300) as answer:
        code, body = answer.status, b''
except urllib.error.HTTPError as e:
    code, body = e.code, e.read()
except OSError as e:
    fail(f'No answer from the App Store: {e}. It may have arrived; look there before trying again.')
print(f'App Store answered {code}')
if code not in (200, 201):
    print(body[:2000].decode(errors='replace'))
    fail(f'The App Store did not accept {tag}. The GitHub release exists (docs/RELEASING.md).')
