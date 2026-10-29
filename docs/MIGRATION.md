# Migration plan: WordPress → zkm-cms on zachschneider.com

Server-specific values (droplet IDs and addresses, the reserved IP, the staging hostname, Access IDs, the admin login) are written as `<placeholders>`; keep the real ones in your own notes.

**Approach:** run zkm-cms on a new droplet cloned from a snapshot of the WordPress droplet, so it starts with the same hardening (SELinux, firewalld, fail2ban, dnf-automatic) and the same Apache event, PHP 8.3 FPM, MariaDB 10.11 and Valkey. Test it on a private staging hostname behind Cloudflare Access, then move the site's reserved IP from the old droplet to the new one. DNS doesn't change: the apex A record points at the reserved IP and `www` is a CNAME to the apex. WordPress stays untouched on the old droplet, so rolling back means moving the IP back.

*Why not the same droplet* (the first plan): running both side by side would have let the importer read WordPress locally, but on 1 GB the two stacks compete for memory, and rolling back would mean editing vhosts under pressure instead of moving an IP.

**Content comes from the local copy, not from the server.** The import ran locally against a WordPress backup, and the review happened there: posts made private, their archives answering 410, and clean-up edits. That database and the uploads were copied to the new droplet. Don't run `wp:import` on the server; it would bring back what the review changed.

---

## Where things stand

- **Local (Oct 28):** the site renders with the zkm-wp-theme design; the admin has sign-in with two-factor, editing, preview, revisions, media, menus, redirects, messages and settings. PHPUnit, the security checks and the admin smoke test run in CI.
- **Staging (Oct 29):** deployed on the new droplet behind Cloudflare Access. Every URL in the live sitemap resolves (the archived posts and their empty archives answer 410 on purpose), `security-check.py` passes, the contact form sends mail, and nightly backups run.

### Open items before cutover

| # | Item | Status |
| --- | --- | --- |
| 1 | **Fresh content.** Reviewed and edited locally, then copied to the server (see *Approach*). | done |
| 2 | **Deploy script.** `scripts/deploy.sh <user@host>` builds HEAD locally (`composer install --no-dev`, `npm run build`) and shows what rsync would change; `--go` deploys, without touching `writable/`, `public/uploads/` or `.env`, then runs migrations and clears the cache. | done |
| 3 | **Lock the origin to Cloudflare.** The new droplet's DigitalOcean firewall allows ports 80 and 443 only from Cloudflare's ranges, so nobody can skip Cloudflare by connecting to the server's IP. The app only trusts `CF-Connecting-IP` from those ranges either way. | done |
| 4 | **fail2ban jail with the Cloudflare action.** Dropped: behind Cloudflare Access it could only ban the site owner (see *Security → Server setup*). | dropped |
| 4a | **Admin access gate:** Cloudflare Access plus the AUD check in `.env` (see *Security → Admin access*). On staging now; `www` gets it in Phase 3. | done for staging |
| 4b | **Backups:** nightly database dump, off-box copy of the dumps and uploads, droplet backups on (see *Security → Backups*). | done |
| 5 | **Cloudflare HTML caching.** Public pages never set cookies, so a Cache Rule can cache HTML at the edge, bypassing `/admin/*` and `/contact-form`. | optional |
| 6 | Review the private and draft posts. Old classic-editor posts get automatic paragraphs and may need touch-ups. Some 2008–09 posts embed Flash, which no browser plays now. | your call |
| 7 | The one past Fluent Forms submission isn't migrated. Export it from WP admin if you want it. | trivial |

---

## Phase 1 — Finish locally (done)

1. Take a fresh All-in-One export, then run `wp:load` and `wp:import`.
2. Review and edit the content locally.
3. `scripts/check-urls.sh https://www.zachschneider.com http://localhost:8090` should report that every path works or answers 410 on purpose.

## Phase 2 — Stage on a new droplet (done; visitors see no change)

Commands run on the new droplet unless noted. Gate every delete behind a check that you're on the new droplet (`[ "$(hostname)" = <new-hostname> ]`), because the clone looks exactly like the live box.

