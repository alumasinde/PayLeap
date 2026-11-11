<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Auth;

/**
 * Password policy and hashing in one place.
 * Argon2id where PHP was built with it, bcrypt otherwise.
 */
final class Passwords
{
    public const MIN_LENGTH = 10;
    private const MAX_LENGTH = 200;

    public static function algo(): int|string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $plain): string
    {
        return password_hash($plain, self::algo());
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    public static function error(string $plain, string $confirmation): ?string
    {
        if (mb_strlen($plain) < self::MIN_LENGTH) {
            return 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }

        if (mb_strlen($plain) > self::MAX_LENGTH) {
            return 'That password is too long.';
        }

        if (!hash_equals($plain, $confirmation)) {
            return 'The two passwords do not match.';
        }

        return null;
    }
}
