<?php

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Rate limits and logs need the visitor's address, which Cloudflare passes in CF-Connecting-IP.
 *
 * @internal
 */
final class ProxyIpTest extends CIUnitTestCase
{
    private function ipFor(string $remote, ?string $header): string
    {
        // CodeIgniter reads request data from the superglobals service, not $_SERVER directly.
        $globals = service('superglobals');
        $globals->setServer('REMOTE_ADDR', $remote);
        $header === null ? $globals->unsetServer('HTTP_CF_CONNECTING_IP') : $globals->setServer('HTTP_CF_CONNECTING_IP', $header);

        $config = new App();

        return (new IncomingRequest($config, new SiteURI($config), null, new UserAgent()))->getIPAddress();
    }

    public function testTrustsCloudflareHeaderFromCloudflare(): void
    {
        $this->assertSame('203.0.113.9', $this->ipFor('162.158.10.20', '203.0.113.9'));
        $this->assertSame('2001:db8::7', $this->ipFor('2606:4700::1', '2001:db8::7'));
    }

    public function testIgnoresTheHeaderFromAnyoneElse(): void
    {
        $this->assertSame('198.51.100.7', $this->ipFor('198.51.100.7', '203.0.113.9'));
    }
}
