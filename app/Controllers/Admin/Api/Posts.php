<?php

namespace App\Controllers\Admin\Api;

use App\Libraries\ContentRenderer;
use App\Libraries\Permalinks;
use App\Models\MediaModel;
use App\Models\PostModel;
use App\Models\RedirectModel;
use App\Models\RevisionModel;
use App\Models\TermModel;

class Posts extends ApiController
{
    private const STATUSES = ['published', 'draft', 'pending', 'private'];

    public function index()
    {
        $type    = $this->request->getGet('type') === 'page' ? 'page' : 'post';
        $status  = (string) $this->request->getGet('status');
        $search  = trim((string) $this->request->getGet('q'));
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPage = 25;

        $model = model(PostModel::class)
            ->select('posts.id, posts.type, posts.status, posts.title, posts.slug, posts.category_id, posts.parent_id, posts.published_at, posts.updated_at, posts.format, terms.name AS category')
            ->join('terms', 'terms.id = posts.category_id', 'left')
            ->where('posts.type', $type);

        if (in_array($status, self::STATUSES, true)) {
            $model->where('posts.status', $status);
        }

        if ($search !== '') {
            $model->groupStart()->like('posts.title', $search)->orLike('posts.body', $search)->groupEnd();
        }

        $total = $model->countAllResults(false);
        // Posts newest first; drafts without a date sort by when they were last edited,
        // so a draft you just started is at the top rather than on the last page.
        $type === 'page'
            ? $model->orderBy('posts.title', 'ASC')
            : $model->orderBy('COALESCE(posts.published_at, posts.updated_at)', 'DESC', false);
        $rows = $model->findAll($perPage, ($page - 1) * $perPage);

        $links = new Permalinks();

        foreach ($rows as &$row) {
            $row['url'] = $links->post($row);
        }

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $perPage)]);
    }

    public function show(int $id)
    {
        $post = model(PostModel::class)->find($id);

        if ($post === null) {
            return $this->error('Not found', 404);
        }

        return $this->ok($this->present($post));
    }

    public function create()
    {
        return $this->save(null, $this->input());
    }

    public function update(int $id)
    {
        if (model(PostModel::class)->find($id) === null) {
            return $this->error('Not found', 404);
        }

        return $this->save($id, $this->input());
    }

    public function delete(int $id)
    {
        model(PostModel::class)->delete($id);
        $this->changed();

        return $this->ok();
    }

    public function revisions(int $id)
    {
        return $this->ok(model(RevisionModel::class)->where('post_id', $id)->orderBy('id', 'DESC')->findAll(50));
    }

    /**
     * Render a draft body the way the site would.
     */
    public function preview()
    {
        $data = $this->input();
        $html = (new ContentRenderer())->html([
            'id'     => (int) ($data['id'] ?? 0),
            'format' => ($data['format'] ?? 'markdown') === 'html' ? 'html' : 'markdown',
            'body'   => (string) ($data['body'] ?? ''),
        ]);

        return $this->ok(['html' => $html]);
    }

    private function save(?int $id, array $data)
    {
        $posts = model(PostModel::class);
        $type  = ($data['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            return $this->error('Give it a title.', 422, ['title' => 'Required']);
        }

        $status = in_array($data['status'] ?? '', self::STATUSES, true) ? $data['status'] : 'draft';

        $parentId = $type === 'page' && ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;

        if ($parentId !== null && $parentId === $id) {
            return $this->error('A page cannot be its own parent.', 422, ['parent_id' => 'Invalid']);
        }

        $slug = url_title(convert_accented_characters((string) ($data['slug'] ?? '') ?: $title), '-', true);
        $slug = $posts->uniqueSlug($slug, $type, $id, $parentId);

        $termIds    = array_map('intval', (array) ($data['term_ids'] ?? []));
        $categories = $termIds === [] ? [] : array_column(model(TermModel::class)->whereIn('id', $termIds)->where('taxonomy', 'category')->findAll(), 'id');
        $categoryId = (int) ($data['category_id'] ?? 0);

        if ($type === 'post' && ! in_array($categoryId, array_map('intval', $categories), true)) {
            $categoryId = (int) ($categories[0] ?? 0);
        }

        $publishedAt = trim((string) ($data['published_at'] ?? ''));

        if ($publishedAt !== '' && strtotime($publishedAt) === false) {
            return $this->error('That date is not valid.', 422, ['published_at' => 'Invalid']);
        }

        $media = ! empty($data['featured_media_id']) ? (int) $data['featured_media_id'] : null;

        if ($media !== null && model(MediaModel::class)->find($media) === null) {
            $media = null;
        }

        $row = [
            'type'              => $type,
            'status'            => $status,
            'title'             => $title,
            'slug'              => $slug,
            'format'            => ($data['format'] ?? 'markdown') === 'html' ? 'html' : 'markdown',
            'body'              => (string) ($data['body'] ?? ''),
            'excerpt'           => trim((string) ($data['excerpt'] ?? '')) ?: null,
            'meta_description'  => trim((string) ($data['meta_description'] ?? '')) ?: null,
            'template'          => ($data['template'] ?? '') === 'contact' ? 'contact' : null,
            'featured_media_id' => $media,
            'category_id'       => $type === 'post' && $categoryId ? $categoryId : null,
            'parent_id'         => $parentId,
            'menu_order'        => (int) ($data['menu_order'] ?? 0),
            'published_at'      => $publishedAt !== '' ? date('Y-m-d H:i:s', strtotime($publishedAt)) : ($status === 'published' ? date('Y-m-d H:i:s') : null),
        ];

        if ($id === null) {
            $row['author_id'] = $this->userId();
            $id               = (int) $posts->insert($row);
        } else {
            $before = $posts->find($id);
            $posts->snapshot($id, $this->userId());
            $posts->update($id, $row);
        }

        $posts->syncTerms($id, $type === 'post' ? $termIds : []);

        // A published post that moved keeps its old URL working, as WordPress did.
        if (isset($before) && $before['status'] === 'published') {
            $oldUrl = (new Permalinks())->post($before);
            $newUrl = (new Permalinks())->post($posts->find($id));

            if ($oldUrl !== $newUrl && $oldUrl !== '/') {
                $redirects = model(RedirectModel::class);
                $redirects->where('from_path', $newUrl)->delete();
                $existing = $redirects->where('from_path', $oldUrl)->first();
                $existing ? $redirects->update($existing['id'], ['to_path' => $newUrl]) : $redirects->insert(['from_path' => $oldUrl, 'to_path' => $newUrl, 'code' => 301]);
            }
        }
        $this->changed();

        return $this->ok($this->present($posts->find($id)), 200);
    }

    private function present(array $post): array
    {
        $post['term_ids']       = array_map('intval', array_column(model(PostModel::class)->terms((int) $post['id']), 'id'));
        $post['url']            = (new Permalinks())->post($post);
        $post['featured_media'] = $post['featured_media_id'] ? model(MediaModel::class)->find($post['featured_media_id']) : null;

        return $post;
    }
}
