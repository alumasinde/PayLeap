<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

/**
 * HMAC-signed URLs, bound to a path and an expiry.
 * Available for links that must work without a session (exports, unsubscribe).
 */
final class SignedUrl
{
    public function __construct(
        private readonly string $key,
        private readonly string $baseUrl,
    ) {
    }

    /** @param array<string,scalar> $params */
    public function make(string $path, array $params = [], int $ttl = 3600): string
    {
        $params['expires'] = time() + $ttl;
        $params['signature'] = $this->sign($path, $params);

        return rtrim($this->baseUrl, '/') . $path . '?' . http_build_query($params);
    }

    /** @param array<string,mixed> $query */
    public function verify(string $path, array $query): bool
    {
        $signature = $query['signature'] ?? null;
        unset($query['signature']);

        if (!is_string($signature) || $signature === '') {
            return false;
        }

        if ((int) ($query['expires'] ?? 0) < time()) {
            return false;
        }

        return hash_equals($this->sign($path, $query), $signature);
    }

    /** @param array<string,mixed> $params */
    private function sign(string $path, array $params): string
    {
        ksort($params);

        return hash_hmac('sha256', $path . '?' . http_build_query($params), $this->key);
    }
}
