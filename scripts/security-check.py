#!/usr/bin/env python3
"""HTTP-level security checks against a running zkm-cms (Apache rules included).

    scripts/security-check.py http://localhost:8090
    scripts/security-check.py http://localhost:8090 --email you@example.com --password '…'
    scripts/security-check.py http://localhost:8090 --uploads-dir public/uploads
    scripts/security-check.py https://www.zachschneider.com --expect-admin-gated

PHPUnit covers the application code; this covers what only the web server can show:
that private files aren't served, that nothing under /uploads/ runs, and the headers
real responses carry. --uploads-dir plants (and removes) probe files in the uploads
folder, so only use it on a local or CI copy. With --email and --password it also signs
in (without two-factor) and checks the upload rules and CSRF on the API.

For a site behind Cloudflare Access, set CF_ACCESS_CLIENT_ID and CF_ACCESS_CLIENT_SECRET
to a service token the Access app allows (a Service Auth policy); every request sends them.
The app still answers /admin with 404 to a service token, so add --expect-admin-gated.

Exits 1 if any check fails. Uses only the standard library.
"""

import argparse
import http.cookiejar
import json
import os
import re
import struct
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib

failures = 0


def check(ok, label, detail=''):
    global failures
    print(('  ok    ' if ok else '  FAIL  ') + label + (f'  ({detail})' if detail and not ok else ''))
    if not ok:
        failures += 1


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def access_headers():
    """A Cloudflare Access service token from the environment, to get past an Access gate."""
    client_id, secret = os.environ.get('CF_ACCESS_CLIENT_ID'), os.environ.get('CF_ACCESS_CLIENT_SECRET')
    return {'CF-Access-Client-Id': client_id, 'CF-Access-Client-Secret': secret} if client_id and secret else {}


class Client:
    def __init__(self, base):
        self.base = base.rstrip('/')
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        self.extra = access_headers()

    def request(self, method, path, data=None, headers=None):
        req = urllib.request.Request(self.base + path, data=data, method=method, headers={'User-Agent': 'zkm-cms security check', **self.extra, **(headers or {})})
        try:
            res = self.opener.open(req, timeout=20)
            return res.status, res.headers, res.read()
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read()


def tiny_png(width, height):
    """A valid PNG header claiming the given size (the pixel data is not needed for getimagesize)."""
    def chunk(kind, body):
        return struct.pack('>I', len(body)) + kind + body + struct.pack('>I', zlib.crc32(kind + body) & 0xFFFFFFFF)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0)) + chunk(b'IEND', b'')


