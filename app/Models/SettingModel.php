<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Key/value site settings (site name, tagline, front page, analytics ID, ...).
 */
class SettingModel extends Model
{
    public const DEFAULTS = [
        'site_name'      => 'ZachSchneider [dot] com',
        'tagline'        => 'code. coffee. cycle.',
        'front_page_id'  => '',
        'posts_page_id'  => '',
        'posts_per_page' => '10',
        'contact_email'  => 'me@zachschneider.com',
        'gtm_id'         => '',
        'twitter'        => '@zkm',
        'fediverse'      => '@zachschneider@mastodon.social',
        'footer_note'    => 'Built on CodeIgniter',
        'site_icon_id'   => '',
    ];

    protected $table         = 'settings';
    protected $primaryKey    = 'name';
    protected $returnType    = 'array';
    protected $allowedFields = ['name', 'value'];
    protected $useAutoIncrement = false;

    private static ?array $loaded = null;

    /**
     * @return array<string, string>
     */
    public function allSettings(): array
    {
        if (self::$loaded === null) {
            $rows         = $this->findAll();
            self::$loaded = self::DEFAULTS;

            foreach ($rows as $row) {
                self::$loaded[$row['name']] = (string) $row['value'];
            }
        }

        return self::$loaded;
    }

    public function value(string $name, string $default = ''): string
    {
        return $this->allSettings()[$name] ?? $default;
    }

    public function put(string $name, ?string $value): void
    {
        $this->db->table($this->table)->replace(['name' => $name, 'value' => $value]);
        self::$loaded = null;
    }
}
