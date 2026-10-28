<?php

use App\Libraries\CloudflareAccess;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\AccessTokens;

/**
 * @internal
 */
final class CloudflareAccessTest extends CIUnitTestCase
{
    private AccessTokens $tokens;
    private CloudflareAccess $access;
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new AccessTokens();
        $this->access = new CloudflareAccess('zkm', 'aud-tag');
        $this->keys   = [$this->tokens->kid => $this->tokens->publicKey];
    }

    public function testAcceptsAValidToken(): void
    {
        $claims = $this->access->verify($this->tokens->token(AccessTokens::claims()), $this->keys);

        $this->assertSame('me@example.com', $claims['email'] ?? null);
        $this->assertSame('https://zkm.cloudflareaccess.com', (new CloudflareAccess('https://zkm.cloudflareaccess.com/', 'x'))->issuer());
    }

    public function testRejectsWrongAudienceIssuerOrExpiry(): void
    {
        foreach ([['aud' => ['other-app']], ['iss' => 'https://evil.cloudflareaccess.com'], ['exp' => time() - 3600], ['iat' => time() + 3600]] as $bad) {
            $this->assertNull($this->access->verify($this->tokens->token(AccessTokens::claims($bad)), $this->keys), json_encode($bad));
        }
    }

    public function testRejectsForgedOrUnsignedTokens(): void
    {
        $other = new AccessTokens();

        // Signed by a key Cloudflare didn't publish.
        $this->assertNull($this->access->verify($other->token(AccessTokens::claims()), $this->keys));
        // An unknown key ID.
        $this->assertNull($this->access->verify($this->tokens->token(AccessTokens::claims(), ['kid' => 'nope']), $this->keys));
        // "alg": "none" with no signature.
        $none = CloudflareAccess::base64UrlEncode('{"alg":"none","kid":"test-key"}') . '.' . CloudflareAccess::base64UrlEncode(json_encode(AccessTokens::claims())) . '.';
        $this->assertNull($this->access->verify($none, $this->keys));
        // A valid token with its claims swapped for others.
        [$h, , $s] = explode('.', $this->tokens->token(AccessTokens::claims()));
        $this->assertNull($this->access->verify($h . '.' . CloudflareAccess::base64UrlEncode(json_encode(AccessTokens::claims(['email' => 'evil@example.com']))) . '.' . $s, $this->keys));
        $this->assertNull($this->access->verify('not-a-token', $this->keys));
    }
}
