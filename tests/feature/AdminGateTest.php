<?php

use App\Libraries\CloudflareAccess;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Admin;
use Config\Services;
use Tests\Support\AccessTokens;

/**
 * Who may reach /admin at all: the IP allowlist and Cloudflare Access.
 *
 * @internal
 */
final class AdminGateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private function gate(array $settings): void
    {
        $admin = new Admin();

        foreach ($settings as $name => $value) {
            $admin->{$name} = $value;
        }

        Factories::injectMock('config', 'Admin', $admin);
        Services::resetSingle('filters');
        Services::resetSingle('response');
    }

    private function from(string $ip): void
    {
        service('superglobals')->setServer('REMOTE_ADDR', $ip);
        Services::resetSingle('request');
    }

    private function code(string $path, array $headers = []): int
    {
        Services::resetSingle('filters');
        Services::resetSingle('response');
        $_SERVER['REQUEST_URI'] = $path;

        return $this->withHeaders($headers)->get($path)->response()->getStatusCode();
    }

    public function testWithNothingConfiguredTheSignInPageIsOpen(): void
    {
        $this->gate([]);
        $this->assertSame(200, $this->code('/admin/login'));
    }

    public function testIpAllowlist(): void
    {
        $this->gate(['allowedIps' => '203.0.113.7, 2001:db8::/48']);

        $this->from('198.51.100.1');
        $this->assertSame(404, $this->code('/admin/login'));
        $this->assertSame(404, $this->code('/admin/api/posts'));
        $this->assertSame(200, $this->code('/'), 'the public site is unaffected');

        $this->from('203.0.113.7');
        $this->assertSame(200, $this->code('/admin/login'));

        $this->from('2001:db8:0:1::5');
        $this->assertSame(200, $this->code('/admin/login'));
    }

    public function testCloudflareAccessToken(): void
    {
        $this->gate(['accessTeam' => 'zkm', 'accessAud' => 'aud-tag', 'accessEmails' => 'me@example.com']);
        $tokens = new AccessTokens();
        cache()->save('cf_access_keys_' . md5((new CloudflareAccess('zkm', 'aud-tag'))->issuer()), [$tokens->kid => $tokens->publicKey], 300);

        $this->assertSame(404, $this->code('/admin/login'), 'no token');
        $this->assertSame(404, $this->code('/admin/login', ['Cf-Access-Jwt-Assertion' => (new AccessTokens())->token(AccessTokens::claims())]), 'forged token');
        $this->assertSame(404, $this->code('/admin/login', ['Cf-Access-Jwt-Assertion' => $tokens->token(AccessTokens::claims(['email' => 'someone@example.com']))]), 'not an allowed identity');
        $this->assertSame(200, $this->code('/admin/login', ['Cf-Access-Jwt-Assertion' => $tokens->token(AccessTokens::claims())]));
        $this->assertNull(cache('cf_access_refetched'), 'bad tokens with a known key ID never refetch the keys');
    }

    public function testIpHelper(): void
    {
        $this->assertTrue(\App\Filters\AdminGate::ipAllowed('10.1.2.3', ['10.0.0.0/8']));
        $this->assertFalse(\App\Filters\AdminGate::ipAllowed('11.1.2.3', ['10.0.0.0/8']));
        $this->assertTrue(\App\Filters\AdminGate::ipAllowed('192.168.1.130', ['192.168.1.128/25']));
        $this->assertFalse(\App\Filters\AdminGate::ipAllowed('192.168.1.127', ['192.168.1.128/25']));
        $this->assertFalse(\App\Filters\AdminGate::ipAllowed('::ffff:10.1.2.3', ['10.0.0.0/8']), 'IPv6 never matches an IPv4 range');
        $this->assertFalse(\App\Filters\AdminGate::ipAllowed('garbage', ['0.0.0.0/0']));
    }
}
