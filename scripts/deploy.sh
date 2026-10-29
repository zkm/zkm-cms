#!/usr/bin/env bash
# Builds the committed code and rsyncs it to the server.
#
#   scripts/deploy.sh <user@host>          # dry run: lists what would change
#   scripts/deploy.sh <user@host> --go     # deploys
#
# The build is a clean copy of HEAD (git archive), so uncommitted changes are never
# deployed. It runs composer install --no-dev and the admin build, then rsyncs to
# /var/www/zkm-cms as zkm:apache (files 640, directories 750), using sudo on the server.
# .env, writable/ and public/uploads/ are never sent or deleted; set them up by hand
# on the first deploy (docs/MIGRATION.md, Phase 2). After a real deploy, it runs
# pending migrations and clears the page cache, if .env exists.

host=${1:?usage: deploy.sh <user@host> [--go]}
target=/var/www/zkm-cms
repo=$(git -C "$(dirname "$0")" rev-parse --show-toplevel) || exit 1

if [ "$2" = "--go" ]; then
  dry=()
else
  dry=(--dry-run)
fi

if [ -n "$(git -C "$repo" status --porcelain)" ]; then
  echo "Note: uncommitted changes are not deployed; building $(git -C "$repo" rev-parse --short HEAD)."
fi

build=$(mktemp -d) || exit 1
trap 'rm -rf "$build"' EXIT

echo "Building $(git -C "$repo" log -1 --format='%h %s') in $build"
git -C "$repo" archive HEAD | tar -x -C "$build" || exit 1
composer install --working-dir="$build" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet || exit 1
(cd "$build/admin-ui" && npm ci --silent && npm run build --silent) || exit 1

# Leading slashes anchor each pattern at the project root. Excluded paths are also
# protected from --delete on the server.
perms=(--rsync-path='sudo rsync' --chown=zkm:apache --chmod=Du=rwx,Dg=rx,Do=,Fu=rw,Fg=r,Fo=)
rsync -rlptzi --delete "${dry[@]}" "${perms[@]}" \
  --exclude=/.env --exclude=/writable/ --exclude=/public/uploads/ \
  --exclude=/admin-ui/ --exclude=/tests/ --exclude=/docker/ --exclude=/docs/ --exclude=/.github/ \
  --exclude=/docker-compose.yml --exclude=/phpunit.dist.xml --exclude=/env --exclude=/CLAUDE.md \
  "$build/" "$host:$target/" || exit 1

# The uploads .htaccess (no PHP, no HTML) on its own, so the uploads directory keeps
# the permissions Apache needs to write to it.
rsync -ptzi "${dry[@]}" "${perms[@]}" "$build/public/uploads/.htaccess" "$host:$target/public/uploads/.htaccess" || exit 1

if [ ${#dry[@]} -gt 0 ]; then
  echo "Dry run only. Run again with --go to deploy."
  exit 0
fi

ssh "$host" "cd $target && if [ -f .env ]; then sudo -u apache php spark migrate --all && sudo -u apache php spark cache:clear; else echo 'No .env yet: skipped migrations and cache clear.'; fi"
