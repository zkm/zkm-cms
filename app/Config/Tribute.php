<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * A quiet memorial banner under the header on every page (public/theme/tribute.css).
 *
 * It turns itself off after $lastDay (site time zone). Cached pages are keyed on
 * whether it's showing, so it disappears without a cache flush. Turn it off early
 * in .env with `tribute.enabled = false`, then flush the page cache.
 */
class Tribute extends BaseConfig
{
    public bool $enabled = true;

    /**
     * The last day the banner shows, as Y-m-d; empty for no end date.
     */
    public string $lastDay = '2026-11-13';

    /**
     * The person the banner is for.
     *
     * @var array{name: string, role: string, years: string, line: string}
     */
    public array $featured = [
        'name'  => 'Sir Patrick Stewart',
        'role'  => 'Captain Jean-Luc Picard',
        'years' => '1940 – 2026',
        'line'  => 'Make it so.',
    ];

    /**
     * Whether the banner should show right now.
     */
    public function isActive(): bool
    {
        return $this->enabled && ($this->lastDay === '' || date('Y-m-d') <= $this->lastDay);
    }
}
