<?php

namespace App\Libraries;

/**
 * Time-based one-time passwords (RFC 6238: SHA-1, 6 digits, 30-second steps),
 * compatible with authenticator apps.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        $secret = '';

        foreach (str_split(random_bytes(20)) as $byte) {
            $secret .= self::ALPHABET[ord($byte) & 31];
        }

        return $secret;
    }

    public static function uri(string $secret, string $account, string $issuer = 'zkm-cms'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
    }

    /**
     * Accepts the current code and the ones either side of it, to allow for clock drift.
     */
    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== 6) {
            return false;
        }

        $step = intdiv($time ?? time(), 30);

        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals(self::code($secret, $step + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function code(string $secret, int $step): string
    {
        $hash   = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value  = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private static function decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index !== false) {
                $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
