<?php

declare(strict_types=1);

namespace Mshahara\Paye;

/**
 * A ruleset is data, never code: a row in rate_versions with a JSON payload.
 * Anything missing or out of shape is rejected at load time so a bad row shows
 * up as an error instead of a silently wrong payslip.
 */
final class Ruleset
{
    /** @param array<string,mixed> $rules */
    public static function assertValid(array $rules): void
    {
        $required = [
            'id', 'label', 'effective_from', 'verified_on',
            'paye', 'nssf', 'shif', 'housing_levy', 'nita', 'reliefs',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $rules)) {
                throw new \InvalidArgumentException(sprintf('Ruleset is missing "%s"', $key));
            }
        }

        if (!is_array($rules['paye']['bands'] ?? null) || $rules['paye']['bands'] === []) {
            throw new \InvalidArgumentException('Ruleset needs at least one PAYE band');
        }

        foreach ($rules['paye']['bands'] as $band) {
            if (!array_key_exists('rate', $band) || !array_key_exists('up_to', $band)) {
                throw new \InvalidArgumentException('Each PAYE band needs "rate" and "up_to"');
            }
        }
    }
}
