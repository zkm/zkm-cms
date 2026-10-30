<?php

use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The contact form's errors and old input belong to one visitor and must never reach a cache.
 *
 * @internal
 */
final class ContactFormTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private ?string $requestUri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestUri = $_SERVER['REQUEST_URI'] ?? null;

        $posts = model(PostModel::class);
        $posts->insert([
            'type' => 'page', 'status' => 'published', 'title' => 'Contact', 'slug' => 'contact', 'format' => 'html',
            'body' => '<p>Say hi.</p>', 'template' => 'contact', 'published_at' => '2021-06-04 12:19:23',
        ]);
        $about = $posts->insert(['type' => 'page', 'status' => 'published', 'title' => 'About', 'slug' => 'about', 'format' => 'html', 'body' => '', 'published_at' => '2021-06-04 12:00:00']);
        $posts->insert([
            'type' => 'page', 'status' => 'published', 'title' => 'Write', 'slug' => 'write', 'parent_id' => $about, 'format' => 'html',
            'body' => '', 'template' => 'contact', 'published_at' => '2021-06-04 12:20:00',
        ]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[config('Session')->cookieName]);

        if ($this->requestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->requestUri;
        }

        parent::tearDown();
    }

    private function getPath(string $path)
    {
        $_SERVER['REQUEST_URI'] = $path;

        return $this->get($path);
    }

    public function testErrorRedirectGoesToAContactPagesPermalink(): void
    {
        $base  = rtrim(config('App')->baseURL, '/');
        $cases = [
            '/about/write/' => '/about/write/',
            // Tampered or stale values fall back to the first contact page, never somewhere else.
            '/contact/#'    => '/contact/',
            '/contact'      => '/contact/',
            '/contact/?a=1' => '/contact/',
            '//contact/'    => '/contact/',
            '/contact/:80'  => '/contact/',
            '/write/'       => '/contact/',
            'https://evil.example/' => '/contact/',
        ];

        foreach ($cases as $return => $page) {
            $result = $this->post('contact-form', ['name' => 'Eve', 'email' => 'not-an-email', 'message' => 'hello', 'return' => $return]);

            $result->assertStatus(303);
            $this->assertSame($base . $page . '?error=1#contact-form', $result->getRedirectUrl(), $return);
        }
    }

    public function testErrorsShowOnlyOnTheErrorPageAndAreNeverCached(): void
    {
        $_COOKIE[config('Session')->cookieName] = 'visitor';
        $this->withSession([
            'contact_errors' => ['name' => 'POISON-TEST visit evil.example'],
            '__ci_vars'      => ['contact_errors' => 'new'],
        ]);

        // The plain page goes through the page cache, so it ignores the visitor's errors.
        $plain = $this->getPath('/contact/');
        $plain->assertDontSee('POISON-TEST');
        $this->assertStringContainsString('no-store', $plain->response()->getHeaderLine('Cache-Control'));

        $failed = $this->getPath('/contact/?error=1');
        $failed->assertSee('POISON-TEST');
        $this->assertStringContainsString('no-store', $failed->response()->getHeaderLine('Cache-Control'));

        unset($_COOKIE[config('Session')->cookieName]);
        $this->withSession([]);
        $this->getPath('/contact/')->assertDontSee('POISON-TEST');
    }

    public function testOnlyRequestsWithoutASessionGetAPublicCacheControl(): void
    {
        $this->assertSame('public, max-age=0, s-maxage=300', $this->getPath('/contact/')->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $this->getPath('/contact/?sent=1')->response()->getHeaderLine('Cache-Control'));

        $_COOKIE[config('Session')->cookieName] = 'visitor';
        $this->assertStringContainsString('no-store', $this->getPath('/contact/')->response()->getHeaderLine('Cache-Control'));
    }
}
