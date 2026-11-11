<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

/**
 * High-entropy tokens for invitations, resets and CSRF.
 * Stored only as SHA-256 digests; the plain token never touches the database.
 */
final class Token
{
    public static function generate(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }
}
