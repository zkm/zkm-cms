<?php

namespace App\Libraries;

/**
 * Encrypts small secrets (the TOTP seed) for storage, with the app's encryption key.
 *
 * Sealed values look like "v1:<base64>". open() also accepts a value stored
 * before encryption was added, so existing users keep working; they are
 * re-sealed on their next sign-in.
 */
class SecretBox
{
    private const PREFIX = 'v1:';

    public static function seal(string $plain): string
    {
        return self::PREFIX . base64_encode(service('encrypter')->encrypt($plain));
    }

    public static function open(string $stored): string
    {
        if (! self::isSealed($stored)) {
            return $stored;
        }

        return service('encrypter')->decrypt(base64_decode(substr($stored, strlen(self::PREFIX)), true) ?: '');
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }
}
