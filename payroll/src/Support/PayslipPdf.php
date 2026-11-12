<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

/**
 * Small, dependency-free PDF writer for payslips. It uses the standard
 * Helvetica fonts, so it works on any PHP install without a PDF package.
 */
final class PayslipPdf
{
    /** @param array<string,mixed> $data */
    public static function make(array $data): string
    {
        $lines = [];
        $y = 800;
        $text = static function (string $value, int $size = 10, bool $bold = false, int $indent = 50) use (&$lines, &$y): void {
            $font = $bold ? 'F2' : 'F1';
            $lines[] = sprintf(
                'BT /%s %d Tf %d %d Td (%s) Tj ET',
                $font,
                $size,
                $indent,
                $y,
                self::escape($value),
            );
            $y -= $size + 6;
        };

        $money = static fn (int|float $cents): string => 'KES ' . number_format(((int) $cents) / 100, 2);

        $text((string) ($data['company'] ?? 'Company'), 16, true);
        $text('Payslip · ' . (string) ($data['period'] ?? ''), 11);
        $text('Generated ' . (string) ($data['generated_at'] ?? gmdate('Y-m-d H:i')) . ' UTC', 8);
        $y -= 10;

        $text((string) ($data['employee_name'] ?? ''), 12, true);
        $text('Employee no. ' . (string) ($data['employee_no'] ?? ''), 9);
        $text('KRA PIN ' . (string) ($data['kra_pin'] ?? '—') . '   NSSF ' . (string) ($data['nssf_no'] ?? '—'), 9);
        $text('Bank ' . (string) ($data['bank_name'] ?? '—') . '   Account ' . (string) ($data['bank_account'] ?? '—'), 9);
        $y -= 8;

        $text('EARNINGS', 10, true);
        $text('Basic salary', 9);
        $text($money($data['base_salary_cents'] ?? 0), 9, false, 430);
        $y += 15;

        foreach (($data['earnings'] ?? []) as $item) {
            $text((string) $item['label'], 9);
            $text($money((int) $item['amount_cents']), 9, false, 430);
            $y += 15;
        }

        $text('Gross pay', 9, true);
        $text($money($data['gross_cents'] ?? 0), 9, true, 430);
        $y += 15;
        $y -= 8;

        $text('DEDUCTIONS', 10, true);

        foreach (($data['deductions'] ?? []) as $item) {
            $text((string) $item['label'], 9);
            $text($money((int) $item['amount_cents']), 9, false, 430);
            $y += 15;
        }

        $text('Total deductions', 9, true);
        $text($money($data['deductions_cents'] ?? 0), 9, true, 430);
        $y += 15;
        $y -= 8;

        $text('NET PAY', 12, true);
        $text($money($data['net_cents'] ?? 0), 12, true, 430);
        $y += 18;
        $y -= 8;

        $text('EMPLOYER CONTRIBUTIONS', 10, true);
        $text('NSSF ' . $money($data['employer_nssf_cents'] ?? 0) . '   Housing ' . $money($data['employer_housing_cents'] ?? 0) . '   NITA ' . $money($data['employer_nita_cents'] ?? 0), 8);
        $text('Employer cost ' . $money($data['employer_cost_cents'] ?? 0), 9, true);

        $content = implode("\n", $lines);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    private static function escape(string $value): string
    {
        $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
