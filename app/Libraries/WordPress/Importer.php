<?php

namespace App\Libraries\WordPress;

use App\Libraries\Permalinks;
use App\Libraries\SiteCache;
use App\Models\CommentModel;
use App\Models\MediaModel;
use App\Models\MenuItemModel;
use App\Models\PostModel;
use App\Models\RedirectModel;
use App\Models\SettingModel;
use App\Models\TermModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Copies a WordPress site into zkm-cms: categories and tags, media, posts and pages
 * (with status, dates, featured images and categories), comments, menus, the main
 * settings, and redirects for old slugs.
 *
 * It only reads from WordPress. Rows are matched on their WordPress ID, so running it
 * again updates what it imported before; posts created in zkm-cms are left alone.
 */
class Importer
{
    private const STATUSES = ['publish' => 'published', 'future' => 'published', 'private' => 'private', 'draft' => 'draft', 'pending' => 'pending'];

    private BaseConnection $wp;
    private ContentConverter $converter;
    private string $siteUrl;

    /** @var array<int, int> WordPress term_id => terms.id */
    private array $termIds = [];

    /** @var array<int, int> WordPress attachment ID => media.id */
    private array $mediaIds = [];

    /** @var array<int, int> WordPress post ID => posts.id */
    private array $postIds = [];

    /** @var array<string, int> */
    public array $counts = [];

    /** @var callable(string): void */
    private $log;

    public function __construct(?BaseConnection $wordpress = null, ?callable $log = null)
    {
        $this->wp  = $wordpress ?? Database::connect('wordpress');
        $this->log = $log ?? static function (string $line): void {};

        $this->siteUrl   = rtrim($this->option('home') ?? '', '/');
        $this->converter = new ContentConverter($this->siteUrl);
    }

    public function siteUrl(): string
    {
        return $this->siteUrl;
    }

    public function run(?string $uploadsDir = null): array
    {
        if ($uploadsDir !== null) {
            $this->copyUploads($uploadsDir, FCPATH . 'uploads');
        }

        $this->importTerms();
        $this->importMedia();
        $this->importPosts();
        $this->importComments();
        $this->importMenus();
        $this->importSettings();
        $this->importOldSlugRedirects();

        SiteCache::flush();

        return $this->counts;
    }

    private function importTerms(): void
    {
        $rows = $this->wp->table('term_taxonomy tt')
            ->select('t.term_id, t.name, t.slug, tt.taxonomy, tt.description, tt.parent')
            ->join('terms t', 't.term_id = tt.term_id')
            ->whereIn('tt.taxonomy', ['category', 'post_tag'])
            ->get()->getResultArray();

        $terms = model(TermModel::class);

        foreach ($rows as $row) {
            $taxonomy = $row['taxonomy'] === 'post_tag' ? 'tag' : 'category';
            $data     = [
                'taxonomy'    => $taxonomy,
                'name'        => html_entity_decode($row['name'], ENT_QUOTES, 'UTF-8'),
                'slug'        => rawurldecode($row['slug']),
                'description' => $row['description'],
                'wp_id'       => (int) $row['term_id'],
            ];

            $this->termIds[(int) $row['term_id']] = $this->upsert($terms, $data, ['taxonomy' => $taxonomy, 'slug' => $data['slug']]);
        }

        foreach ($rows as $row) {
            if ((int) $row['parent'] > 0 && isset($this->termIds[(int) $row['parent']])) {
                $terms->update($this->termIds[(int) $row['term_id']], ['parent_id' => $this->termIds[(int) $row['parent']]]);
            }
        }

        $this->counts['terms'] = count($rows);
        ($this->log)('Terms: ' . count($rows));
    }

    private function importMedia(): void
    {
        $rows  = $this->wp->table('posts')->where('post_type', 'attachment')->get()->getResultArray();
        $meta  = $this->postMeta(array_column($rows, 'ID'), ['_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt']);
        $media = model(MediaModel::class);

        foreach ($rows as $row) {
            $id   = (int) $row['ID'];
            $file = $meta[$id]['_wp_attached_file'] ?? null;

            if ($file === null || $file === '') {
                continue;
            }

            $info  = $this->unserialize($meta[$id]['_wp_attachment_metadata'] ?? '');
            $dir   = str_contains($file, '/') ? dirname($file) . '/' : '';
            $sizes = [];

            foreach (($info['sizes'] ?? []) as $name => $size) {
                if (isset($size['file'])) {
                    $sizes[$name] = ['path' => $dir . $size['file'], 'width' => (int) ($size['width'] ?? 0), 'height' => (int) ($size['height'] ?? 0)];
                }
            }

            $local = FCPATH . 'uploads/' . $file;

            $this->mediaIds[$id] = $this->upsert($media, [
                'path'    => $file,
                'mime'    => $row['post_mime_type'],
                'width'   => isset($info['width']) ? (int) $info['width'] : null,
                'height'  => isset($info['height']) ? (int) $info['height'] : null,
                'bytes'   => is_file($local) ? filesize($local) : ($info['filesize'] ?? null),
                'alt'     => (string) ($meta[$id]['_wp_attachment_image_alt'] ?? ''),
                'caption' => $row['post_excerpt'] !== '' ? $row['post_excerpt'] : null,
                'sizes'   => json_encode($sizes, JSON_UNESCAPED_SLASHES),
                'wp_id'   => $id,
            ], ['path' => $file]);
        }

        $this->counts['media'] = count($this->mediaIds);
        ($this->log)('Media: ' . count($this->mediaIds));
    }

