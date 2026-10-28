<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Serves the Vue admin app (built into public/admin-app by `npm run build` in admin-ui/).
 */
class Spa extends BaseController
{
    public function index()
    {
        $built = is_file(FCPATH . 'admin-app/app.js');
        $ver   = static fn (string $file) => (string) (@filemtime(FCPATH . 'admin-app/' . $file) ?: '0');

        return $this->response->setBody(view('admin/spa', [
            'built'    => $built,
            'jsVer'    => $ver('app.js'),
            'cssVer'   => $ver('app.css'),
            'userName' => session('user_name'),
        ]));
    }
}
