# Migration plan: WordPress → zkm-cms on zachschneider.com

Server-specific values (droplet ID, admin login, staging hostname) are written as `<placeholders>`; keep the real ones in your own notes.

**Approach:** run zkm-cms next to WordPress on the same Rocky 10 droplet, import straight from the live WordPress database, test on a private staging hostname, then switch the `www` vhost. WordPress stays installed and untouched, so rolling back means switching the vhost back.

**Why the same droplet:** the importer reads the WordPress database and uploads folder locally, so nothing has to be copied between servers. The box is already hardened (SELinux, firewalld, fail2ban, dnf-automatic), and Apache event, PHP 8.3 FPM, MariaDB 10.11 and Valkey are what zkm-cms needs. zkm-cms uses less memory than WordPress with its plugins, which matters on 1 GB.
*Alternative:* a fresh $6 droplet with a reserved-IP swap, like the Oct 8 move. That gives a cleaner rollback, but means a second box to harden and copying ~265 MB of uploads across.

---

## Where things stand (local build, Oct 28)

- The site renders with the zkm-wp-theme design. Home, blog, posts, pages, archives, the contact form, the feed, the sitemap and robots.txt all work.
- 43 of the 51 live sitemap URLs work locally (`/author/admin/` now redirects to `/blog/`). The other 8 are the **Skywatch post and its tags**, which were published after the Oct 8 backup. A fresh import closes the gap (Phase 1).
- The import brought over 222 posts and pages, 209 media items with all their sizes, 219 categories and tags, 54 comments (archived), both menus, the settings, the Tag Manager ID and the site icon. Re-running the import is idempotent.
- The admin has sign-in (password plus optional TOTP, throttled), editing, a live preview, revisions, media upload, menus, redirects, messages and settings.
- 21 PHPUnit tests pass, covering the converter, TOTP, the .wpress reader and permalink routing.

### Open items before cutover

| # | Item | Size |
| --- | --- | --- |
| 1 | **Fresh content.** Import from the live database (Phase 2 does this) so Skywatch and the latest About-page edit come over. | covered by the plan |
| 2 | **Deploy script.** Build locally (`composer install --no-dev`, `npm run build`), then rsync to the server without `writable/`, `public/uploads/` or `.env`. | small |
| 3 | **Lock the origin to Cloudflare.** Allow ports 80 and 443 on the droplet only from Cloudflare's ranges (DigitalOcean firewall), so nobody can skip Cloudflare by connecting to the server's IP. The app only trusts `CF-Connecting-IP` from those ranges either way. | small |
| 4 | **fail2ban jail with the Cloudflare action** (see *Security* below). A firewall ban on the droplet does nothing, because visitors reach it through Cloudflare's addresses. | small |
| 4a | **Admin access gate:** Cloudflare Access in front of `/admin`, plus the AUD check in `.env` (see *Security → Admin access*). Do this before the first sign-in on staging. | small |
| 4b | **Backups:** nightly database dump and off-box copy of uploads; droplet backups on (see *Security → Backups*). | small |
| 5 | **Cloudflare HTML caching.** Public pages never set cookies, so a Cache Rule can cache HTML at the edge, bypassing `/admin/*` and `/contact-form`. Optional. | optional |
| 6 | Review the 155 private and 47 draft posts. Old classic-editor posts get automatic paragraphs and may need touch-ups. Some 2008–09 posts embed Flash, which no browser plays now. | your call |
| 7 | The one past Fluent Forms submission isn't migrated. Export it from WP admin if you want it. | trivial |

---

## Phase 1 — Finish locally

1. Work through open items 2–4.
2. Take a fresh All-in-One export, then run `wp:load` and `wp:import`, so local review uses current content.
3. Run `scripts/check-urls.sh https://www.zachschneider.com http://localhost:8090`. It should report that all paths work.
4. Click through the published posts and pages next to the live site in light and dark mode, and on a phone.

## Phase 2 — Stage on the droplet (visitors see no change)

All commands run on the droplet unless noted.

1. **Snapshot first** (from your machine):
   `doctl compute droplet-action snapshot <droplet-id> --snapshot-name zachschneider-pre-zkm-cms-$(date +%F) --wait`
