<?php

namespace App\Commands;

use App\Libraries\WordPress\Importer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class WpImport extends BaseCommand
{
    protected $group       = 'WordPress';
    protected $name        = 'wp:import';
    protected $description = 'Import posts, pages, media, menus and settings from the wordpress database group. Safe to re-run.';
    protected $usage       = 'wp:import [--uploads <dir>]';
    protected $options     = ['--uploads' => 'A WordPress uploads folder to copy into public/uploads (default: writable/wp-import/uploads, if it exists)'];

    public function run(array $params)
    {
        $uploads = CLI::getOption('uploads');

        if (is_string($uploads) && ! str_starts_with($uploads, '/')) {
            $uploads = ROOTPATH . $uploads;
        }

        if ($uploads === null && is_dir(WRITEPATH . 'wp-import/uploads')) {
            $uploads = WRITEPATH . 'wp-import/uploads';
        }

        $importer = new Importer(null, static fn (string $line) => CLI::write('  ' . $line));
        CLI::write('Importing from ' . $importer->siteUrl() . ($uploads ? " (uploads: {$uploads})" : ' (no uploads folder)'));
        $importer->run(is_string($uploads) ? $uploads : null);
        CLI::write('Done.', 'green');

        return EXIT_SUCCESS;
    }
}
