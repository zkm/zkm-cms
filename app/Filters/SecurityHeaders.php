<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Browser security headers for every response.
 *
 * The admin gets a strict Content-Security-Policy: only its own scripts, no framing.
 * Public pages allow the Google tag and Tag Manager, the embed players posts use, and the
 * inline scripts in <head> (the colour-mode switch and the tag snippets), allowed by hash
 * so that cached pages keep working. Inline scripts inside post content are not allowed.
 */
class SecurityHeaders implements FilterInterface
{
    private const EMBEDS = 'https://www.youtube-nocookie.com https://www.youtube.com https://player.vimeo.com https://www.strava.com';

    private const GOOGLE_SCRIPTS = 'https://www.googletagmanager.com https://*.googletagmanager.com https://www.google-analytics.com';

    private const GOOGLE_CONNECT = 'https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com https://*.g.doubleclick.net';

    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $path  = '/' . ltrim($request->getUri()->getPath(), '/');
        $admin = str_starts_with($path, '/admin');
        $type  = $response->getHeaderLine('Content-Type');

        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $response->setHeader('X-Frame-Options', $admin ? 'DENY' : 'SAMEORIGIN');

        if (ENVIRONMENT === 'production' && $request->isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        if ($type !== '' && ! str_contains($type, 'text/html')) {
            return $response;
        }

        if ($admin) {
            $response->setHeader('Cross-Origin-Opener-Policy', 'same-origin');
            $response->setHeader('Content-Security-Policy', self::policy([
                'default-src'     => "'self'",
                'script-src'      => "'self'",
                // The editor preview is an iframe with the theme's fonts and an inline <style>.
                'style-src'       => "'self' 'unsafe-inline' https://fonts.googleapis.com",
                'font-src'        => "'self' https://fonts.gstatic.com",
                'img-src'         => "'self' data: https:",
                'frame-src'       => "'self' " . self::EMBEDS,
                'connect-src'     => "'self'",
                'object-src'      => "'none'",
                'base-uri'        => "'none'",
                'form-action'     => "'self'",
                'frame-ancestors' => "'none'",
            ]));

            return $response;
        }

        $response->setHeader('Content-Security-Policy', self::policy([
            'default-src'     => "'self'",
            'script-src'      => trim("'self' " . self::GOOGLE_SCRIPTS . ' ' . implode(' ', self::headScriptHashes((string) $response->getBody()))),
            'style-src'       => "'self' 'unsafe-inline' https://fonts.googleapis.com",
            'font-src'        => "'self' https://fonts.gstatic.com",
            // Old posts link images from other sites.
            'img-src'         => "'self' data: https:",
            'frame-src'       => self::EMBEDS . ' https://www.googletagmanager.com',
            'connect-src'     => "'self' " . self::GOOGLE_CONNECT,
            'object-src'      => "'none'",
            'base-uri'        => "'self'",
            'form-action'     => "'self'",
            'frame-ancestors' => "'self'",
        ] + (ENVIRONMENT === 'production' ? ['upgrade-insecure-requests' => ''] : [])));

        return $response;
    }

    /**
     * 'sha256-…' sources for the inline scripts in the page's <head>.
     *
     * @return list<string>
     */
    public static function headScriptHashes(string $html): array
    {
        $end = stripos($html, '</head>');

        if ($end === false) {
            return [];
        }

        preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', substr($html, 0, $end), $matches);

        return array_values(array_unique(array_map(
            static fn (string $js) => "'sha256-" . base64_encode(hash('sha256', $js, true)) . "'",
            $matches[1]
        )));
    }

    /**
     * @param array<string, string> $directives
     */
    private static function policy(array $directives): string
    {
        $parts = [];

        foreach ($directives as $name => $value) {
            $parts[] = trim($name . ' ' . $value);
        }

        return implode('; ', $parts);
    }
}