2. **PHP extensions.** Check with `php -m | grep -Ei 'intl|mysqli|gd|redis|mbstring'` and install what's missing: `sudo dnf install php-intl php-mysqlnd php-gd php-pecl-redis php-mbstring`, then `sudo systemctl reload php-fpm`.
3. **Code** goes in `/var/www/zkm-cms`, deployed by rsync from the deploy script. Ownership is `zkm:apache`, and only `writable/` and `public/uploads/` are writable by Apache.
4. **SELinux:**
   ```bash
   sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/zkm-cms/(writable|public/uploads)(/.*)?'
   sudo restorecon -Rv /var/www/zkm-cms
   ```
   Check whether `httpd_can_network_connect` is needed for SMTP to Proton: `getsebool httpd_can_network_connect`. WP Mail SMTP already sends from this box, so it is probably on.
5. **Database:**
   ```sql
   CREATE DATABASE zkm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'zkm'@'localhost' IDENTIFIED BY '…';
   GRANT ALL ON zkm.* TO 'zkm'@'localhost';
   -- Read-only access to WordPress, for the importer only
   CREATE USER 'zkm_wp_ro'@'localhost' IDENTIFIED BY '…';
   GRANT SELECT ON wordpress.* TO 'zkm_wp_ro'@'localhost';
   ```
   (Look up the real WordPress database name in `wp-config.php`.)
6. **Config:** copy `env.production.example` to `/var/www/zkm-cms/.env` (mode 640, `root:apache`). Fill in the passwords and the Proton SMTP token from WP Mail SMTP. For now, set `app.baseURL` to the staging hostname. Then:
   ```bash
   cd /var/www/zkm-cms
   sudo -u apache php spark key:generate
   sudo -u apache php spark migrate
   ```
7. **Import from the live site.** Uncomment the `database.wordpress.*` lines and run:
   `sudo -u apache php spark wp:import --uploads /var/www/html/wp-content/uploads`
8. **Admin user:** `php spark user:create <admin-email> "<name>"`, then `php spark user:totp <admin-email>`. In production, sign-in is refused until two-factor is set up.
9. **Staging vhost** at `<staging-host>` (a subdomain of zachschneider.com), with DocumentRoot `/var/www/zkm-cms/public`, `AllowOverride All`, and the same Cloudflare Origin CA cert, which covers `*.zachschneider.com` if it was issued that way (check). Add a proxied DNS record in Cloudflare and keep it private with a **Cloudflare Access** policy that allows only your email. `sudo apachectl configtest && sudo systemctl reload httpd`.
10. **Test on staging:**
    - `scripts/check-urls.sh https://www.zachschneider.com https://<staging-host>`. Access blocks curl unless you pass a service token, so the alternative is to run it on the droplet against `http://127.0.0.1` with a `Host:` header.
    - Send a contact form message and confirm it reaches the Proton inbox.
    - Edit a post in the admin and check that the public page updates (the cache is flushed on save).
    - Sign in with TOTP and confirm that five wrong passwords trigger the throttle.
    - Run Lighthouse on home and a post.
    - `scripts/security-check.py https://<staging-host>` from a machine Access lets through (no `--uploads-dir` on a real server).

## Phase 3 — Cutover (about 15 minutes, at a quiet hour)

1. **Freeze WordPress:** don't publish from here on.
2. **Save the URL list** while the old sitemap still exists:
   `scripts/check-urls.sh --save urls.txt https://www.zachschneider.com` (from your machine).
3. **Final import**, to pick up anything since staging. It only updates rows; nothing is duplicated:
   `sudo -u apache php spark wp:import --uploads /var/www/html/wp-content/uploads`
4. In `.env`, set `app.baseURL = 'https://www.zachschneider.com/'` and comment out the `database.wordpress.*` lines.
5. **Switch the vhost:** in the `www.zachschneider.com` vhost, change `DocumentRoot` and `<Directory>` from `/var/www/html` to `/var/www/zkm-cms/public`. Keep a copy of the old file as `…conf.wordpress`. Then `sudo apachectl configtest && sudo systemctl reload httpd`.
6. **Purge Cloudflare:** Caching → Purge Everything. The WordPress Cloudflare plugin used to do this for you.
7. **Verify:**
   - `scripts/check-urls.sh urls.txt https://www.zachschneider.com`: every path should work.
   - `curl -I https://www.zachschneider.com/wp-login.php` should return 410.
   - `curl -I https://www.zachschneider.com/wp-content/uploads/2026/10/zach-portrait.webp` should 301 to `/uploads/…`.
   - Check that the feed works in a reader and that the sitemap URL is right. In Search Console, submit `/sitemap.xml` in place of `/wp-sitemap.xml`.
   - Check that the share card renders (paste a post URL into Mastodon), and that Analytics and Tag Manager fire (GA4 Realtime, the GTM preview). The Content-Security-Policy allows Google's tag scripts; a Custom HTML tag in the GTM container that loads scripts from other domains would be blocked, so check the browser console.
   - `scripts/security-check.py https://www.zachschneider.com`: every check should pass.
