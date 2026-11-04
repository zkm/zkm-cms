<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A hash of the sender's address, the message and the day it came in. The unique key
 * stops two copies of a message posted at the same moment from both being stored.
 * Older rows keep NULL, which the unique key allows any number of.
 */
class AddContactFingerprint extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('contact_messages', [
            'fingerprint' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'after' => 'user_agent'],
        ]);
        $this->db->query('ALTER TABLE ' . $this->db->prefixTable('contact_messages') . ' ADD UNIQUE KEY contact_messages_fingerprint (fingerprint)');
    }

    public function down(): void
    {
        $this->forge->dropColumn('contact_messages', 'fingerprint');
    }
}
