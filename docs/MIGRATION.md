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
| 3 | **Encrypt the TOTP secret at rest** with `encryption.key`. It's stored in plain text today. | small |
| 4 | **fail2ban jail** for `Admin sign-in failed for <ip>` in `writable/logs/log-*.log`. | small |
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
   - Check that the share card renders (paste a post URL into Mastodon) and that Tag Manager fires (the GTM preview).
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

## Things that intentionally change

- The footer says "Built on CodeIgniter". Edit or clear it in Settings.
- `/wp-admin`, `/wp-login.php`, `/xmlrpc.php` and `/wp-json` return **410 Gone**.
- Comments stay hidden, as before, and new comments aren't possible. There is no search page; the theme never had a search form.
- oEmbed discovery and pingbacks for the site's own posts are gone.
- Post dates use America/Chicago, so they observe daylight saving. WordPress was set to a fixed UTC−6, so summer-time timestamps move by an hour; the dates themselves don't change.
