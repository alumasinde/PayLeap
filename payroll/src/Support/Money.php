<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

/**
 * Money is integer cents everywhere. Forms speak shillings; this converts
 * between the two so no float ever reaches the database or a sum.
 */
final class Money
{
    /** Parse a typed amount ("45,000.50") into cents, or null when unusable. */
    public static function toCents(string $input): ?int
    {
        $clean = preg_replace('/[\s,]/', '', trim($input));

        if ($clean === null || $clean === '' || !preg_match('/^\d{1,12}(\.\d{1,2})?$/', $clean)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $clean), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** Convert a shilling amount from the engine into integer cents. */
    public static function fromShillings(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** Render cents for a form field or a table cell. */
    public static function format(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return number_format($cents / 100, 2);
    }
}
