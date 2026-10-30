<?php

use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The contact form's errors and old input belong to one visitor and must never reach the page cache.
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

    protected function setUp(): void
    {
        parent::setUp();

        model(PostModel::class)->insert([
            'type' => 'page', 'status' => 'published', 'title' => 'Contact', 'slug' => 'contact', 'format' => 'html',
            'body' => '<p>Say hi.</p>', 'template' => 'contact', 'published_at' => '2021-06-04 12:19:23',
        ]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[config('Session')->cookieName]);

        parent::tearDown();
    }

    private function getPath(string $path)
    {
        $_SERVER['REQUEST_URI'] = $path;

        return $this->get($path);
    }

    public function testErrorRedirectGoesToTheCanonicalPath(): void
    {
        foreach (['/contact/#', '/contact', '/contact/?a=1'] as $return) {
            $result = $this->post('contact-form', ['name' => 'Eve', 'email' => 'not-an-email', 'message' => 'hello', 'return' => $return]);

            $result->assertStatus(303);
            $this->assertSame(rtrim(config('App')->baseURL, '/') . '/contact/?error=1#contact-form', $result->getRedirectUrl(), $return);
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
        // A visitor with a session cookie still gets private, no-store, which keeps it out of Cloudflare.
        $plain = $this->getPath('/contact/');
        $plain->assertDontSee('POISON-TEST');
        $this->assertSame('private, no-store', $plain->response()->getHeaderLine('Cache-Control'));

        $failed = $this->getPath('/contact/?error=1');
        $failed->assertSee('POISON-TEST');
        $this->assertSame('private, no-store', $failed->response()->getHeaderLine('Cache-Control'));

        unset($_COOKIE[config('Session')->cookieName]);
        $this->withSession([]);
        $this->getPath('/contact/')->assertDontSee('POISON-TEST');
    }
}
