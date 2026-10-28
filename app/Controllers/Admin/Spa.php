<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Serves the Vue admin app (built into public/admin-app by `npm run build` in admin-ui/).
 * File names come from Vite's manifest; they carry content hashes, so no ?v= is needed.
 */
class Spa extends BaseController
{
    public function index()
    {
        $entry = self::entry();

        return $this->response->setBody(view('admin/spa', [
            'js'       => $entry['file'] ?? null,
            'css'      => $entry['css'] ?? [],
            'userName' => session('user_name'),
        ]));
    }

    /**
     * The built entry from Vite's manifest: its hashed JS file and CSS files.
     *
     * @return array{file?: string, css?: list<string>}
     */
    private static function entry(): array
    {
        $manifest = json_decode((string) @file_get_contents(FCPATH . 'admin-app/manifest.json'), true);

        foreach ((array) $manifest as $chunk) {
            if (! empty($chunk['isEntry'])) {
                return $chunk;
            }
        }

        return [];
    }
}
