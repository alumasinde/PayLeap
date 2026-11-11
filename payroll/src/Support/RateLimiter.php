<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

use PDO;

/**
 * Fixed-window rate limiting in MySQL, so it survives multiple PHP workers
 * without Redis. The counter increment is a single atomic upsert.
 */
final class RateLimiter
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function hit(string $bucket, string $identity, int $max, int $windowSeconds): bool
    {
        $key = hash('sha256', $bucket . '|' . strtolower($identity));
        $windowSeconds = max(1, $windowSeconds);
        $window = intdiv(time(), $windowSeconds);

        $this->db
            ->prepare('INSERT INTO rate_limits (bucket_key, window_start, hits) VALUES (?, ?, 1)
                       ON DUPLICATE KEY UPDATE hits = hits + 1')
            ->execute([$key, $window]);

        $statement = $this->db->prepare('SELECT hits FROM rate_limits WHERE bucket_key = ? AND window_start = ?');
        $statement->execute([$key, $window]);
        $hits = (int) $statement->fetchColumn();

        if (random_int(1, 100) === 1) {
            $this->db->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$window - 4]);
        }

        return $hits <= $max;
    }

    public function clear(string $bucket, string $identity): void
    {
        $key = hash('sha256', $bucket . '|' . strtolower($identity));
        $this->db->prepare('DELETE FROM rate_limits WHERE bucket_key = ?')->execute([$key]);
    }
}
