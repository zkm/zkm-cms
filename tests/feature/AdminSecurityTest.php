<?php

use App\Libraries\SecretBox;
use App\Libraries\Totp;
use App\Models\UserModel;
use CodeIgniter\Cache\Handlers\FileHandler;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Throttle\Throttler;
use Config\Admin;
use Config\Services;

/**
 * @internal
 */
final class AdminSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private const PASSWORD = 'correct horse battery';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test gets a new mock cache, but the shared throttler keeps the first one it saw,
        // so rate limits would carry over from earlier tests. Start with a fresh throttler.
        \Config\Services::resetSingle('throttler');

        $admin              = new Admin();
        $admin->requireTotp = false;
        Factories::injectMock('config', 'Admin', $admin);

        $this->userId = (int) model(UserModel::class)->insert([
            'email' => 'me@example.com', 'name' => 'Me', 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);
    }

    private function withoutCsrf(): void
    {
        config('Filters')->filters = [];
        $this->freshRequest();
    }

    /**
     * Feature-test requests share the filters and response services; a real request
     * starts with new ones. Reset them so one test request doesn't leak into the next.
     */
    private function freshRequest(): void
    {
        Services::resetSingle('filters');
        Services::resetSingle('response');
    }

    private function signIn(string $code = '', string $password = self::PASSWORD)
    {
        return $this->post('/admin/login', ['email' => 'me@example.com', 'password' => $password, 'code' => $code]);
    }

    public function testEveryApiRouteNeedsSignIn(): void
    {
        // Every route declared inside the admin/api group in Routes.php.
        $source = file_get_contents(APPPATH . 'Config/Routes.php');
        $group  = substr($source, strpos($source, "group('api'"));
        $group  = substr($group, 0, strpos($group, '});'));
        preg_match_all("#\\\$routes->(get|post|put|delete)\\('([^']+)'#", $group, $routes, PREG_SET_ORDER);
        $checked = 0;

        foreach ($routes as [, $verb, $route]) {
            $path = '/admin/api/' . str_replace(['(:num)', '(:segment)'], ['1', 'primary'], $route);
            $this->withoutCsrf();
            $result = $this->call($verb, $path);
            $this->assertSame(401, $result->response()->getStatusCode(), strtoupper($verb) . " {$path}");
            $checked++;
        }

        $this->assertGreaterThan(25, $checked);
        $this->get('/admin/')->assertRedirectTo('/admin/login');
    }

    public function testApiWritesNeedTheCsrfToken(): void
    {
        try {
            $this->withSession(['user_id' => $this->userId])->withBodyFormat('json')->put('/admin/api/settings', ['site_name' => 'Hijacked']);
            $this->fail('The request went through without a CSRF token');
        } catch (\CodeIgniter\Security\Exceptions\SecurityException $e) {
            // The framework turns this into a 403 response.
            $this->assertSame(403, $e->getCode());
        }

        $this->assertNotSame('Hijacked', model(\App\Models\SettingModel::class)->value('site_name'));
    }

    public function testSignInNeedsTheCsrfToken(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->signIn();
    }

    public function testAnAuthenticatorCodeWorksOnlyOnce(): void
    {
        $this->withoutCsrf();
        $secret = Totp::newSecret();
        model(UserModel::class)->update($this->userId, ['totp_secret' => SecretBox::seal($secret)]);
        $code = Totp::code($secret, intdiv(time(), 30));

        $this->signIn($code)->assertRedirectTo('/admin/');
        $this->assertSame(intdiv(time(), 30), (int) model(UserModel::class)->find($this->userId)['totp_last_step'], 'step recorded', 1);

        $_SESSION = [];
        $this->signIn($code)->assertRedirectTo('/admin/login');
    }

    public function testPlaintextSecretsAreEncryptedOnSignIn(): void
    {
        $this->withoutCsrf();
        $secret = Totp::newSecret();
        model(UserModel::class)->update($this->userId, ['totp_secret' => $secret]);

        $this->signIn(Totp::code($secret, intdiv(time(), 30)))->assertRedirectTo('/admin/');

        $stored = model(UserModel::class)->find($this->userId)['totp_secret'];
        $this->assertTrue(SecretBox::isSealed($stored));
        $this->assertSame($secret, SecretBox::open($stored));
    }

    public function testAccountIsThrottledAcrossAddresses(): void
    {
        $this->withoutCsrf();
        $cache = new FileHandler(config('Cache'));
        $cache->initialize();
        $cache->clean();
        Services::injectMock('throttler', new Throttler($cache));

        $from = static fn (string $ip) => service('superglobals')->setServer('REMOTE_ADDR', $ip);

        // Ten wrong passwords, each from a different address, so no address reaches its own limit.
        for ($i = 1; $i <= 10; $i++) {
            $from("198.51.100.{$i}");
            $this->signIn('', 'wrong password');
            $this->assertSame('That email, password or code is wrong.', session('login_error'), "attempt {$i} is a normal failure");
        }

        // Now even the right password, from a new address, is refused for this account.
        $from('198.51.100.99');
        $this->signIn()->assertRedirectTo('/admin/login');
        $this->assertSame('Too many attempts. Wait a few minutes and try again.', session('login_error'));

        $cache->clean();
    }

    public function testAdminPagesSendStrictHeaders(): void
    {
        $this->freshRequest();
        $result  = $this->get('/admin/login');
        $headers = $result->response();

        $this->assertStringContainsString("script-src 'self';", $headers->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $headers->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('DENY', $headers->getHeaderLine('X-Frame-Options'));
        $this->assertSame('nosniff', $headers->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $headers->getHeaderLine('Cache-Control'));
    }

    public function testPublicPagesAllowOnlyTheirOwnHeadScripts(): void
    {
        $this->freshRequest();
        $_SERVER['REQUEST_URI'] = '/';
        $result = $this->get('/');
        $csp    = $result->response()->getHeaderLine('Content-Security-Policy');
        $body   = $result->response()->getBody();

        preg_match('#<script>(.*?)</script>#s', $body, $colourMode);
        $this->assertStringContainsString("'sha256-" . base64_encode(hash('sha256', $colourMode[1], true)) . "'", $csp);
        $this->assertStringNotContainsString("'unsafe-inline' https://www.googletagmanager.com", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringNotContainsString('zkm_session', $result->response()->getHeaderLine('Set-Cookie'), 'public pages start no session');
    }
}