    private function importPosts(): void
    {
        $rows = $this->wp->table('posts')
            ->whereIn('post_type', ['post', 'page'])
            ->whereIn('post_status', array_keys(self::STATUSES))
            ->orderBy('ID')
            ->get()->getResultArray();

        $ids   = array_column($rows, 'ID');
        $meta  = $this->postMeta($ids, ['_thumbnail_id']);
        $terms = $this->postTerms($ids);
        $posts = model(PostModel::class);

        foreach ($rows as $row) {
            $id        = (int) $row['ID'];
            $converted = $this->converter->convert($row['post_content']);
            $postTerms = $terms[$id] ?? [];

            // WordPress puts the category with the lowest term ID in the URL.
            $categories = array_keys(array_filter($postTerms, static fn ($taxonomy) => $taxonomy === 'category'));
            sort($categories);
            $categoryId = $categories !== [] ? ($this->termIds[$categories[0]] ?? null) : null;

            $thumbnail = (int) ($meta[$id]['_thumbnail_id'] ?? 0);
            $slug      = rawurldecode($row['post_name']) ?: ($row['post_type'] === 'page' ? 'page-' . $id : 'post-' . $id);

            $this->postIds[$id] = $this->upsert($posts, [
                'type'              => $row['post_type'],
                'status'            => self::STATUSES[$row['post_status']],
                'title'             => ContentConverter::texturizeText(html_entity_decode($row['post_title'], ENT_QUOTES, 'UTF-8')),
                'slug'              => $slug,
                'format'            => 'html',
                'body'              => $converted['html'],
                'excerpt'           => trim($row['post_excerpt']) !== '' ? trim($row['post_excerpt']) : null,
                'template'          => $converted['contact_form'] ? 'contact' : null,
                'featured_media_id' => $this->mediaIds[$thumbnail] ?? null,
                'category_id'       => $categoryId,
                'menu_order'        => (int) $row['menu_order'],
                'published_at'      => $this->localTime($row['post_date_gmt'], $row['post_date']),
                'created_at'        => $this->localTime($row['post_date_gmt'], $row['post_date']),
                'updated_at'        => $this->localTime($row['post_modified_gmt'], $row['post_modified']),
                'wp_id'             => $id,
            ]);

            $posts->syncTerms($this->postIds[$id], array_values(array_filter(array_map(fn ($termId) => $this->termIds[$termId] ?? null, array_keys($postTerms)))));
        }

        foreach ($rows as $row) {
            if ((int) $row['post_parent'] > 0 && isset($this->postIds[(int) $row['post_parent']])) {
                $posts->update($this->postIds[(int) $row['ID']], ['parent_id' => $this->postIds[(int) $row['post_parent']]]);
            }
        }

        $byStatus = array_count_values(array_map(static fn ($r) => $r['post_type'] . ' ' . self::STATUSES[$r['post_status']], $rows));
        ksort($byStatus);
        $this->counts += $byStatus;
        ($this->log)('Posts and pages: ' . count($rows) . ' (' . http_build_query($byStatus, '', ', ') . ')');
    }

    private function importComments(): void
    {
        if ($this->postIds === []) {
            return;
        }

        $rows = $this->wp->table('comments')
            ->whereIn('comment_post_ID', array_keys($this->postIds))
            ->whereIn('comment_approved', ['0', '1'])
            ->orderBy('comment_ID')
            ->get()->getResultArray();

        $comments = model(CommentModel::class);
        $ids      = [];

        foreach ($rows as $row) {
            $ids[(int) $row['comment_ID']] = $this->upsert($comments, [
                'post_id'      => $this->postIds[(int) $row['comment_post_ID']],
                'author'       => $row['comment_author'],
                'author_email' => $row['comment_author_email'] ?: null,
                'author_url'   => $row['comment_author_url'] ?: null,
                'body'         => $row['comment_content'],
                'type'         => $row['comment_type'] ?: 'comment',
                'approved'     => (int) $row['comment_approved'],
                'created_at'   => $this->localTime($row['comment_date_gmt'], $row['comment_date']),
                'wp_id'        => (int) $row['comment_ID'],
            ]);
        }

        foreach ($rows as $row) {
            if ((int) $row['comment_parent'] > 0 && isset($ids[(int) $row['comment_parent']])) {
                $comments->update($ids[(int) $row['comment_ID']], ['parent_id' => $ids[(int) $row['comment_parent']]]);
            }
        }

        $this->counts['comments'] = count($rows);
        ($this->log)('Comments (archived, not shown): ' . count($rows));
    }