8. **Stop WordPress background jobs:** `sudo systemctl disable --now wp-cron.timer`. Use the timer's real name on the box. Leave the WordPress files and database where they are.
9. **Watch it for a day:** `/var/log/httpd/*error_log`, `writable/logs/`, and `fail2ban-client status`.

### Rollback (any time in the first two weeks)

1. Restore the `…conf.wordpress` vhost, then `sudo apachectl configtest && sudo systemctl reload httpd`.
2. Purge Cloudflare.
3. Re-enable `wp-cron.timer`.

The importer never writes to WordPress, so WordPress comes back exactly as it was. Anything published in zkm-cms after cutover would have to be re-entered by hand.

## Phase 4 — Clean up (after about two weeks without trouble)

1. Dump the WordPress database and tar `/var/www/html` into an off-box backup. Then remove the WordPress vhost, the files, the `wordpress` database and the `zkm_wp_ro` user.
2. Remove the WordPress-only pieces: the wp-cron timer and its PHP settings. Swap the WordPress fail2ban jails (`wp-login`, `xmlrpc`) for the zkm-cms jail. Disconnect Site Kit, BlogVault and similar services on their side.
3. Delete the `<staging-host>` DNS record and its Access policy, or keep it as a permanent staging site.
4. Take a new snapshot and drop the pre-cutover one once you're comfortable.

## Security

Done in the code (tests in `tests/`, HTTP checks in `scripts/security-check.py`, both in CI):

- **Sign-in:**
  - Password plus a required authenticator code in production. Each code works only once, and the secret is encrypted with `encryption.key`.
  - Limits of 5 tries a minute per address and 10 per 15 minutes per account.
  - The same work for unknown emails, so timing doesn't show which accounts exist.
  - A new session ID and CSRF token after sign-in.
- **Admin:**
  - CSRF on every write; API responses answer 401 when signed out.
  - A strict Content-Security-Policy (`script-src 'self'`) and no framing.
  - The editor preview is a sandboxed iframe, so scripts in a post body can't run with admin rights.
- **Public site:**
  - A Content-Security-Policy with `object-src 'none'`, and inline scripts allowed only in `<head>` (by hash).
  - No session cookies; HSTS in production; visitors' real IPs read from Cloudflare.
- **Uploads:**
  - Only listed file types, with the extension checked against the content.
  - Images over 40 megapixels refused.
  - Nothing under `/uploads/` runs as PHP or is served as HTML, and files are sent with a `sandbox` CSP, so an uploaded SVG can't run script.
- **Other:**
  - The contact form's return path must be on the site, and names are stripped of line breaks before going into email headers.
  - WordPress endpoints return 410.
  - `composer audit` and `npm audit` run in CI, and Dependabot opens update PRs.

- **Sessions:** a session ends after 8 hours idle or 12 hours in total, and immediately if the user is deleted. Changing the password, the sign-in email or two-factor signs out every other session. The **Account** page also has "Sign out everywhere else".
- **Logging:** production keeps warnings and info, so failed, throttled and successful sign-ins reach `writable/logs/` for fail2ban. The framework's default drops them.

### Admin access

Anyone on the internet can reach `/admin/login` until you restrict it. Rate limits and two-factor protect the sign-in, but restricting access also stops guessing and lock-out attempts. Two options, which can be combined; anyone outside them gets the site's normal 404:

1. **Cloudflare Access (recommended).** It's free for up to 50 users, and you sign in through Cloudflare (a one-time email code, or Google/GitHub) before the admin page is even sent.
   1. Cloudflare dashboard → **Zero Trust** → **Access** → **Applications** → **Add an application** → **Self-hosted**.
   2. Domain `www.zachschneider.com`, path `admin`, and add a second entry for path `admin/*`. Set the session duration to, say, 24 hours.
   3. Policy: **Allow**, *Emails*, set to your address only.
   4. Copy the application's **Audience (AUD) tag** (Overview tab), and note your team name (Settings → Custom Pages, `<team>.cloudflareaccess.com`).
   5. In `.env`, set `admin.accessTeam = '<team>'`, `admin.accessAud = '<AUD tag>'` and `admin.accessEmails = '<your email>'`.

   The app checks Cloudflare's signed token on every admin request. Without that check, someone who reached the server's IP directly could skip Access. The keys are fetched from Cloudflare and cached for an hour.
