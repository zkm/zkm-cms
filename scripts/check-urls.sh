#!/usr/bin/env bash
# Checks that every URL from the WordPress sitemap still works on the new site.
#
#   scripts/check-urls.sh https://www.zachschneider.com http://localhost:8090
#   scripts/check-urls.sh --save urls.txt https://www.zachschneider.com
#   scripts/check-urls.sh urls.txt https://www.zachschneider.com
#
# The first form reads wp-sitemap.xml from the old site and tests each path on the
# new one. --save writes the paths to a file instead, so they can be checked after
# cutover, when the old sitemap is gone. Redirects are followed. A 410 counts as
# removed on purpose (archived posts, empty archives) and is listed but not a failure.
# Prints each path that ends in anything else but a 200, and exits 1 if there were any.

sitemap_paths() {
  local site=$1 sitemap url
  for sitemap in $(curl -fsS -A 'zkm-cms url check' "$site/wp-sitemap.xml" | grep -oE '<loc>[^<]+' | sed 's/<loc>//'); do
    for url in $(curl -fsS -A 'zkm-cms url check' "$sitemap" | grep -oE '<loc>[^<]+' | sed 's/<loc>//'); do
      echo "${url#"$site"}"
    done
  done
}

if [ "$1" = "--save" ]; then
  out=${2:?usage: check-urls.sh --save <file> <old-site>}
  sitemap_paths "${3:?usage: check-urls.sh --save <file> <old-site>}" > "$out"
  echo "Saved $(wc -l < "$out") paths to $out"
  exit 0
fi

source=${1:?usage: check-urls.sh <old-site|paths-file> <new-site>}
new=${2:?usage: check-urls.sh <old-site|paths-file> <new-site>}

if [ -f "$source" ]; then
  paths=$(cat "$source")
else
  paths=$(sitemap_paths "$source")
fi

failed=0
count=0
gone=0

for path in $paths; do
  count=$((count + 1))
  code=$(curl -sL -o /dev/null -w '%{http_code}' "$new$path")
  if [ "$code" = 410 ]; then
    echo "gone (410) $path"
    gone=$((gone + 1))
  elif [ "$code" != 200 ]; then
    echo "$code $path"
    failed=1
  fi
done

[ "$failed" = 0 ] && echo "All $count paths are fine on $new: $((count - gone)) work, $gone removed on purpose (410)"
exit "$failed"