    private function importMenus(): void
    {
        $mods      = $this->unserialize($this->option('theme_mods_' . $this->option('stylesheet')) ?? '');
        $locations = array_filter($mods['nav_menu_locations'] ?? []);
        $menuItems = model(MenuItemModel::class);
        $total     = 0;

        foreach ($locations as $location => $menuTermId) {
            $items = $this->wp->table('posts p')
                ->select('p.ID, p.post_title, p.menu_order')
                ->join('term_relationships tr', 'tr.object_id = p.ID')
                ->join('term_taxonomy tt', 'tt.term_taxonomy_id = tr.term_taxonomy_id')
                ->where('tt.term_id', (int) $menuTermId)
                ->where('p.post_type', 'nav_menu_item')
                ->where('p.post_status', 'publish')
                ->orderBy('p.menu_order')
                ->get()->getResultArray();

            $meta = $this->postMeta(array_column($items, 'ID'), ['_menu_item_type', '_menu_item_object_id', '_menu_item_url', '_menu_item_menu_item_parent']);

            $menuItems->where('location', $location)->delete();

            foreach ($items as $item) {
                $m      = $meta[(int) $item['ID']] ?? [];
                $postId = null;
                $url    = null;
                $label  = $item['post_title'];

                if (($m['_menu_item_type'] ?? '') === 'post_type') {
                    $postId = $this->postIds[(int) ($m['_menu_item_object_id'] ?? 0)] ?? null;

                    if ($postId === null) {
                        continue;
                    }

                    if ($label === '') {
                        $label = model(PostModel::class)->find($postId)['title'];
                    }
                } else {
                    $url = $this->converter->localUrl((string) ($m['_menu_item_url'] ?? ''));
                }

                $menuItems->insert(['location' => $location, 'label' => $label, 'url' => $url, 'post_id' => $postId, 'sort' => (int) $item['menu_order']]);
                $total++;
            }
        }

        $this->counts['menu items'] = $total;
        ($this->log)('Menu items: ' . $total . ' (' . implode(', ', array_keys($locations)) . ')');
    }

    private function importSettings(): void
    {
        $settings = model(SettingModel::class);

        $settings->put('site_name', html_entity_decode((string) $this->option('blogname'), ENT_QUOTES, 'UTF-8'));
        $settings->put('tagline', html_entity_decode((string) $this->option('blogdescription'), ENT_QUOTES, 'UTF-8'));
        $settings->put('posts_per_page', (string) ((int) $this->option('posts_per_page') ?: 10));

        if ($this->option('show_on_front') === 'page') {
            $settings->put('front_page_id', (string) ($this->postIds[(int) $this->option('page_on_front')] ?? ''));
            $settings->put('posts_page_id', (string) ($this->postIds[(int) $this->option('page_for_posts')] ?? ''));
        }

        $settings->put('site_icon_id', (string) ($this->mediaIds[(int) $this->option('site_icon')] ?? ''));

        // The Google tag (Analytics) Site Kit placed, if it was set up.
        $analytics = $this->unserialize($this->option('googlesitekit_analytics-4_settings') ?? '');

        if (! empty($analytics['useSnippet']) && ! empty($analytics['googleTagID'] ?? $analytics['measurementID'] ?? '')) {
            $settings->put('google_tag_id', $analytics['googleTagID'] ?: $analytics['measurementID']);
        }

        // Google Tag Manager container from Site Kit, if it was set up.
        $tagManager = $this->unserialize($this->option('googlesitekit_tagmanager_settings') ?? '');

        if (! empty($tagManager['containerID'])) {
            $settings->put('gtm_id', $tagManager['containerID']);
        }

        ($this->log)('Settings: site name, tagline, front page, posts page' . (! empty($tagManager['containerID']) ? ', Tag Manager ' . $tagManager['containerID'] : ''));
    }

