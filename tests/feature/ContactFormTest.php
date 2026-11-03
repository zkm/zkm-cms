<?php

use App\Libraries\FormToken;
use App\Models\ContactMessageModel;
use App\Models\PostModel;
use CodeIgniter\I18n\Time;
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

        // The rate limit (5 an hour per IP) and spent tokens must not carry over between tests.
        cache()->clean();
        \Config\Services::resetSingle('throttler');

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
        Time::setTestNow();

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

    public function testTheTokenEndpointIsNeverCached(): void
    {
        $result = $this->get('contact-form/token');

        $result->assertStatus(200);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertMatchesRegularExpression('/^\d+\.[0-9a-f]{64}$/', json_decode($result->getJSON(), true)['token']);
    }

    public function testPostsWithoutAGoodTokenAreNeverStored(): void
    {
        $base = rtrim(config('App')->baseURL, '/');
        $form = ['name' => 'CharlesMes', 'email' => 'bot@example.com', 'message' => 'Read the news and earn https://telegra.ph/x', 'return' => '/contact/'];

        $this->post('contact-form', $form)->assertRedirectTo($base . '/contact/?error=1#contact-form');

        Time::setTestNow('2026-10-31 12:00:00');
        $fast = $this->post('contact-form', $form + ['form_token' => FormToken::issue()]);
        $this->assertSame($base . '/contact/?sent=1#contact-form', $fast->getRedirectUrl());

        $forged = $this->post('contact-form', $form + ['form_token' => '1700000000.' . str_repeat('a', 64)]);
        $this->assertSame($base . '/contact/?sent=1#contact-form', $forged->getRedirectUrl());

        $this->assertSame(0, model(ContactMessageModel::class)->countAllResults());
    }

    public function testATokenWorksOnlyOnce(): void
    {
        $base = rtrim(config('App')->baseURL, '/');
        $form = ['name' => 'Ann', 'email' => 'ann@example.com', 'message' => 'Hello there', 'return' => '/contact/'];

        Time::setTestNow('2026-10-31 12:00:00');
        $token = FormToken::issue();
        Time::setTestNow('2026-10-31 12:01:00');

        $this->post('contact-form', $form + ['form_token' => $token])->assertRedirectTo($base . '/contact/?sent=1#contact-form');
        $again = $this->post('contact-form', ['message' => 'Something else'] + $form + ['form_token' => $token]);
        $this->assertSame($base . '/contact/?sent=1#contact-form', $again->getRedirectUrl());

        $this->assertSame(1, model(ContactMessageModel::class)->countAllResults());
    }

    public function testTheSameMessageIsStoredOnlyOnceADay(): void
    {
        $base = rtrim(config('App')->baseURL, '/');
        $form = ['name' => 'Andrew Collins', 'email' => 'andrew@example.com', 'message' => 'Quick question about your site', 'return' => '/contact/'];

        $send = function (string $issued, string $at, array $form) use ($base): void {
            Time::setTestNow($issued);
            $token = FormToken::issue();
            Time::setTestNow($at);
            $this->post('contact-form', $form + ['form_token' => $token])->assertRedirectTo($base . '/contact/?sent=1#contact-form');
        };

        $send('2026-11-02 09:00:00', '2026-11-02 09:01:00', $form);
        $send('2026-11-02 09:02:00', '2026-11-02 09:03:00', $form);
        $send('2026-11-02 09:04:00', '2026-11-02 09:05:00', ['message' => 'A different question'] + $form);
        $send('2026-11-03 09:10:00', '2026-11-03 09:11:00', $form);

        $messages = model(ContactMessageModel::class);
        $this->assertSame(3, $messages->countAllResults());
        $this->assertSame(1, $messages->where('message', 'A different question')->countAllResults());
    }

    public function testACopyPostedAtTheSameMomentIsDroppedByTheUniqueKey(): void
    {
        $base = rtrim(config('App')->baseURL, '/');
        $form = ['name' => 'Andrew Collins', 'email' => 'Andrew@example.com', 'message' => 'Same second', 'return' => '/contact/'];

        // Stands in for the other request of the pair: stored with today's fingerprint, but
        // not yet visible to the 24-hour query (its created_at is older here).
        model(ContactMessageModel::class)->insert([
            'name' => 'Andrew Collins', 'email' => 'andrew@example.com', 'message' => 'Same second', 'created_at' => '2026-10-01 00:00:00',
            'fingerprint' => hash('sha256', "andrew@example.com\n2026-11-02\nSame second"),
        ]);

        Time::setTestNow('2026-11-02 08:17:40');
        $token = FormToken::issue();
        Time::setTestNow('2026-11-02 08:17:49');
        $this->post('contact-form', $form + ['form_token' => $token])->assertRedirectTo($base . '/contact/?sent=1#contact-form');

        $this->assertSame(1, model(ContactMessageModel::class)->countAllResults());
    }
}
