<?php

namespace App\Filters;

use App\Libraries\CloudflareAccess;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Decides who may reach /admin at all, before the sign-in page. Each rule is off until
 * configured in Config\Admin (or .env):
 *
 * - admin.allowedIps: only these addresses or ranges (e.g. "203.0.113.7, 2001:db8::/48").
 * - admin.accessTeam + admin.accessAud: a valid Cloudflare Access token is required,
 *   optionally for one of admin.accessEmails.
 *
 * Anyone else gets the site's normal 404, so the admin doesn't announce itself.
 */
class AdminGate implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config('Admin');
        $ip     = $request->getIPAddress();

        $allowed = self::list($config->allowedIps);

        if ($allowed !== [] && ! self::ipAllowed($ip, $allowed)) {
            log_message('warning', "Admin access refused for {$ip}: not on the allowed list");

            throw PageNotFoundException::forPageNotFound();
        }

        if ($config->accessTeam !== '' && $config->accessAud !== '') {
            $claims = self::accessClaims($request, new CloudflareAccess($config->accessTeam, $config->accessAud));
            $emails = array_map('strtolower', self::list($config->accessEmails));

            if ($claims === null || ($emails !== [] && ! in_array(strtolower((string) ($claims['email'] ?? '')), $emails, true))) {
                log_message('warning', "Admin access refused for {$ip}: no valid Cloudflare Access token");

                throw PageNotFoundException::forPageNotFound();
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private static function accessClaims(RequestInterface $request, CloudflareAccess $access): ?array
    {
        $token = $request->getHeaderLine('Cf-Access-Jwt-Assertion') ?: (string) $request->getCookie('CF_Authorization');

        if ($token === '') {
            return null;
        }

        return $access->claims($token);
    }

    /**
     * @param list<string> $ranges IP addresses or CIDR ranges, IPv4 or IPv6
     */
    public static function ipAllowed(string $ip, array $ranges): bool
    {
        $address = @inet_pton($ip);

        if ($address === false) {
            return false;
        }

        foreach ($ranges as $range) {
            [$network, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
            $network          = @inet_pton($network);

            if ($network === false || strlen($network) !== strlen($address)) {
                continue;
            }

            $bits ??= strlen($address) * 8;
            $bits = max(0, min((int) $bits, strlen($address) * 8));
            $mask = str_repeat("\xff", intdiv($bits, 8)) . ($bits % 8 ? chr(0xff << (8 - $bits % 8) & 0xff) : '');
            $mask = str_pad($mask, strlen($address), "\0");

            if (($address & $mask) === ($network & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
