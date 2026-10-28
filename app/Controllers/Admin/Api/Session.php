<?php

namespace App\Controllers\Admin\Api;

use App\Models\ContactMessageModel;
use App\Models\PostModel;
use App\Models\UserModel;

class Session extends ApiController
{
    public function me()
    {
        $user = model(UserModel::class)->find($this->userId());

        return $this->ok([
            'id'        => (int) $user['id'],
            'name'      => $user['name'],
            'email'     => $user['email'],
            'twoFactor' => $user['totp_secret'] !== null,
        ]);
    }

    public function dashboard()
    {
        $posts  = model(PostModel::class);
        $counts = $posts->select('type, status, COUNT(*) AS n')->groupBy(['type', 'status'])->findAll();
        $recent = model(PostModel::class)->select('id, type, status, title, updated_at')->orderBy('updated_at', 'DESC')->findAll(8);

        return $this->ok([
            'counts'   => $counts,
            'recent'   => $recent,
            'unread'   => model(ContactMessageModel::class)->where('read_at', null)->countAllResults(),
        ]);
    }
}