def multipart(name, content, ctype):
    boundary = uuid.uuid4().hex
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\n'
            f'Content-Type: {ctype}\r\n\r\n').encode() + content + f'\r\n--{boundary}--\r\n'.encode()
    return body, f'multipart/form-data; boundary={boundary}'


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('base')
    parser.add_argument('--email')
    parser.add_argument('--password')
    parser.add_argument('--uploads-dir')
    parser.add_argument('--expect-admin-gated', action='store_true', help='/admin should answer 404 to this machine (IP allowlist or Cloudflare Access)')
    args = parser.parse_args()
    site = Client(args.base)
    host = urllib.parse.urlparse(args.base).netloc

    print('Private files are not served')
    for path in ['/.env', '/env', '/composer.json', '/composer.lock', '/spark', '/app/Config/App.php',
                 '/writable/logs/', '/vendor/autoload.php', '/.git/HEAD', '/docker-compose.yml', '/backups/']:
        status, _, body = site.request('GET', path)
        check(status in (403, 404) and b'DB_PASSWORD' not in body and b'database.default' not in body, f'{path} -> {status}')

    print('Old WordPress endpoints are gone')
    for path in ['/wp-login.php', '/xmlrpc.php', '/wp-admin/', '/wp-json/wp/v2/users', '/wp-content/plugins/x.php']:
        status, _, _ = site.request('GET', path)
        check(status == 410, f'{path} -> {status}')

    print('Uploads folder')
    status, _, body = site.request('GET', '/uploads/')
    check(status in (403, 404), f'/uploads/ is not listed -> {status}')

    # Without planting files: these answers only come from public/uploads/.htaccess, so
    # they show Apache is reading it (AllowOverride). Safe to run against production.
    status, _, _ = site.request('GET', '/uploads/zz-missing-probe.php')
    check(status == 403, f'uploads rules are active: a .php there is refused -> {status}', 'expected 403; is AllowOverride on for /uploads?')
    # A real file is needed here (a missing one falls through to the app's 404): use the
    # first /uploads/ image the home page links to.
    _, _, home = site.request('GET', '/')
    found = re.search(rb'(?:src|href)="(/uploads/[^"?#]+)"', home)
    if found:
        upload = found.group(1).decode()
        status, headers, _ = site.request('GET', upload)
        check(status == 200 and 'sandbox' in headers.get('Content-Security-Policy', ''), f'uploads rules are active: {upload} is served sandboxed', headers.get('Content-Security-Policy', 'no CSP'))
    else:
        print('  (no /uploads/ file linked from the home page to check the sandbox header on)')

    if args.uploads_dir:
        probes = {
            'zz-probe.php': (b'<?php echo "EXECUTED-" . (6*7);', None),
            'zz-probe.phtml': (b'<?php echo "EXECUTED-" . (6*7);', None),
            'zz-probe.html': (b'<script>alert(1)</script>', None),
            'zz-probe.svg': (b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'svg'),
        }
        for name, (content, _) in probes.items():
            with open(os.path.join(args.uploads_dir, name), 'wb') as f:
                f.write(content)
        try:
            for name in ['zz-probe.php', 'zz-probe.phtml']:
                status, _, body = site.request('GET', '/uploads/' + name)
                check(b'EXECUTED-42' not in body and status in (403, 404), f'/uploads/{name} is not run -> {status}')
            status, _, _ = site.request('GET', '/uploads/zz-probe.html')
            check(status in (403, 404), f'/uploads/zz-probe.html is not served -> {status}')
            status, headers, _ = site.request('GET', '/uploads/zz-probe.svg')
            csp = headers.get('Content-Security-Policy', '')
            check(status == 200 and 'sandbox' in csp and "default-src 'none'" in csp, 'uploaded SVG is served sandboxed', csp or f'status {status}')
            check(headers.get('X-Content-Type-Options') == 'nosniff', 'uploads send nosniff')
        finally:
            for name in probes:
                try:
                    os.remove(os.path.join(args.uploads_dir, name))
                except FileNotFoundError:
                    pass

    print('Errors show no internals')
    status, _, body = site.request('GET', '/zz-no-such-page-probe/')
    leaked = [word for word in (b'Kint', b'Stack trace', b'APPPATH', b'SYSTEMPATH', b'vendor/codeigniter4', b'Exception') if word in body]
    check(status == 404 and not leaked, f'404 page -> {status}', 'shows ' + b', '.join(leaked).decode() if leaked else '')

    print('Headers')
    status, headers, body = site.request('GET', '/')
    csp = headers.get('Content-Security-Policy', '')
    check(status == 200, f'/ -> {status}')
    check("object-src 'none'" in csp and "base-uri 'self'" in csp and 'sha256-' in csp, 'public CSP', csp)
    check(headers.get('X-Content-Type-Options') == 'nosniff', 'public nosniff')
    check('zkm_session' not in (headers.get('Set-Cookie') or ''), 'public pages set no session cookie')
    check(not headers.get('X-Powered-By'), 'no X-Powered-By', headers.get('X-Powered-By', ''))

    status, headers, body = site.request('GET', '/admin/login')
    csp = headers.get('Content-Security-Policy', '')
    check("script-src 'self';" in csp and "frame-ancestors 'none'" in csp, 'admin CSP', csp)
    check(headers.get('X-Frame-Options') == 'DENY', 'admin cannot be framed')
    check('no-store' in headers.get('Cache-Control', ''), 'sign-in page is not cached')

    if args.expect_admin_gated:
        print('Admin gate')
        for path in ['/admin/login', '/admin/', '/admin/api/posts']:
            status, _, _ = site.request('GET', path)
            check(status == 404, f'{path} is hidden from this machine -> {status}')
        print()
        print('All security checks passed.' if failures == 0 else f'{failures} security check(s) failed.')
        return 1 if failures else 0

    print('Signed-out API and redirects')
    status, _, _ = site.request('GET', '/admin/api/posts', headers={'Accept': 'application/json'})
    check(status == 401, f'/admin/api/posts signed out -> {status}')
    status, _, _ = site.request('GET', '/admin/')
    check(status in (302, 303), f'/admin/ signed out redirects -> {status}')
    for target in ['//evil.example/', '/\\evil.example/', 'https://evil.example/']:
        data = urllib.parse.urlencode({'website': 'bot', 'return': target}).encode()
        status, headers, _ = site.request('POST', '/contact-form', data=data, headers={'Content-Type': 'application/x-www-form-urlencoded'})
        location = headers.get('Location', '')
        check(urllib.parse.urlparse(location).netloc == host and 'evil.example' not in location, f'contact return={target!r} stays on site', location)

    if not (args.email and args.password):
        print('(Skipping signed-in checks: pass --email and --password.)')
    else:
        print('Signed in')
        admin = Client(args.base)
        _, _, html = admin.request('GET', '/admin/login')
        token = re.search(rb'name="csrf_token" value="([^"]+)"', html).group(1).decode()
        form = urllib.parse.urlencode({'csrf_token': token, 'email': args.email, 'password': args.password}).encode()
        status, headers, _ = admin.request('POST', '/admin/login', data=form, headers={'Content-Type': 'application/x-www-form-urlencoded'})
        check(status == 303 and headers.get('Location', '').endswith('/admin/'), f'sign in -> {status} {headers.get("Location")}')
        _, _, spa = admin.request('GET', '/admin/')
        match = re.search(rb'name="csrf-token" content="([^"]+)"', spa)
        token = match.group(1).decode() if match else ''
        check(bool(token), 'admin page carries a CSRF token')

        status, _, _ = admin.request('PUT', '/admin/api/settings', data=b'{"tagline":"csrf"}', headers={'Content-Type': 'application/json', 'Accept': 'application/json'})
        check(status == 403, f'API write without CSRF token -> {status}')

        auth = {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}
        for name, content, ctype in [('evil.php', b'<?php echo 1;', 'application/x-php'),
                                     ('evil.php.jpg', b'<?php echo 1;', 'image/jpeg'),
                                     ('page.html', b'<script>alert(1)</script>', 'text/html'),
                                     ('huge.png', tiny_png(20000, 20000), 'image/png')]:
            body, ctype_header = multipart(name, content, ctype)
            status, _, resp = admin.request('POST', '/admin/api/media', data=body, headers={**auth, 'Content-Type': ctype_header})
            # The oversized image must be refused for its size, not for some other reason.
            reason_ok = b'megapixels' in resp if name == 'huge.png' else True
            check(status == 422 and reason_ok, f'upload {name} is refused -> {status}', resp[:120].decode(errors='replace'))

        admin.request('POST', '/admin/logout', data=urllib.parse.urlencode({'csrf_token': token}).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
        status, _, _ = admin.request('GET', '/admin/api/posts', headers={'Accept': 'application/json'})
        check(status == 401, f'signed out after logout -> {status}')

    print()
    print('All security checks passed.' if failures == 0 else f'{failures} security check(s) failed.')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
