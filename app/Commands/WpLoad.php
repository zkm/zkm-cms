<?php

namespace App\Commands;

use App\Libraries\WordPress\WpressArchive;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Unpacks an All-in-One WP Migration backup: the uploads go to writable/wp-import/uploads
 * and the SQL dump is loaded into the `wordpress` database group, ready for wp:import.
 */
class WpLoad extends BaseCommand
{
    protected $group       = 'WordPress';
    protected $name        = 'wp:load';
    protected $description = 'Load a .wpress backup into the wp_import database and writable/wp-import/uploads.';
    protected $usage       = 'wp:load <backup.wpress>';
    protected $arguments   = ['backup.wpress' => 'An All-in-One WP Migration export'];

    public function run(array $params)
    {
        $file = $params[0] ?? null;

        if ($file === null) {
            CLI::error('Give the path to a .wpress file.');

            return EXIT_USER_INPUT;
        }

        // spark runs from public/, so read relative paths from the project root.
        if (! str_starts_with($file, '/')) {
            $file = ROOTPATH . $file;
        }

        $db = Database::connect('wordpress');

        // The load drops and recreates every table it contains. Only ever aim it at a scratch database.
        if (! str_starts_with($db->getDatabase(), 'wp_import')) {
            CLI::error("Refusing to load into '{$db->getDatabase()}': the wordpress database group must point at a scratch database named wp_import*.");

            return EXIT_ERROR;
        }

        $dest = WRITEPATH . 'wp-import';
        CLI::write("Extracting {$file} ...");
        $count = (new WpressArchive($file))->extract($dest, ['database.sql', 'package.json', 'uploads/']);
        CLI::write("  {$count} files written to {$dest}");

        $sql = file_get_contents($dest . '/database.sql');
        $sql = str_replace('SERVMASK_PREFIX_', $db->getPrefix(), $sql);

        CLI::write('Loading database.sql into ' . $db->getDatabase() . ' ...');
        $mysqli = $db->connect();
        $mysqli->set_charset('utf8mb4');

        if (! $mysqli->multi_query($sql)) {
            CLI::error($mysqli->error);

            return EXIT_ERROR;
        }

        $statements = 0;

        do {
            $statements++;

            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

        if ($mysqli->errno) {
            CLI::error("Statement {$statements}: {$mysqli->error}");

            return EXIT_ERROR;
        }

        CLI::write("  {$statements} statements", 'green');

        // The backup blanks the active theme in the dump and keeps it in package.json.
        // wp:import needs it to find the menu locations.
        $package = json_decode((string) @file_get_contents($dest . '/package.json'), true) ?: [];

        foreach (['stylesheet' => 'Stylesheet', 'template' => 'Template'] as $option => $key) {
            if (! empty($package[$key])) {
                $db->table('options')->where('option_name', $option)->where('option_value', '')->update(['option_value' => $package[$key]]);
            }
        }
        CLI::write('Next: php spark wp:import --uploads ' . $dest . '/uploads');

        return EXIT_SUCCESS;
    }
}