    /**
     * WordPress kept serving a post at its old slug after a rename; keep that working.
     */
    private function importOldSlugRedirects(): void
    {
        $rows = $this->wp->table('postmeta')
            ->where('meta_key', '_wp_old_slug')
            ->whereIn('post_id', array_keys($this->postIds) ?: [0])
            ->get()->getResultArray();

        $links     = new Permalinks();
        $posts     = model(PostModel::class);
        $redirects = model(RedirectModel::class);
        $count     = 0;

        foreach ($rows as $row) {
            $post = $posts->find($this->postIds[(int) $row['post_id']]);

            if ($post === null || $post['status'] !== 'published' || $row['meta_value'] === $post['slug']) {
                continue;
            }

            $target = $links->post($post);

            if ($target === '/') {
                continue;
            }
            $from   = substr($target, 0, -strlen($post['slug']) - 1) . rawurldecode($row['meta_value']) . '/';

            $this->upsert($redirects, ['from_path' => $from, 'to_path' => $target, 'code' => 301], ['from_path' => $from]);
            $count++;
        }

        $this->counts['old-slug redirects'] = $count;
        ($this->log)('Old-slug redirects: ' . $count);
    }

    /**
     * Copy the uploads folder, skipping code, hidden files and plugin caches.
     */
    public function copyUploads(string $from, string $to): void
    {
        $from = rtrim($from, '/');

        if (! is_dir($from)) {
            throw new RuntimeException("Uploads folder not found: {$from}");
        }

        $skipDirs = ['cache', 'js_cache', 'gravity_forms', 'fluentform', 'wpforms', 'wp-file-manager-pro', 'ai1wm-backups', 'breeze'];
        $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'pdf', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'm4v', 'mov', 'webm', 'zip', 'txt', 'csv', 'swf', 'flv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'key'];
        $copied   = 0;
        $skipped  = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f) => ! str_starts_with($f->getFilename(), '.') && ! ($f->isDir() && in_array($f->getFilename(), $skipDirs, true))
            )
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || $file->isLink() || ! in_array(strtolower($file->getExtension()), $allowed, true)) {
                $skipped++;

                continue;
            }

            $target = $to . substr($file->getPathname(), strlen($from));

            if (is_file($target) && filesize($target) === $file->getSize()) {
                continue;
            }

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }

            copy($file->getPathname(), $target);
            $copied++;
        }

        $this->counts['files copied'] = $copied;
        ($this->log)("Uploads: {$copied} files copied, {$skipped} skipped");
    }

    /**
     * Insert, or update the row that has the same wp_id (or, failing that, the same natural key).
     */
    private function upsert($model, array $data, array $naturalKey = []): int
    {
        $existing = isset($data['wp_id']) ? $model->where('wp_id', $data['wp_id'])->first() : null;

        if ($existing === null && $naturalKey !== []) {
            $existing = $model->where($naturalKey)->first();
        }

        if ($existing !== null) {
            $model->update($existing['id'], $data);

            return (int) $existing['id'];
        }

        return (int) $model->insert($data);
    }

    private function option(string $name): ?string
    {
        $row = $this->wp->table('options')->select('option_value')->where('option_name', $name)->get()->getRowArray();

        return $row['option_value'] ?? null;
    }

    /**
     * @param list<int|string> $ids
     * @param list<string>     $keys
     *
     * @return array<int, array<string, string>>
     */
    private function postMeta(array $ids, array $keys): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->wp->table('postmeta')->whereIn('post_id', $chunk)->whereIn('meta_key', $keys)->get()->getResultArray();

            foreach ($rows as $row) {
                $out[(int) $row['post_id']][$row['meta_key']] = $row['meta_value'];
            }
        }

        return $out;
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array<int, array<int, string>> post ID => [term_id => taxonomy]
     */
    private function postTerms(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->wp->table('term_relationships tr')
            ->select('tr.object_id, tt.term_id, tt.taxonomy')
            ->join('term_taxonomy tt', 'tt.term_taxonomy_id = tr.term_taxonomy_id')
            ->whereIn('tr.object_id', $ids)
            ->whereIn('tt.taxonomy', ['category', 'post_tag'])
            ->get()->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['object_id']][(int) $row['term_id']] = $row['taxonomy'];
        }

        return $out;
    }

    /**
     * WordPress stores each date twice; prefer the UTC copy and convert it to the app timezone.
     */
    private function localTime(?string $gmt, ?string $local): ?string
    {
        if ($gmt !== null && ! str_starts_with($gmt, '0000')) {
            return (new \DateTimeImmutable($gmt, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(config('App')->appTimezone))
                ->format('Y-m-d H:i:s');
        }

        return ($local !== null && ! str_starts_with($local, '0000')) ? $local : null;
    }

    private function unserialize(string $value): array
    {
        if ($value === '' || ! is_string($value)) {
            return [];
        }

        $data = @unserialize($value, ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
    }
}
