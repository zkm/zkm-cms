<?php

namespace App\Controllers\Admin\Api;

use App\Models\ContactMessageModel;

class Messages extends ApiController
{
    public function index()
    {
        return $this->ok(model(ContactMessageModel::class)->orderBy('id', 'DESC')->findAll(200));
    }

    public function markRead(int $id)
    {
        model(ContactMessageModel::class)->update($id, ['read_at' => date('Y-m-d H:i:s')]);

        return $this->ok();
    }

    public function delete(int $id)
    {
        model(ContactMessageModel::class)->delete($id);

        return $this->ok();
    }
}
