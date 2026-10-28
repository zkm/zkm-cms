<?php

use App\Libraries\WordPress\Importer;
use App\Models\PostModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Runs the importer against a small WordPress database (tables prefixed wpt_ in the
 * test database).
 *
 * @internal
 */
final class ImporterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private BaseConnection $wp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wp = Database::connect(['DBPrefix' => 'wpt_'] + config('Database')->tests, false);
        $this->createWordPressTables();

        $this->wp->table('options')->insertBatch([
            ['option_name' => 'home', 'option_value' => 'https://www.example.com'],
            ['option_name' => 'blogname', 'option_value' => 'Example'],
            ['option_name' => 'blogdescription', 'option_value' => 'Tagline'],
            ['option_name' => 'posts_per_page', 'option_value' => '10'],
            ['option_name' => 'show_on_front', 'option_value' => 'posts'],
            ['option_name' => 'stylesheet', 'option_value' => 'theme'],
        ]);
        $this->wp->table('terms')->insert(['term_id' => 3, 'name' => 'Fun', 'slug' => 'fun']);
        $this->wp->table('term_taxonomy')->insert(['term_taxonomy_id' => 3, 'term_id' => 3, 'taxonomy' => 'category', 'description' => '', 'parent' => 0]);

        foreach ([10 => 'Edited later', 11 => 'Left alone'] as $id => $title) {
            $this->wp->table('posts')->insert([
                'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title,
                'post_name' => 'post-' . $id, 'post_content' => "<!-- wp:paragraph -->\n<p>Original {$id}</p>\n<!-- /wp:paragraph -->",
                'post_excerpt' => '', 'post_parent' => 0, 'menu_order' => 0, 'post_mime_type' => '',
                'post_date' => '2020-01-01 00:00:00', 'post_date_gmt' => '2020-01-01 06:00:00',
                'post_modified' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 06:00:00',
            ]);
            $this->wp->table('term_relationships')->insert(['object_id' => $id, 'term_taxonomy_id' => 3]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['options', 'terms', 'term_taxonomy', 'term_relationships', 'posts', 'postmeta', 'comments'] as $table) {
            $this->wp->query('DROP TABLE IF EXISTS ' . $this->wp->prefixTable($table));
        }

        parent::tearDown();
    }

    private function import(bool $overwriteEdited = false): Importer
    {
        $importer                  = new Importer($this->wp);
        $importer->overwriteEdited = $overwriteEdited;
        $importer->run();

        return $importer;
    }

    private function local(int $wpId): array
    {
        return model(PostModel::class)->where('wp_id', $wpId)->first();
    }

    public function testImportsPostsWithConvertedContent(): void
    {
        $this->import();

        $post = $this->local(10);
        $this->assertSame('<p>Original 10</p>', $post['body']);
        $this->assertSame('published', $post['status']);
        $this->assertNotNull($post['category_id']);
    }

    public function testKeepsPostsEditedInZkmCms(): void
    {
        $this->import();

        // Edit post 10 the way the admin does: save a revision, then update.
        $posts = model(PostModel::class);
        $local = $this->local(10);
        $posts->snapshot((int) $local['id'], null);
        $posts->update($local['id'], ['body' => '<p>Edited in zkm-cms</p>', 'excerpt' => 'My summary']);

        // Meanwhile both posts change in WordPress.
        foreach ([10, 11] as $id) {
            $this->wp->table('posts')->where('ID', $id)->update(['post_content' => "<p>Changed in WordPress {$id}</p>"]);
        }

        $importer = $this->import();

        $this->assertSame('<p>Edited in zkm-cms</p>', $this->local(10)['body'], 'the edit survives');
        $this->assertSame('My summary', $this->local(10)['excerpt']);
        $this->assertSame('<p>Changed in WordPress 11</p>', $this->local(11)['body'], 'unedited posts still update');
        $this->assertSame(1, $importer->counts['kept (edited in zkm-cms)'] ?? 0);
        $this->assertSame(2, model(PostModel::class)->where('wp_id IS NOT NULL')->countAllResults(), 'nothing duplicated');

        // Asked to, the import replaces the edit.
        $this->import(true);
        $this->assertSame('<p>Changed in WordPress 10</p>', $this->local(10)['body']);
    }

    private function createWordPressTables(): void
    {
        $forge = Database::forge($this->wp);
        $id    = ['type' => 'BIGINT', 'unsigned' => true];
        $text  = ['type' => 'TEXT', 'null' => true];
        $str   = ['type' => 'VARCHAR', 'constraint' => 200, 'default' => ''];
        $date  = ['type' => 'DATETIME', 'null' => true];

        $tables = [
            'options'            => ['option_name' => $str, 'option_value' => $text],
            'terms'              => ['term_id' => $id, 'name' => $str, 'slug' => $str],
            'term_taxonomy'      => ['term_taxonomy_id' => $id, 'term_id' => $id, 'taxonomy' => $str, 'description' => $text, 'parent' => $id],
            'term_relationships' => ['object_id' => $id, 'term_taxonomy_id' => $id],
            'postmeta'           => ['post_id' => $id, 'meta_key' => $str, 'meta_value' => $text],
            'posts'              => [
                'ID' => $id, 'post_type' => $str, 'post_status' => $str, 'post_title' => $str, 'post_name' => $str,
                'post_content' => $text, 'post_excerpt' => $text, 'post_parent' => $id, 'menu_order' => ['type' => 'INT', 'default' => 0],
                'post_mime_type' => $str, 'post_date' => $date, 'post_date_gmt' => $date, 'post_modified' => $date, 'post_modified_gmt' => $date,
            ],
            'comments' => [
                'comment_ID' => $id, 'comment_post_ID' => $id, 'comment_author' => $str, 'comment_author_email' => $str,
                'comment_author_url' => $str, 'comment_content' => $text, 'comment_type' => $str, 'comment_approved' => $str,
                'comment_parent' => $id, 'comment_date' => $date, 'comment_date_gmt' => $date,
            ],
        ];

        foreach ($tables as $table => $fields) {
            $forge->addField($fields);
            $forge->createTable($table, true);
        }
    }
}
