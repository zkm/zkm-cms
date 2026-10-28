<?php

namespace App\Controllers\Admin\Api;

use App\Models\TermModel;

class Terms extends ApiController
{
    public function index()
    {
        $rows = model(TermModel::class)
            ->select('terms.*, COUNT(post_terms.post_id) AS posts')
            ->join('post_terms', 'post_terms.term_id = terms.id', 'left')
            ->groupBy('terms.id')
            ->orderBy('terms.taxonomy')->orderBy('terms.name')
            ->findAll();

        return $this->ok($rows);
    }

    public function create()
    {
        return $this->save(null, $this->input());
    }

    public function update(int $id)
    {
        return $this->save($id, $this->input());
    }

    public function delete(int $id)
    {
        model(TermModel::class)->delete($id);
        $this->changed();

        return $this->ok();
    }

    private function save(?int $id, array $data)
    {
        $terms    = model(TermModel::class);
        $taxonomy = ($data['taxonomy'] ?? 'tag') === 'category' ? 'category' : 'tag';
        $name     = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            return $this->error('Give it a name.', 422, ['name' => 'Required']);
        }

        $slug  = url_title(convert_accented_characters((string) ($data['slug'] ?? '') ?: $name), '-', true);
        $clash = $terms->where('taxonomy', $taxonomy)->where('slug', $slug);

        if ($id !== null) {
            $clash->where('id !=', $id);
        }

        if ($clash->first() !== null) {
            return $this->error("There is already a {$taxonomy} called {$slug}.", 422, ['slug' => 'Taken']);
        }

        $row = [
            'taxonomy'    => $taxonomy,
            'name'        => $name,
            'slug'        => $slug,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'parent_id'   => $taxonomy === 'category' && ! empty($data['parent_id']) && (int) $data['parent_id'] !== $id ? (int) $data['parent_id'] : null,
        ];

        if ($id === null) {
            $id = (int) $terms->insert($row);
        } else {
            $terms->update($id, $row);
        }

        $this->changed();

        return $this->ok($terms->find($id));
    }
}
