<?php

namespace App\Controllers\Admin\Api;

use App\Models\SettingModel;

class Settings extends ApiController
{
    public function index()
    {
        return $this->ok(model(SettingModel::class)->allSettings());
    }

    public function save()
    {
        $settings = model(SettingModel::class);

        foreach ($this->input() as $name => $value) {
            if (array_key_exists($name, SettingModel::DEFAULTS)) {
                $settings->put($name, trim((string) $value));
            }
        }

        $this->changed();

        return $this->index();
    }
}
