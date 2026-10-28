<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Each sign-in records the user's session_version. Raising it (on a password
 * or email change, or "sign out everywhere") ends every session that has the old one.
 */
class AddSessionVersion extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'session_version' => ['type' => 'INT', 'unsigned' => true, 'default' => 1, 'after' => 'totp_last_step'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'session_version');
    }
}
