<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCmsTables extends Migration
{
    private array $attributes = ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci'];

    public function up(): void
    {
        $id = ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true];
        $fk = ['type' => 'INT', 'unsigned' => true, 'null' => true];
        $timestamps = [
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ];

        $this->forge->addField([
            'id'            => $id,
            'email'         => ['type' => 'VARCHAR', 'constraint' => 191],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'totp_secret'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'last_login_at' => ['type' => 'DATETIME', 'null' => true],
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('users', true, $this->attributes);

        // Uploaded files. `path` is relative to public/uploads; `sizes` holds the resized copies.
        $this->forge->addField([
            'id'      => $id,
            'path'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'mime'    => ['type' => 'VARCHAR', 'constraint' => 100],
            'width'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'height'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'bytes'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'alt'     => ['type' => 'VARCHAR', 'constraint' => 500, 'default' => ''],
            'caption' => ['type' => 'TEXT', 'null' => true],
            'sizes'   => ['type' => 'JSON', 'null' => true],
            'wp_id'   => $fk,
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('path');
        $this->forge->addUniqueKey('wp_id');
        $this->forge->createTable('media', true, $this->attributes);

        $this->forge->addField([
            'id'          => $id,
            'taxonomy'    => ['type' => 'ENUM', 'constraint' => ['category', 'tag']],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 200],
            'slug'        => ['type' => 'VARCHAR', 'constraint' => 191],
            'description' => ['type' => 'TEXT', 'null' => true],
            'parent_id'   => $fk,
            'wp_id'       => $fk,
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['taxonomy', 'slug']);
        $this->forge->addUniqueKey('wp_id');
        $this->forge->createTable('terms', true, $this->attributes);

        // Posts and pages. `format` says how `body` is rendered: html (imported) or markdown.
        // `category_id` is the category in the post's URL (/category/slug/).
        $this->forge->addField([
            'id'               => $id,
            'type'             => ['type' => 'ENUM', 'constraint' => ['post', 'page'], 'default' => 'post'],
            'status'           => ['type' => 'ENUM', 'constraint' => ['published', 'draft', 'pending', 'private'], 'default' => 'draft'],
            'title'            => ['type' => 'VARCHAR', 'constraint' => 300],
            'slug'             => ['type' => 'VARCHAR', 'constraint' => 191],
            'format'           => ['type' => 'ENUM', 'constraint' => ['html', 'markdown'], 'default' => 'markdown'],
            'body'             => ['type' => 'MEDIUMTEXT', 'null' => true],
            'excerpt'          => ['type' => 'TEXT', 'null' => true],
            'meta_description' => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'template'         => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'featured_media_id' => $fk,
            'category_id'      => $fk,
            'parent_id'        => $fk,
            'menu_order'       => ['type' => 'INT', 'default' => 0],
            'author_id'        => $fk,
            'published_at'     => ['type' => 'DATETIME', 'null' => true],
            'wp_id'            => $fk,
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['type', 'status', 'published_at']);
        $this->forge->addKey('slug');
        $this->forge->addUniqueKey('wp_id');
        $this->forge->createTable('posts', true, $this->attributes);

        $this->forge->addField([
            'post_id' => ['type' => 'INT', 'unsigned' => true],
            'term_id' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['post_id', 'term_id']);
        $this->forge->addKey('term_id');
        $this->forge->addForeignKey('post_id', 'posts', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('term_id', 'terms', 'id', '', 'CASCADE');
        $this->forge->createTable('post_terms', true, $this->attributes);

        // The previous copy of a post, saved each time it is edited.
        $this->forge->addField([
            'id'         => $id,
            'post_id'    => ['type' => 'INT', 'unsigned' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 300],
            'format'     => ['type' => 'VARCHAR', 'constraint' => 10],
            'body'       => ['type' => 'MEDIUMTEXT', 'null' => true],
            'excerpt'    => ['type' => 'TEXT', 'null' => true],
            'user_id'    => $fk,
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('post_id');
        $this->forge->addForeignKey('post_id', 'posts', 'id', '', 'CASCADE');
        $this->forge->createTable('revisions', true, $this->attributes);

        // Imported WordPress comments, kept as an archive. The theme never displayed them.
        $this->forge->addField([
            'id'           => $id,
            'post_id'      => ['type' => 'INT', 'unsigned' => true],
            'author'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'author_email' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'author_url'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'body'         => ['type' => 'TEXT'],
            'type'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'comment'],
            'approved'     => ['type' => 'TINYINT', 'default' => 0],
            'parent_id'    => $fk,
            'wp_id'        => $fk,
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('post_id');
        $this->forge->addUniqueKey('wp_id');
        $this->forge->addForeignKey('post_id', 'posts', 'id', '', 'CASCADE');
        $this->forge->createTable('comments', true, $this->attributes);

        // Menu links by location (primary, social, footer). A link points at a post or a URL.
        $this->forge->addField([
            'id'       => $id,
            'location' => ['type' => 'VARCHAR', 'constraint' => 30],
            'label'    => ['type' => 'VARCHAR', 'constraint' => 100],
            'url'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'post_id'  => $fk,
            'sort'     => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['location', 'sort']);
        $this->forge->addForeignKey('post_id', 'posts', 'id', '', 'CASCADE');
        $this->forge->createTable('menu_items', true, $this->attributes);

        $this->forge->addField([
            'id'        => $id,
            'from_path' => ['type' => 'VARCHAR', 'constraint' => 191],
            'to_path'   => ['type' => 'VARCHAR', 'constraint' => 500],
            'code'      => ['type' => 'SMALLINT', 'default' => 301],
            'hits'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('from_path');
        $this->forge->createTable('redirects', true, $this->attributes);

        $this->forge->addField([
            'name'  => ['type' => 'VARCHAR', 'constraint' => 100],
            'value' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('name');
        $this->forge->createTable('settings', true, $this->attributes);

        $this->forge->addField([
            'id'         => $id,
            'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'email'      => ['type' => 'VARCHAR', 'constraint' => 191],
            'message'    => ['type' => 'TEXT'],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'emailed'    => ['type' => 'TINYINT', 'default' => 0],
            'read_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('contact_messages', true, $this->attributes);
    }

    public function down(): void
    {
        foreach (['contact_messages', 'settings', 'redirects', 'menu_items', 'comments', 'revisions', 'post_terms', 'posts', 'terms', 'media', 'users'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
