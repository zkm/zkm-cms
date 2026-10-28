<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Remembers the time step of the last accepted authenticator code, so a code
 * can't be used twice (RFC 6238 section 5.2).
 */
class AddTotpLastStep extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'totp_last_step' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'totp_secret'],
        ]);
        // Room for the encrypted form of the secret.
        $this->forge->modifyColumn('users', [
            'totp_secret' => ['name' => 'totp_secret', 'type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'totp_last_step');
    }
}
