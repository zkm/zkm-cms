# zkm-cms

A small CMS for zachschneider.com, built to replace WordPress without changing a single public URL.

- **Public site:** CodeIgniter 4 renders server-side HTML using the zkm-wp-theme design (`public/theme/`). Pages are cached in Valkey and never start a session, so Cloudflare can cache them too.
- **Admin:** a Vue 3 app at `/admin/` (source in `admin-ui/`) on top of a JSON API. It covers posts, pages, media, categories and tags, menus, redirects, contact messages and settings.
- **Import:** `php spark wp:import` copies posts, pages, media, menus and settings from a WordPress database. It only reads from WordPress and is safe to run again.

The migration plan for the live site is in [docs/MIGRATION.md](docs/MIGRATION.md).

## Local setup

You need Docker, plus Node 20+ for the admin app.

```bash
composer install
docker compose up -d                                   # site :8090, Mailpit :8025
docker compose exec app php spark migrate

# Load a WordPress backup and import it
mkdir -p backups && cp ~/Downloads/site.wpress backups/
docker compose exec app php spark wp:load backups/site.wpress
docker compose exec app php spark wp:import

# An admin user, then optional two-factor sign-in
docker compose exec app php spark user:create you@example.com "Your Name"
docker compose exec app php spark user:totp you@example.com

# The admin app
cd admin-ui && npm install && npm run build            # or npm run dev to rebuild on save
```

Then open http://localhost:8090 for the site and http://localhost:8090/admin/ for the admin. Contact form emails show up in Mailpit at http://localhost:8025.

`wp:load` only writes to a database named `wp_import*`, and it drops the tables it loads. `wp:import` reads the `wordpress` database group, which can point at a live WordPress database through a read-only user.

## How the content works

| WordPress | zkm-cms |
| --- | --- |
| Block-editor HTML | Stored as HTML (`format = html`). Block comments are removed, YouTube and Vimeo embeds become iframes, and quotes are curled the way `wptexturize` did. |
| New posts | Markdown (`format = markdown`, GitHub-flavoured). Raw HTML is allowed. |
| `/%category%/%postname%/` | Same URLs. The category with the lowest WordPress term ID goes in the URL, as WordPress chose it. |
| `/wp-content/uploads/…` | Files move to `/uploads/…` with the same paths, and the old URLs get a 301. |
| `?p=123`, `/feed/`, `/author/…`, `/wp-sitemap.xml` | Redirected to the new equivalents. |
| `_wp_old_slug` | Imported as redirects. Renaming a published post adds a redirect automatically. |
| Fluent Forms contact form | A built-in form (`template = contact`). Messages are stored and emailed. It has a honeypot and a rate limit of 5 per hour per IP. |
| Comments | Imported into `comments` as an archive. They aren't shown on the site, which matches the old theme. |
| Private, draft and pending posts | Kept, and visible only when signed in. |

## Tests

```bash
docker compose exec app vendor/bin/phpunit
scripts/check-urls.sh https://www.zachschneider.com http://localhost:8090
```

The PHPUnit suite uses the `zkm_tests` database. `check-urls.sh` reads every URL from the live WordPress sitemap and checks that each one still works on the new site.

## Layout

```
app/Commands/            spark commands: wp:load, wp:import, user:create, user:totp
app/Controllers/Site.php the public site (routing by permalink, feed, sitemap, contact form)
app/Controllers/Admin/   sign-in, the admin app shell and the JSON API
app/Libraries/WordPress/ .wpress reader, content converter, importer
app/Views/               site layout and templates (ported from zkm-wp-theme)
admin-ui/                Vue 3 admin (Vite) -> public/admin-app/
public/theme/            theme CSS/JS/images and blocks.css (core block styles)
docker/                  local stack mirroring the Rocky 10 droplet
```
