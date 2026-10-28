<?php

use App\Models\PostModel;
use App\Models\RedirectModel;
use App\Models\SettingModel;
use App\Models\TermModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The public URLs must keep the shape of the old WordPress site.
 *
 * @internal
 */
final class PermalinkRoutingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private int $postId;

    protected function setUp(): void
    {
        parent::setUp();

        $fun  = model(TermModel::class)->insert(['taxonomy' => 'category', 'name' => 'Fun', 'slug' => 'fun', 'wp_id' => 3]);
        $geek = model(TermModel::class)->insert(['taxonomy' => 'category', 'name' => 'Geek', 'slug' => 'geek', 'wp_id' => 9]);

        $this->postId = model(PostModel::class)->insert([
            'type' => 'post', 'status' => 'published', 'title' => 'War and Peace', 'slug' => 'american-war-and-peace',
            'format' => 'markdown', 'body' => "Intro\n\n<!--more-->\n\nMore", 'category_id' => $fun,
            'published_at' => '2026-10-06 17:16:28', 'wp_id' => 1888,
        ]);
        model(PostModel::class)->syncTerms($this->postId, [$fun, $geek]);

        $about = model(PostModel::class)->insert(['type' => 'page', 'status' => 'published', 'title' => 'about', 'slug' => 'about-2', 'format' => 'html', 'body' => '<p>Hi</p>', 'published_at' => '2016-11-23 14:00:07']);
        $blog  = model(PostModel::class)->insert(['type' => 'page', 'status' => 'published', 'title' => 'blog', 'slug' => 'blog', 'format' => 'html', 'body' => '', 'published_at' => '2021-06-03 10:45:14']);
        model(PostModel::class)->insert(['type' => 'post', 'status' => 'private', 'title' => 'Secret', 'slug' => 'secret', 'format' => 'html', 'body' => 'x', 'category_id' => $fun, 'published_at' => '2020-01-01 00:00:00']);

        model(SettingModel::class)->put('front_page_id', (string) $about);
        model(SettingModel::class)->put('posts_page_id', (string) $blog);
    }

    private function getPath(string $path)
    {
        $_SERVER['REQUEST_URI'] = $path;

        return $this->get($path);
    }

    public function testPostAtCategoryPermalink(): void
    {
        $result = $this->getPath('/fun/american-war-and-peace/');
        $result->assertOK();
        $result->assertSee('War and Peace');
    }

    public function testMissingSlashAndWrongCategoryRedirect(): void
    {
        $this->getPath('/fun/american-war-and-peace')->assertRedirectTo('/fun/american-war-and-peace/');
        $this->getPath('/geek/american-war-and-peace/')->assertRedirectTo('/fun/american-war-and-peace/');
    }

    public function testFrontPageAndBlog(): void
    {
        $home = $this->getPath('/');
        $home->assertOK();
        $this->assertStringContainsString('<h1 class="site-title">', $home->getBody());
        $this->getPath('/about-2/')->assertRedirectTo('/');
        $blog = $this->getPath('/blog/');
        $blog->assertOK();
        $this->assertStringContainsString('href="/fun/american-war-and-peace/"', $blog->getBody());
        $this->getPath('/blog/page/2/')->assertStatus(404);
        $this->getPath('/author/admin/')->assertRedirectTo('/blog/');
    }

    public function testArchives(): void
    {
        $archive = $this->getPath('/category/geek/');
        $archive->assertOK();
        $archive->assertSee('War and Peace');
        $this->getPath('/category/nope/')->assertStatus(404);
    }

    public function testPrivatePostsAreHidden(): void
    {
        $this->getPath('/fun/secret/')->assertStatus(404);
        $this->getPath('/blog/')->assertDontSee('Secret');
    }

    public function testShortLinkAndStoredRedirect(): void
    {
        $this->get('/', ['p' => '1888'])->assertRedirectTo('/fun/american-war-and-peace/');

        model(RedirectModel::class)->insert(['from_path' => '/old/thing/', 'to_path' => '/fun/american-war-and-peace/', 'code' => 301]);
        $this->getPath('/old/thing/')->assertRedirectTo('/fun/american-war-and-peace/');
    }

    public function testFeedListsPublishedPosts(): void
    {
        $result = $this->get('/feed');
        $result->assertOK();
        $result->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        $xml = $result->response()->getBody();
        $this->assertStringContainsString('<link>' . rtrim(config('App')->baseURL, '/') . '/fun/american-war-and-peace/</link>', $xml);
        $this->assertStringNotContainsString('Secret', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'The feed is well-formed XML');
    }
}
