<?php

namespace App\Models;

use CodeIgniter\Model;

class PostModel extends Model
{
    protected $table         = 'posts';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'type', 'status', 'title', 'slug', 'format', 'body', 'excerpt', 'meta_description', 'template',
        'featured_media_id', 'category_id', 'parent_id', 'menu_order', 'author_id', 'published_at', 'wp_id',
        // Set by the importer to keep WordPress dates; filled in automatically otherwise.
        'created_at', 'updated_at',
    ];

    /**
     * Published posts, newest first, optionally limited to one term.
     */
    public function publishedPosts(?int $termId = null): self
    {
        $this->where('posts.type', 'post')
            ->where('posts.status', 'published')
            ->where('posts.published_at <=', date('Y-m-d H:i:s'))
            ->orderBy('posts.published_at', 'DESC');

        if ($termId !== null) {
            $this->join('post_terms', 'post_terms.post_id = posts.id')
                ->where('post_terms.term_id', $termId)
                ->select('posts.*');
        }

        return $this;
    }

    /**
     * Terms attached to a post, categories first.
     *
     * @return list<array<string, mixed>>
     */
    public function terms(int $postId, ?string $taxonomy = null): array
    {
        $builder = $this->db->table('terms')
            ->select('terms.*')
            ->join('post_terms', 'post_terms.term_id = terms.id')
            ->where('post_terms.post_id', $postId)
            ->orderBy('terms.taxonomy')
            ->orderBy('terms.name');

        if ($taxonomy !== null) {
            $builder->where('terms.taxonomy', $taxonomy);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * @param list<int> $termIds
     */
    public function syncTerms(int $postId, array $termIds): void
    {
        $this->db->table('post_terms')->where('post_id', $postId)->delete();

        $rows = array_map(static fn (int $termId) => ['post_id' => $postId, 'term_id' => $termId], array_values(array_unique($termIds)));

        if ($rows !== []) {
            $this->db->table('post_terms')->insertBatch($rows);
        }
    }

    /**
     * Save the current copy of a post to its revision history.
     */
    public function snapshot(int $postId, ?int $userId): void
    {
        $post = $this->find($postId);

        if ($post === null) {
            return;
        }

        model(RevisionModel::class)->insert([
            'post_id' => $postId,
            'title'   => $post['title'],
            'format'  => $post['format'],
            'body'    => $post['body'],
            'excerpt' => $post['excerpt'],
            'user_id' => $userId,
        ]);
    }

    /**
     * A slug that no other post of the same type (and parent, for pages) uses.
     */
    public function uniqueSlug(string $slug, string $type, ?int $exceptId = null, ?int $parentId = null): string
    {
        $base      = $slug !== '' ? $slug : 'untitled';
        $candidate = $base;

        for ($n = 2; ; $n++) {
            $builder = $this->builder()->where('type', $type)->where('slug', $candidate);

            if ($type === 'page') {
                $parentId === null ? $builder->where('parent_id', null) : $builder->where('parent_id', $parentId);
            }

            if ($exceptId !== null) {
                $builder->where('id !=', $exceptId);
            }

            if ($builder->countAllResults() === 0) {
                return $candidate;
            }

            $candidate = $base . '-' . $n;
        }
    }
}
