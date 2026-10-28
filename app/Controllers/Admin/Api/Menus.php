<?php

namespace App\Controllers\Admin\Api;

use App\Libraries\Permalinks;
use App\Models\MenuItemModel;
use App\Models\PostModel;

class Menus extends ApiController
{
    public const LOCATIONS = ['primary' => 'Header', 'social' => 'Social links (footer)'];

    public function index()
    {
        $menus = [];

        foreach (self::LOCATIONS as $location => $label) {
            $menus[$location] = ['label' => $label, 'items' => model(MenuItemModel::class)->where('location', $location)->orderBy('sort')->findAll()];
        }

        $links = new Permalinks();
        $pages = model(PostModel::class)->select('id, type, title, slug, parent_id, category_id')->where('status', 'published')
            ->orderBy('type', 'DESC')->orderBy('title')->findAll();

        return $this->ok([
            'menus'   => $menus,
            'targets' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'type' => $p['type'], 'title' => $p['title'], 'url' => $links->post($p)], $pages),
        ]);
    }

    public function save(string $location)
    {
        if (! isset(self::LOCATIONS[$location])) {
            return $this->error('Unknown menu', 404);
        }

        $items = $this->input()['items'] ?? [];
        $model = model(MenuItemModel::class);
        $rows  = [];

        foreach (array_values($items) as $i => $item) {
            $label  = trim((string) ($item['label'] ?? ''));
            $postId = ! empty($item['post_id']) ? (int) $item['post_id'] : null;
            $url    = $postId ? null : trim((string) ($item['url'] ?? ''));

            if ($postId === null && ! preg_match('#^(https?://|/|mailto:)#', (string) $url)) {
                return $this->error('Link ' . ($i + 1) . ' needs a page or a URL starting with https://, / or mailto:.');
            }

            $rows[] = ['location' => $location, 'label' => $label, 'url' => $url, 'post_id' => $postId, 'sort' => $i + 1];
        }

        $model->db->transStart();
        $model->where('location', $location)->delete();

        if ($rows !== []) {
            $model->insertBatch($rows);
        }

        $model->db->transComplete();
        $this->changed();

        return $this->index();
    }
}
