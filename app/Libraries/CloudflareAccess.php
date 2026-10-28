<?php

namespace App\Libraries;

/**
 * Verifies the token Cloudflare Access adds to requests it lets through
 * (the Cf-Access-Jwt-Assertion header, or the CF_Authorization cookie).
 *
 * Checking it in the app means the admin stays closed even to someone who reaches
 * the server without going through Cloudflare. See
 * https://developers.cloudflare.com/cloudflare-one/identity/authorization-cookie/validating-json/
 */
class CloudflareAccess
{
    private const LEEWAY = 60;

    public function __construct(private readonly string $team, private readonly string $audience)
    {
    }

    /**
     * "zkm" and "zkm.cloudflareaccess.com" both mean https://zkm.cloudflareaccess.com.
     */
    public function issuer(): string
    {
        $host = preg_replace('#^https?://|/.*$#', '', strtolower(trim($this->team))) ?? '';

        return 'https://' . (str_contains($host, '.') ? $host : $host . '.cloudflareaccess.com');
    }

    /**
     * The token's claims if it is valid for this application, otherwise null.
     *
     * @param array<string, string> $keys Public keys (PEM) by key ID
     */
    public function verify(string $jwt, array $keys, ?int $now = null): ?array
    {
        $now ??= time();
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = array_map(self::base64UrlDecode(...), $parts);
        $header = json_decode((string) $header, true);
        $claims = json_decode((string) $payload, true);

        // Only RS256; never trust "none" or an algorithm the token chooses for itself.
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || ! isset($keys[$header['kid'] ?? ''])) {
            return null;
        }

        $key = openssl_pkey_get_public($keys[$header['kid']]);

        if ($key === false || openssl_verify($parts[0] . '.' . $parts[1], (string) $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $audiences = (array) ($claims['aud'] ?? []);

        if (($claims['iss'] ?? '') !== $this->issuer()
            || ! in_array($this->audience, $audiences, true)
            || (int) ($claims['exp'] ?? 0) < $now - self::LEEWAY
            || (int) ($claims['nbf'] ?? $claims['iat'] ?? 0) > $now + self::LEEWAY) {
            return null;
        }

        return $claims;
    }

    /**
     * The claims of a request's token, fetching the signing keys as needed.
     *
     * The keys are fetched again only when the token names a key ID we don't have
     * (Cloudflare rotated them), and at most once a minute, so bad tokens can't make
     * the server call Cloudflare on every request.
     */
    public function claims(string $jwt): ?array
    {
        $keys   = $this->keys();
        $header = json_decode(self::base64UrlDecode(explode('.', $jwt)[0]), true);
        $kid    = is_array($header) ? (string) ($header['kid'] ?? '') : '';

        if ($kid !== '' && ! isset($keys[$kid]) && cache('cf_access_refetched') === null) {
            cache()->save('cf_access_refetched', 1, MINUTE);
            $keys = $this->keys(true);
        }

        return $this->verify($jwt, $keys);
    }

    /**
     * The team's current signing keys, cached for an hour. $refresh fetches them again.
     *
     * @return array<string, string>
     */
    public function keys(bool $refresh = false): array
    {
        $cacheKey = 'cf_access_keys_' . md5($this->issuer());
        $keys     = $refresh ? null : cache($cacheKey);

        if (is_array($keys)) {
            return $keys;
        }

        $keys = [];

        try {
            $response = service('curlrequest', ['timeout' => 5, 'http_errors' => false])->get($this->issuer() . '/cdn-cgi/access/certs');
            $data     = json_decode($response->getBody(), true);

            foreach (($data['public_certs'] ?? []) as $cert) {
                if (isset($cert['kid'], $cert['cert'])) {
                    $keys[$cert['kid']] = $cert['cert'];
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Could not fetch Cloudflare Access keys: ' . $e->getMessage());
        }

        // An empty result (Cloudflare unreachable) is kept for a minute, so an outage
        // doesn't turn every admin request into another fetch.
        cache()->save($cacheKey, $keys, $keys !== [] ? HOUR : MINUTE);

        return $keys;
    }

    public static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
