<?php

namespace App\Controllers\Admin\Api;

use App\Models\MediaModel;
use App\Models\PostModel;
use Config\Services;

class Media extends ApiController
{
    private const IMAGE_SIZES = ['medium' => 300, 'large' => 1024];

    public function index()
    {
        $search  = trim((string) $this->request->getGet('q'));
        $page    = max(1, (int) $this->request->getGet('page'));
        $images  = $this->request->getGet('images') === '1';
        $perPage = 48;
        $model   = model(MediaModel::class);

        if ($search !== '') {
            $model->groupStart()->like('path', $search)->orLike('alt', $search)->groupEnd();
        }

        if ($images) {
            $model->like('mime', 'image/', 'after');
        }

        $total = $model->countAllResults(false);
        $rows  = $model->orderBy('id', 'DESC')->findAll($perPage, ($page - 1) * $perPage);

        return $this->ok(['items' => array_map([$this, 'present'], $rows), 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $perPage)]);
    }

    public function upload()
    {
        $file = $this->request->getFile('file');

        if ($file === null || ! $file->isValid()) {
            return $this->error($file ? $file->getErrorString() : 'No file was sent.');
        }

        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'pdf', 'mp3', 'mp4', 'webm', 'zip', 'txt'];
        $ext     = strtolower($file->guessExtension() ?: $file->getClientExtension());

        if (! in_array($ext, $allowed, true) || ! in_array(strtolower($file->getClientExtension()), $allowed, true)) {
            return $this->error('That kind of file is not allowed (' . implode(', ', $allowed) . ').');
        }

        $dir  = date('Y/m');
        $base = url_title(pathinfo($file->getClientName(), PATHINFO_FILENAME), '-', true) ?: 'file';
        $name = $base . '.' . $ext;

        for ($n = 2; is_file(FCPATH . "uploads/{$dir}/{$name}"); $n++) {
            $name = "{$base}-{$n}.{$ext}";
        }

        $mime = $file->getMimeType();
        $file->move(FCPATH . 'uploads/' . $dir, $name);
        $path  = $dir . '/' . $name;
        $full  = FCPATH . 'uploads/' . $path;
        $row   = ['path' => $path, 'mime' => $mime, 'bytes' => filesize($full), 'alt' => '', 'sizes' => '{}'];
        $info  = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? @getimagesize($full) : false;

        if ($info !== false) {
            [$row['width'], $row['height']] = $info;
            $row['sizes'] = json_encode($this->resize($full, $dir, $base, $ext, $info[0], $info[1]), JSON_UNESCAPED_SLASHES);
        }

        $id = model(MediaModel::class)->insert($row);

        return $this->ok($this->present(model(MediaModel::class)->find($id)), 201);
    }

    public function update(int $id)
    {
        $data = $this->input();
        model(MediaModel::class)->update($id, [
            'alt'     => mb_substr(trim((string) ($data['alt'] ?? '')), 0, 500),
            'caption' => trim((string) ($data['caption'] ?? '')) ?: null,
        ]);
        $this->changed();

        return $this->ok($this->present(model(MediaModel::class)->find($id)));
    }

    public function delete(int $id)
    {
        $media = model(MediaModel::class)->find($id);

        if ($media === null) {
            return $this->error('Not found', 404);
        }

        $usedBy = model(PostModel::class)->groupStart()
            ->where('featured_media_id', $id)
            ->orLike('body', '/uploads/' . $media['path'])
            ->groupEnd()->select('id, title')->findAll(5);

        if ($usedBy !== [] && $this->request->getGet('force') !== '1') {
            return $this->error('Still used by: ' . implode(', ', array_column($usedBy, 'title')), 409);
        }

        foreach (array_merge([$media['path']], array_column(json_decode($media['sizes'] ?? '[]', true) ?: [], 'path')) as $path) {
            $file = FCPATH . 'uploads/' . $path;

            if (is_file($file) && str_starts_with(realpath($file), realpath(FCPATH . 'uploads') . '/')) {
                unlink($file);
            }
        }

        model(PostModel::class)->where('featured_media_id', $id)->set('featured_media_id', null)->update();
        model(MediaModel::class)->delete($id);
        $this->changed();

        return $this->ok();
    }

    /**
     * Make the smaller copies WordPress would have (medium 300px, large 1024px wide).
     */
    private function resize(string $full, string $dir, string $base, string $ext, int $width, int $height): array
    {
        $sizes = [];

        foreach (self::IMAGE_SIZES as $label => $max) {
            if ($width <= $max && $height <= $max) {
                continue;
            }

            $scale = $max / max($width, $height);
            $w     = (int) round($width * $scale);
            $h     = (int) round($height * $scale);
            $name  = "{$base}-{$w}x{$h}.{$ext}";

            try {
                Services::image('gd', null, false)->withFile($full)->resize($w, $h, true)->save(FCPATH . "uploads/{$dir}/{$name}", 85);
                $sizes[$label] = ['path' => "{$dir}/{$name}", 'width' => $w, 'height' => $h];
            } catch (\Throwable $e) {
                log_message('error', "Resize of {$full} failed: " . $e->getMessage());
            }
        }

        return $sizes;
    }

    private function present(array $media): array
    {
        $sizes          = json_decode($media['sizes'] ?? '[]', true) ?: [];
        $media['sizes'] = $sizes;
        $media['url']   = '/uploads/' . $media['path'];
        $media['thumb'] = '/uploads/' . ($sizes['medium']['path'] ?? $sizes['thumbnail']['path'] ?? $media['path']);
        $media['large'] = '/uploads/' . ($sizes['large']['path'] ?? $media['path']);

        return $media;
    }
}
