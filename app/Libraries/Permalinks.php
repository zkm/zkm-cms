<?php

namespace App\Libraries;

use App\Models\PostModel;
use App\Models\SettingModel;
use App\Models\TermModel;

/**
 * Builds and resolves URLs in the same shape as the WordPress site
 * (permalink structure /%category%/%postname%/), so existing links keep working:
 *
 *   post      /{category}/{child-category}/{slug}/
 *   page      /{parent-page}/{slug}/   (the front page is /)
 *   category  /category/{slug}/
 *   tag       /tag/{slug}/
 */
class Permalinks
{
    /** @var array<int, array<string, mixed>> */
    private array $terms = [];

    /** @var array<int, array<string, mixed>> */
    private array $pages = [];

    public function post(array $post): string
    {
        if ($post['type'] === 'page') {
            if ((string) $post['id'] === model(SettingModel::class)->value('front_page_id')) {
                return '/';
            }

            return '/' . implode('/', $this->pagePath($post)) . '/';
        }

        $category = $post['category_id'] ? $this->term((int) $post['category_id']) : null;
        $prefix   = $category ? implode('/', $this->termPath($category)) : 'uncategorized';

        return '/' . $prefix . '/' . $post['slug'] . '/';
    }

    public function term(int $id): ?array
    {
        if (! array_key_exists($id, $this->terms)) {
            $this->terms[$id] = model(TermModel::class)->find($id);
        }

        return $this->terms[$id];
    }

    public function termUrl(array $term): string
    {
        return '/' . $term['taxonomy'] . '/' . implode('/', $this->termPath($term)) . '/';
    }

    /**
     * Slugs from the top-level category down to this one.
     *
     * @return list<string>
     */
    public function termPath(array $term): array
    {
        $path = [$term['slug']];

        for ($parent = $term['parent_id'], $depth = 0; $parent && $depth < 10; $depth++) {
            $row = $this->term((int) $parent);

            if ($row === null) {
                break;
            }

            array_unshift($path, $row['slug']);
            $parent = $row['parent_id'];
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function pagePath(array $page): array
    {
        $path = [$page['slug']];

        for ($parent = $page['parent_id'], $depth = 0; $parent && $depth < 10; $depth++) {
            if (! array_key_exists((int) $parent, $this->pages)) {
                $this->pages[(int) $parent] = model(PostModel::class)->find($parent);
            }

            $row = $this->pages[(int) $parent];

            if ($row === null) {
                break;
            }

            array_unshift($path, $row['slug']);
            $parent = $row['parent_id'];
        }

        return $path;
    }
}
