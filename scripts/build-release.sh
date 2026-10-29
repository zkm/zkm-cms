#!/usr/bin/env bash
# Builds a deployable tarball of HEAD, for .github/workflows/deploy.yml.
#
#   scripts/build-release.sh <out.tar.gz>
#
# Same build as scripts/deploy.sh (git archive, composer install --no-dev, the admin
# build) and the same files left out, so the tarball is exactly what goes into
# /var/www/zkm-cms. Of writable/ and public/uploads/ only the uploads .htaccess is kept.
# A RELEASE file records the tag (or short hash) and the full commit.

out=${1:?usage: build-release.sh <out.tar.gz>}
repo=$(git -C "$(dirname "$0")" rev-parse --show-toplevel) || exit 1
case $out in /*) ;; *) out=$PWD/$out ;; esac

build=$(mktemp -d) || exit 1
trap 'rm -rf "$build"' EXIT

git -C "$repo" archive HEAD | tar -x -C "$build" || exit 1
composer install --working-dir="$build" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet || exit 1
(cd "$build/admin-ui" && npm ci --silent && npm run build --silent) || exit 1

mv "$build/public/uploads/.htaccess" "$build/uploads.htaccess" || exit 1
rm -rf "$build"/{admin-ui,tests,docker,docs,.github,writable,public/uploads} \
  "$build"/{docker-compose.yml,phpunit.dist.xml,env,CLAUDE.md} || exit 1
mkdir "$build/public/uploads" && mv "$build/uploads.htaccess" "$build/public/uploads/.htaccess" || exit 1

version=$(git -C "$repo" describe --tags --exact-match HEAD 2>/dev/null || git -C "$repo" rev-parse --short HEAD)
printf '%s %s\n' "$version" "$(git -C "$repo" rev-parse HEAD)" > "$build/RELEASE" || exit 1

[ -f "$build/public/admin-app/manifest.json" ] && [ -f "$build/vendor/autoload.php" ] || { echo "build is incomplete" >&2; exit 1; }
tar -czf "$out" --owner=0 --group=0 -C "$build" . || exit 1
echo "Built $version: $out ($(du -h "$out" | cut -f1))"