2. **IP allowlist.** `admin.allowedIps = '203.0.113.7, 2001:db8::/48'` uses your home or VPN addresses, read through Cloudflare (`CF-Connecting-IP`). Home connections often change address, so pair it with Access or keep a way in, such as SSH, to edit `.env`.

Then check from a machine outside the allowed set: `scripts/security-check.py https://www.zachschneider.com --expect-admin-gated` should report `/admin` as 404. If you lock yourself out, comment the lines in `.env` over SSH.

### Defence in depth for uploads

`public/uploads/.htaccess` stops PHP running in the uploads folder, but only if Apache reads `.htaccess` files there. Repeat the rule in the vhost so it holds even if `AllowOverride` changes:

```apache
<Directory /var/www/zkm-cms/public/uploads>
    AllowOverride All
    <FilesMatch "\.(php\d?|phtml|phar|html?|shtml|xhtml)$">
        Require all denied
    </FilesMatch>
    Header always set Content-Security-Policy "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; media-src 'self'; sandbox"
    Header always set X-Content-Type-Options "nosniff"
</Directory>
```

`scripts/security-check.py https://www.zachschneider.com` checks both from the outside, without writing anything to the server.

### Backups

The droplet becomes the only copy of anything you write after cutover.

- **Nightly:** dump the database (`mysqldump --single-transaction zkm | gzip > /var/backups/zkm-$(date +%F).sql.gz`, keeping 14 days) and `rsync` `public/uploads/` and the dumps off the box, for example to DigitalOcean Spaces or your RAID at home.
- **Weekly:** turn on DigitalOcean droplet backups (20% of the droplet price) for whole-machine restores.
- **Keep `.env` in your password manager,** especially `encryption.key`. Without it, the stored authenticator secrets can't be read; you'd run `user:totp` again.
- **Test a restore once,** into the local Docker stack: load the dump into `zkm` and copy the uploads into `public/uploads/`.

### What's left (accepted risks)

- **Contact form spam:** it's limited to 5 messages an hour per address, with a honeypot. If spam gets through, add Cloudflare Turnstile.
- **One admin, no recovery codes:** if you lose your phone, run `php spark user:totp` over SSH. SSH access is the recovery path, so keep it safe.
- **Third-party scripts:** the Google tag and Tag Manager run on public pages, so anyone who can edit the GTM container can run script on the site. Keep that Google account protected with two-factor.
- **Trust in Cloudflare:** Cloudflare sees all traffic and is trusted for visitor addresses and Access identities.

### Server setup

1. `.env` needs `encryption.key` from `php spark key:generate` **before** `php spark user:totp`. Back up the key: without it, the stored authenticator secrets can't be read, and you'd have to run `user:totp` again.
2. fail2ban, banning at Cloudflare. Create an API token with *Account Filter Lists: Edit* or *Zone Firewall: Edit*, then add:

   `/etc/fail2ban/filter.d/zkm-cms.conf`
   ```ini
   [Definition]
   failregex = ^WARNING - \S+ \S+ --> Admin sign-in (?:failed|throttled|refused) for <HOST>
   datepattern = ^WARNING - %%Y-%%m-%%d %%H:%%M:%%S
   ```
   `/etc/fail2ban/jail.d/zkm-cms.local`
   ```ini
   [zkm-cms]
   enabled  = true
   filter   = zkm-cms
   logpath  = /var/www/zkm-cms/writable/logs/log-*.log
   maxretry = 5
   findtime = 600
   bantime  = 86400
   action   = cloudflare-token[cfzone="zachschneider.com", cftoken="<token>"]
   ```
   Test the filter with `fail2ban-regex /var/www/zkm-cms/writable/logs/log-$(date +%F).log /etc/fail2ban/filter.d/zkm-cms.conf`.
3. Cloudflare: turn on *Always Use HTTPS*. Consider a WAF rule or rate limit for `/admin/*` and `/contact-form`, or put `/admin` behind Cloudflare Access too.

## Things that intentionally change

- The footer says "Built on CodeIgniter". Edit or clear it in Settings.
- `/wp-admin`, `/wp-login.php`, `/xmlrpc.php` and `/wp-json` return **410 Gone**.
- Comments stay hidden, as before, and new comments aren't possible. There is no search page; the theme never had a search form.
- oEmbed discovery and pingbacks for the site's own posts are gone.
- Post dates use America/Chicago, so they observe daylight saving. WordPress was set to a fixed UTC−6, so summer-time timestamps move by an hour; the dates themselves don't change.
