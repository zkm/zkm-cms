<?php

namespace Tests\Support;

use App\Libraries\CloudflareAccess;

/**
 * Signs Cloudflare Access style tokens with a throwaway RSA key, for tests.
 */
final class AccessTokens
{
    public string $publicKey;
    private \OpenSSLAsymmetricKey $privateKey;

    public function __construct(public readonly string $kid = 'test-key')
    {
        $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->publicKey  = openssl_pkey_get_details($this->privateKey)['key'];
    }

    public function token(array $claims, array $header = []): string
    {
        $header += ['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT'];
        $signed = CloudflareAccess::base64UrlEncode(json_encode($header)) . '.' . CloudflareAccess::base64UrlEncode(json_encode($claims));
        openssl_sign($signed, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signed . '.' . CloudflareAccess::base64UrlEncode($signature);
    }

    public static function claims(array $overrides = []): array
    {
        return $overrides + [
            'iss'   => 'https://zkm.cloudflareaccess.com',
            'aud'   => ['aud-tag'],
            'email' => 'me@example.com',
            'iat'   => time() - 10,
            'exp'   => time() + 3600,
        ];
    }
}
