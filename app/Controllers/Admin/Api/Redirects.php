<?php

namespace App\Controllers\Admin\Api;

use App\Models\RedirectModel;

class Redirects extends ApiController
{
    public function index()
    {
        return $this->ok(model(RedirectModel::class)->orderBy('from_path')->findAll());
    }

    public function create()
    {
        $data = $this->input();
        $from = '/' . trim((string) ($data['from_path'] ?? ''), '/') . '/';
        $to   = trim((string) ($data['to_path'] ?? ''));
        $code = in_array((int) ($data['code'] ?? 301), [301, 302, 410], true) ? (int) $data['code'] : 301;

        if ($from === '//' || ($code !== 410 && ! preg_match('#^(https?://|/)#', $to))) {
            return $this->error('Give a path to redirect from, and a target starting with / or https://.');
        }

        $model    = model(RedirectModel::class);
        $existing = $model->where('from_path', $from)->first();
        $row      = ['from_path' => $from, 'to_path' => $to, 'code' => $code];
        $existing ? $model->update($existing['id'], $row) : $model->insert($row);
        $this->changed();

        return $this->index();
    }

    public function delete(int $id)
    {
        model(RedirectModel::class)->delete($id);
        $this->changed();

        return $this->index();
    }
}
