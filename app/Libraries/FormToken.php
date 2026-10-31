<?php

namespace App\Libraries;

use CodeIgniter\I18n\Time;

/**
 * Proof that a contact form submission came from a browser that loaded the page, ran its
 * script and waited a moment, without a session (public pages stay cacheable).
 *
 * The page fetches a token from /contact-form/token: the issue time plus an HMAC of it.
 * Form-posting bots that never run JavaScript don't have one, and those that replay a
 * token straight away fail the minimum age.
 */
final class FormToken
{
    public const MIN_AGE = 3;      // seconds: nobody types a message faster
    public const MAX_AGE = 86400;  // a tab left open overnight still works

    public static function issue(): string
    {
        $now = (string) Time::now()->getTimestamp();

        return $now . '.' . self::sign($now);
    }

    /**
     * Null when the token is good, otherwise why it isn't: 'missing', 'invalid', 'too-fast', 'expired'.
     */
    public static function problem(string $token): ?string
    {
        if ($token === '') {
            return 'missing';
        }

        if (! preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $token, $m) || ! hash_equals(self::sign($m[1]), $m[2])) {
            return 'invalid';
        }

        $age = Time::now()->getTimestamp() - (int) $m[1];

        if ($age < self::MIN_AGE) {
            return 'too-fast';
        }

        return $age > self::MAX_AGE ? 'expired' : null;
    }

    private static function sign(string $issued): string
    {
        $key = config('Encryption')->key;

        if ($key === '') {
            throw new \RuntimeException('encryption.key must be set to sign contact form tokens.');
        }

        return hash_hmac('sha256', 'contact-form|' . $issued, $key);
    }
}
