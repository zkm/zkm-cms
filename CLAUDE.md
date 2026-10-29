# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

zkm-cms replaces the WordPress install behind zachschneider.com. The overriding constraint is that **no public URL may change**: permalinks, archives, feeds, and old `/wp-content/uploads/` paths all have to keep resolving (or redirect). `docs/MIGRATION.md` has the cutover plan, security posture, and the list of things that intentionally differ from WordPress.

Stack: PHP 8.3 / CodeIgniter 4.7, MariaDB 10.11 (the schema uses ENUM and JSON columns), Valkey cache, and a Vue 3 + Vite admin app.

## Commands

Everything PHP runs inside the Docker stack (`docker compose up -d`; site at :8090, Mailpit at :8025). The app container runs as `www-data`.

```bash
docker compose exec app php spark migrate
docker compose exec app vendor/bin/phpunit --no-coverage                         # all tests (zkm_tests DB)
docker compose exec app vendor/bin/phpunit --no-coverage tests/unit/TotpTest.php  # one file
docker compose exec app vendor/bin/phpunit --no-coverage --filter testName        # one test

docker compose exec app php spark wp:load backups/site.wpress   # loads into wp_import* only, dropping its tables
docker compose exec app php spark wp:import                     # read-only against WordPress; safe to rerun
docker compose exec app php spark user:create <email> "Name"    # also resets a password
docker compose exec app php spark user:totp <email>
docker compose exec app php spark user:email <old> <new>

cd admin-ui && npm run build    # -> public/admin-app/ (gitignored); npm run dev rebuilds on save
ADMIN_EMAIL=… ADMIN_PASSWORD=… npm run test:smoke [-- http://localhost:8090]   # Playwright, in admin-ui/

scripts/security-check.py http://localhost:8090 --uploads-dir public/uploads --email … --password …
scripts/check-urls.sh https://www.zachschneider.com http://localhost:8090      # every live sitemap URL

scripts/deploy.sh <user@host>         # builds HEAD (no dev deps) and shows what rsync would change
scripts/deploy.sh <user@host> --go    # deploys; never touches .env, writable/ or public/uploads/
```

Always pass `--no-coverage`: `phpunit.dist.xml` asks for coverage reports and there's no coverage driver. `security-check.py --uploads-dir` plants probe files, so use it only on local/CI copies. CI (`.github/workflows/tests.yml`) runs PHPUnit + `composer audit`, the admin build + `npm audit`, and the security check and smoke test against the real Docker stack.

## Architecture

**Public site** — `app/Controllers/Site.php`. Besides a few fixed routes (home, feed, sitemap, robots, contact form), everything falls through `(:any)` to `Site::resolve()`, which in order: checks the `redirects` table (a `410` row returns Gone), strips `/page/N/`, handles `/category/…` and `/tag/…` archives (410 when a term has no published posts), sends `/author/…` to the blog page, then matches the last segment as a post/page slug. `canonical()` 301s any non-canonical path (missing slash, wrong category) to the URL from `App\Libraries\Permalinks`, which reproduces WordPress's `/%category%/%postname%/` structure (pages nest under parent pages; the front page is `/`).

**No sessions for visitors.** Public pages must never start a session, so that Valkey and Cloudflare can cache them. Use the `visitor_session()` helper (`app/Helpers/site_helper.php`), which reads the session only if the cookie already exists, never `session()` directly in public code paths. Drafts/private posts are shown only to a valid admin session.

**Page cache** — `App\Libraries\SiteCache` keys every entry with a generation number; `flush()` bumps the generation. Anonymous, query-string-free requests go through `Site::cached()`. Any admin API action that changes public output must call `$this->changed()` (from `ApiController`) or the site will serve stale pages.

**Content rendering** — posts have `format = html` (imported WordPress block HTML, cleaned by `Libraries/WordPress/ContentConverter`) or `format = markdown` (new posts, GFM via league/commonmark, raw HTML allowed). `ContentRenderer` handles both, plus excerpts and descriptions. `template = contact` renders the built-in contact form.

**Admin** — `/admin/login` is server-rendered (`Admin\Auth`); everything else under `/admin/` is the Vue SPA shell (`Admin\Spa`, which reads `public/admin-app/manifest.json` to find the hashed bundle). The JSON API lives in `app/Controllers/Admin/Api/`, all extending `ApiController` (`input()`, `ok()`, `error()`, `changed()`), all behind the `adminauth` filter and CSRF. New API routes go in the `admin/api` group in `app/Config/Routes.php`. The Vue client (`admin-ui/src/api.js`) sends the CSRF token from the page's meta tags and redirects to sign-in on a 401.

**Auth** — `App\Filters\AdminAuth::currentUser()` accepts a session only if the user exists, its `session_version` matches (bumped on password/email change or "sign out everywhere"), and it's under `Config\Admin::$maxSessionAge`. TOTP codes are checked by `Libraries/Totp`; `Admin\Auth` rejects code reuse by storing `totp_last_step` on the user. `AdminGate` + `CloudflareAccess` optionally restrict who can reach `/admin` at all.

**WordPress import** — `Libraries/WordPress/`: `WpressArchive` reads All-in-One WP Migration `.wpress` backups, `Importer` copies content from the `wordpress` DB group (keyed by `wp_id`), and `ContentConverter` cleans block markup. On re-import, posts edited in zkm-cms (they have a revision) are kept unless `--overwrite-edited` is passed. `_wp_old_slug` becomes redirects; renaming a published post adds a redirect automatically.

**Databases** (`docker/db/init.sql`): `zkm` (app), `zkm_tests` (PHPUnit), `wp_import` (loaded WordPress backup). Config comes from env vars like `database_default_hostname` (see `docker-compose.yml`, `env.production.example`).

**Theme** — `app/Views/layouts/site.php` and `app/Views/site/*` are ported from zkm-wp-theme; its assets are in `public/theme/` (`blocks.css` carries the WordPress core block styles imported HTML still relies on).
