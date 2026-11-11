<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

/**
 * Session-bound CSRF token. Rotated on login, logout and privilege changes,
 * so a token harvested before authentication cannot be replayed after it.
 */
final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');

        if (!is_string($token) || $token === '') {
            $token = Token::generate(32);
            $this->session->put('_csrf', $token);
        }

        return $token;
    }

    public function rotate(): void
    {
        $this->session->put('_csrf', Token::generate(32));
    }

    public function check(?string $token): bool
    {
        $known = $this->session->get('_csrf');

        return is_string($token)
            && $token !== ''
            && is_string($known)
            && hash_equals($known, $token);
    }
}