1. **Clone.** Snapshot the WordPress droplet, then create `<new-droplet>` from the snapshot (same size and region). Disable WordPress's timers on the clone right away.
2. **Make the clone its own machine.** It carries the old droplet's SSH host keys and machine-id:
   ```bash
   sudo rm -f /etc/ssh/ssh_host_* && sudo ssh-keygen -A && sudo restorecon /etc/ssh/ssh_host_*
   sudo rm /etc/machine-id && sudo systemd-machine-id-setup
   sudo systemctl reboot
   ```
   Rocky 10 has no `ssh_keys` group; the host keys are root-only (`0600`). On your machine, replace the clone's `known_hosts` entry only after the key it presents matches `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`.
3. **Firewall:** a DigitalOcean firewall on the new droplet allowing 80 and 443 from Cloudflare's ranges only, and 22 for SSH.
4. **Remove WordPress from the clone:** `/var/www/html`, the `wordpress` database and user, the wp-cron units and WP-CLI. The old droplet keeps all of it for rollback.
5. **PHP extensions.** Check with `php -m | grep -Ei 'intl|mysqli|gd|redis|mbstring|sodium'`.
6. **Directories and SELinux.** `/var/www/zkm-cms` and `public/` are `zkm:apache` 750. `writable/` (with `cache`, `logs`, `session`, `uploads`, `debugbar`) and `public/uploads/` are owned by `apache`, and sessions go in `/var/lib/zkm-cms/sessions` (700, `apache`). Only those are writable by Apache:
   ```bash
   sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/zkm-cms/(writable|public/uploads)(/.*)?'
   sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zkm-cms(/.*)?'
   sudo restorecon -R /var/www/zkm-cms /var/lib/zkm-cms
   ```
7. **Database:**
   ```sql
   CREATE DATABASE zkm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'zkm'@'localhost' IDENTIFIED BY '…';
   GRANT ALL ON zkm.* TO 'zkm'@'localhost';
   ```
8. **Config:** `/var/www/zkm-cms/.env` from `env.production.example`, mode 640, `root:apache`. It needs a new `encryption.key` (`php spark key:generate`, or `hex2bin:` plus 32 random bytes in hex), the database password, a Proton SMTP token of its own (Proton → Settings → IMAP/SMTP → SMTP tokens), and `app.baseURL = 'https://<staging-host>/'`. Keep a copy in your password manager.
9. **Deploy** (from your machine): `scripts/deploy.sh <user@new-droplet>` for the dry run, then `--go`. It runs the migrations.
10. **Copy the content** (from your machine):
    - Dump the local database (`docker compose exec -T db mariadb-dump -uzkm -pzkm --single-transaction zkm`), load it into `zkm` on the server, delete local test messages, and run `php spark cache:clear`.
    - `rsync` `public/uploads/` to the server with `--rsync-path='sudo rsync' --chown=apache:apache`, excluding `/.htaccess` (the deploy sends that one). Dry run first.
    - **Admin user:** the local one comes along with the database. Change its email to the private sign-in address (`user:email`), set a password (`user:create`) and set up two-factor again (`user:totp`): the server's `encryption.key` can't read the local authenticator secret.
11. **Staging hostname and Access,** before Apache serves the site there:
    - Cloudflare DNS: a proxied A record `<staging-host>` → the new droplet's IP.
    - Zero Trust → Access controls → Applications → **Add an application → Self-hosted**: application name, the public hostname `<staging-host>` with **no path**, and a policy (Action **Allow**, Include → **Emails** → your address). Save the policy and attach it, then save the application; check it appears in the Applications list.
    - Check that `curl -I https://<staging-host>/` redirects to `<team>.cloudflareaccess.com`. The redirect's `kid` parameter is the application's AUD tag.
    - In `.env`: `admin.accessTeam = '<team>'`, `admin.accessAud = '<aud>'`, `admin.accessEmails = '<your email>'`.
12. **Vhost.** Keep the WordPress vhost as `zachschneider.conf.wordpress` (Apache loads only `*.conf`), and point `zachschneider.com`, `www` and `<staging-host>` at `/var/www/zkm-cms/public`, with the uploads block from *Security → Defence in depth*. Without `<staging-host>` in `ServerAlias`, Apache presents its default certificate and Cloudflare answers 526. `sudo apachectl configtest && sudo systemctl reload httpd`.
13. **Backups:** see *Security → Backups*.
14. **Test on staging.** Access blocks plain `curl`, so:
    - **URLs**, from the droplet, sending requests to its own Apache:
      ```bash
      scripts/check-urls.sh --save urls.txt https://www.zachschneider.com   # on your machine
      ssh <new-droplet> 'h=<staging-host>; while read -r p; do echo "$(curl -sk -L --resolve $h:443:127.0.0.1 -o /dev/null -w "%{http_code}" "https://$h$p") $p"; done' < urls.txt
      ```
    - **Security**, through Access with a service token: Zero Trust → Service credentials → create one, then add a policy to the app with Action **Service Auth**, Include → **Service Token**. Then:
      ```bash
      CF_ACCESS_CLIENT_ID=… CF_ACCESS_CLIENT_SECRET=… scripts/security-check.py https://<staging-host> --expect-admin-gated
      ```
      (In fish, `read -s -P 'Client ID: ' -x CF_ACCESS_CLIENT_ID` keeps the values out of your history.) The app answers `/admin` with 404 even to the service token, which carries no email.
    - **In the browser:** sign in with two-factor, edit a post and check the public page updates, and send a contact form message.

## Phase 3 — Cutover (about 30 minutes, at a quiet hour)

1. **Freeze WordPress:** don't publish from here on.
2. **Save the URL list** while the old sitemap still exists: `scripts/check-urls.sh --save urls.txt https://www.zachschneider.com`. If anything was published or edited in WordPress since the local export, bring it into zkm-cms first.
3. **Access for the live admin.** Add four destinations to the same Access application: `www.zachschneider.com` with paths `admin` and `admin/*`, and `zachschneider.com` with the same two. The AUD tag stays the same, so `.env` keeps its Access lines. `curl -I https://www.zachschneider.com/admin` should now redirect to `<team>.cloudflareaccess.com`; WordPress's own admin is `/wp-admin`, so visitors see no change.
4. **Point zkm-cms at www:** in `.env`, `app.baseURL = 'https://www.zachschneider.com/'`, then `sudo -u apache php spark cache:clear`. The cache clear matters: cached pages hold staging URLs in their canonical links, feed and sitemap.
5. **Switch:** `doctl compute reserved-ip-action assign <reserved-ip> <new-droplet-id>`, then `doctl compute reserved-ip get <reserved-ip>` to confirm.
6. **Purge Cloudflare:** Caching → Configuration → Purge Everything. The WordPress Cloudflare plugin used to do this for you.
7. **Verify:**
   - `curl -I https://www.zachschneider.com/wp-login.php` should return 410, and `/wp-content/uploads/…` should 301 to `/uploads/…`.
   - `curl -I https://zachschneider.com/` should 301 to `https://www.zachschneider.com/`. That's a Cloudflare rule; zkm-cms doesn't redirect the bare domain itself.
   - `scripts/check-urls.sh urls.txt https://www.zachschneider.com`: every path should work or answer 410 on purpose.
   - `scripts/security-check.py https://www.zachschneider.com --expect-admin-gated`, with the service token's variables set: every check should pass.
   - In the browser: sign in at `/admin`, edit a post, send a contact form message, read the feed, paste a post URL into Mastodon for the share card, and check GA4 Realtime and the GTM preview. The Content-Security-Policy allows Google's tag scripts; a Custom HTML tag in the GTM container that loads scripts from other domains would be blocked, so check the browser console.
   - In Search Console, submit `/sitemap.xml` in place of `/wp-sitemap.xml`.
8. **Stop WordPress background jobs** on the old droplet: `sudo systemctl disable --now wp-cron.timer`. Leave the WordPress files and database where they are.
9. **Watch it for a day:** `/var/log/httpd/*error_log`, `writable/logs/`, `fail2ban-client status sshd`, and the first nightly backup.

### Rollback (any time in the first two weeks)

1. `doctl compute reserved-ip-action assign <reserved-ip> <old-droplet-id>`.
2. Purge Cloudflare.
3. Re-enable `wp-cron.timer` on the old droplet.

The Access gate on `/admin` can stay. Anything published in zkm-cms after cutover would have to be re-entered in WordPress by hand.

## Phase 4 — Clean up (after about two weeks without trouble)

1. Snapshot the old droplet, then delete it and its DigitalOcean firewall. Drop the snapshot the new droplet was cloned from once you're comfortable.
2. Delete the `<staging-host>` DNS record and its Access destination, and its name in the vhost's `ServerAlias`, or keep it as a permanent staging site.
3. Delete the Access service token and its Service Auth policy once you no longer need to run the security check through Access.
4. Revoke the WP Mail SMTP token in Proton (zkm-cms has its own), and disconnect Site Kit, BlogVault and similar services on their side.
5. Take a new snapshot of the new droplet.

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
- **Logging:** production keeps warnings and info, so failed, throttled and successful sign-ins reach `writable/logs/`. The framework's default drops them.

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

- **Nightly, on the droplet:** a root systemd timer runs a script that dumps the database (`mysqldump --single-transaction zkm | gzip`) to `/var/backups/zkm/zkm-YYYY-MM-DD.sql.gz`, checks the file with `gzip -t`, and keeps 14 days.
- **Nightly, off the box:** a machine at home pulls the dumps and `public/uploads/` an hour later (`rsync --rsync-path='sudo rsync'`, since both are root/apache-only on the droplet). Without `--delete`, so it keeps every dump and every upload, including ones later removed from the server. A user timer with `Persistent=true` catches up after the machine was off; have it refuse to run when the backup disk isn't mounted.
- **Weekly:** DigitalOcean droplet backups (20% of the droplet price) for whole-machine restores.
- **Keep `.env` in your password manager,** especially `encryption.key`. Without it, the stored authenticator secrets can't be read; you'd run `user:totp` again.
- **Test a restore once,** into the local Docker stack: load the dump into `zkm` and copy the uploads into `public/uploads/`.

### What's left (accepted risks)

- **Contact form spam:** it's limited to 5 messages an hour per address, with a honeypot. If spam gets through, add Cloudflare Turnstile.
- **One admin, no recovery codes:** if you lose your phone, run `php spark user:totp` over SSH. SSH access is the recovery path, so keep it safe.
- **Third-party scripts:** the Google tag and Tag Manager run on public pages, so anyone who can edit the GTM container can run script on the site. Keep that Google account protected with two-factor.
- **Trust in Cloudflare:** Cloudflare sees all traffic and is trusted for visitor addresses and Access identities.

### Server setup

1. `.env` needs `encryption.key` from `php spark key:generate` **before** `php spark user:totp`. Back up the key: without it, the stored authenticator secrets can't be read, and you'd have to run `user:totp` again.
2. **No fail2ban jail for sign-ins.** This was planned before the Access gate. With Cloudflare Access in front of `/admin` and the droplet's ports 80 and 443 open only to Cloudflare, nobody but the allowed account can reach the sign-in form, so such a jail could only ever ban the site owner, while keeping a firewall-editing API token on the server. The app's own limits (5 tries a minute per address, 10 per 15 minutes per account) still apply. fail2ban keeps its `sshd` jail, which bans on the droplet itself; SSH doesn't go through Cloudflare.

   If you add one anyway (for example, without Access), these are the snags found when trying:
   - **SELinux:** `fail2ban_t` reads only log types, and it can't override file permissions. `writable/logs` is `httpd_sys_rw_content_t` under `750` directories owned by `zkm` and `apache`, so the jail fails with *Permission denied*, even though `fail2ban-regex` works from a root shell. Both the label and the permissions would have to change, and deploys must not undo them.
   - **Time zone:** the log uses the app's time zone (America/Chicago); a UTC server needs `datepattern = {NONE}`, because `logtimezone` takes only fixed offsets.
   - **Daily files:** fail2ban expands `logpath` globs only when the jail starts, so a new `log-YYYY-MM-DD.log` needs a daily `fail2ban-client reload zkm-cms`. Reload that jail alone: a failed `fail2ban-client reload` of everything leaves the other jails stopped.
   - **Cloudflare action:** `cloudflare-token` takes the zone **ID** (`cfzone=<32 hex characters>`), not the domain name, and needs a token with *Zone → Firewall Services → Edit*. If the token is limited by client IP, include the droplet's IPv6 address as well: it reaches the Cloudflare API over IPv6. `/user/tokens/verify` ignores that IP filter, so test with a zone request instead.
3. Cloudflare: turn on *Always Use HTTPS*. Consider a WAF rule or rate limit for `/admin/*` and `/contact-form`, or put `/admin` behind Cloudflare Access too.

## Things that intentionally change

- The footer says "Built on CodeIgniter". Edit or clear it in Settings.
- `/wp-admin`, `/wp-login.php`, `/xmlrpc.php` and `/wp-json` return **410 Gone**.
- Comments stay hidden, as before, and new comments aren't possible. There is no search page; the theme never had a search form.
- oEmbed discovery and pingbacks for the site's own posts are gone.
- Post dates use America/Chicago, so they observe daylight saving. WordPress was set to a fixed UTC−6, so summer-time timestamps move by an hour; the dates themselves don't change.
