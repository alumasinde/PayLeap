<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Modules\Payroll\PayrollRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Mailer;
use Mshahara\Payroll\Support\PayslipPdf;
use PDO;

/** Generates payslip PDFs, stores them outside the web root and emails them. */
final class PayslipDeliveryService
{
    public function __construct(
        private readonly PDO $db,
        private readonly PayrollRepository $runs,
        private readonly EmployeeRepository $employees,
        private readonly Mailer $mailer,
        private readonly AuditLogger $audit,
        private readonly string $root,
    ) {
    }

    /**
     * @return array{path:string,file_ref:string,name:string,employee:array<string,mixed>,line:array<string,mixed>}
     */
    public function generate(int $companyId, int $runId, int $lineId): array
    {
        $run = $this->runs->find($companyId, $runId);

        if ($run === null) {
            throw new HttpException(404, 'That payroll run does not exist.');
        }

        $line = $this->runs->line($companyId, $runId, $lineId);

        if ($line === null) {
            throw new HttpException(404, 'That payslip does not exist.');
        }

        $employee = $this->employees->find($companyId, (int) $line['employee_id']);

        if ($employee === null) {
            throw new HttpException(404, 'That employee does not exist.');
        }

        $breakdown = json_decode((string) $line['breakdown'], true);
        $items = $this->runs->lineItems($companyId, $runId);
        $earnings = [];
        $deductions = [];

        foreach ($items as $item) {
            if ((int) $item['employee_id'] !== (int) $line['employee_id']) {
                continue;
            }

            if ($item['kind'] === 'deduction') {
                $deductions[] = ['label' => $item['label'], 'amount_cents' => (int) $item['amount_cents']];
            } elseif (in_array($item['kind'], ['earning', 'benefit', 'reimbursement'], true)) {
                $earnings[] = ['label' => $item['label'], 'amount_cents' => (int) $item['amount_cents']];
            }
        }

        $base = is_array($breakdown) && isset($breakdown['base_salary_cents'])
            ? (int) $breakdown['base_salary_cents']
            : (int) $line['gross_cents'];

        $company = $this->db->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
        $company->execute([$companyId]);
        $companyName = (string) $company->fetchColumn();

        $pdf = PayslipPdf::make([
            'company' => $companyName,
            'period' => (string) $run['period'],
            'generated_at' => gmdate('Y-m-d H:i'),
            'employee_name' => (string) $line['employee_name'],
            'employee_no' => (string) $line['employee_no'],
            'kra_pin' => (string) ($employee['kra_pin'] ?? '—'),
            'nssf_no' => (string) ($employee['nssf_number'] ?? '—'),
            'bank_name' => (string) ($employee['bank_name'] ?? '—'),
            'bank_account' => (string) ($employee['bank_account'] ?? '—'),
            'base_salary_cents' => $base,
            'gross_cents' => (int) $line['gross_cents'],
            'deductions_cents' => (int) $line['deductions_cents'],
            'net_cents' => (int) $line['net_cents'],
            'employer_nssf_cents' => (int) $line['nssf_employer_cents'],
            'employer_housing_cents' => (int) $line['housing_levy_employer_cents'],
            'employer_nita_cents' => (int) $line['nita_cents'],
            'employer_cost_cents' => (int) $line['employer_cost_cents'],
            'earnings' => $earnings,
            'deductions' => $deductions,
        ]);

        $relative = 'payslips/' . $companyId . '/' . $run['period'];
        $directory = rtrim($this->root, '/\\') . '/storage/' . $relative;

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the payslip directory.');
        }

        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $line['employee_no']) . '-' . $run['period'] . '.pdf';
        $path = $directory . '/' . $name;

        if (file_put_contents($path, $pdf) === false) {
            throw new \RuntimeException('Could not write the payslip PDF.');
        }

        return [
            'path' => $path,
            'file_ref' => $relative . '/' . $name,
            'name' => $name,
            'employee' => $employee,
            'line' => $line,
        ];
    }

    /**
     * Email one payslip and record the delivery attempt.
     *
     * @return array{status:string,error:?string}
     */
    public function email(int $companyId, int $runId, int $lineId, int $actorId): array
    {
        $slip = $this->generate($companyId, $runId, $lineId);
        $email = trim((string) ($slip['employee']['email'] ?? ''));

        if ($email === '') {
            $this->record($companyId, $lineId, null, $slip['file_ref'], 'failed', 'No email address');
            return ['status' => 'failed', 'error' => 'That employee has no email address.'];
        }

        try {
            $this->mailer->sendAttachment(
                $email,
                'Your payslip for ' . $slip['line']['period'],
                "Hello " . $slip['employee']['first_name'] . ",\n\nYour payslip for " . $slip['line']['period'] . " is attached.\n\nMshahara",
                $slip['path'],
                $slip['name'],
            );
        } catch (\Throwable $error) {
            $this->record($companyId, $lineId, $email, $slip['file_ref'], 'failed', mb_substr($error->getMessage(), 0, 255));
            $this->audit->record($companyId, $actorId, 'payslip.delivery_failed', 'payroll_line', (string) $lineId, ['email' => $email]);
            return ['status' => 'failed', 'error' => $error->getMessage()];
        }

        $this->record($companyId, $lineId, $email, $slip['file_ref'], 'sent', null);
        $this->audit->record($companyId, $actorId, 'payslip.delivered', 'payroll_line', (string) $lineId, ['email' => $email]);

        return ['status' => 'sent', 'error' => null];
    }

    /** @return array{sent:int,failed:int,skipped:int} */
    public function emailRun(int $companyId, int $runId, int $actorId): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->runs->lines($runId) as $line) {
            $employee = $this->employees->find($companyId, (int) $line['employee_id']);

            if ($employee === null || trim((string) ($employee['email'] ?? '')) === '') {
                $counts['skipped']++;
                continue;
            }

            $result = $this->email($companyId, $runId, (int) $line['id'], $actorId);
            $counts[$result['status'] === 'sent' ? 'sent' : 'failed']++;
        }

        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function deliveries(int $companyId, int $runId): array
    {
        $statement = $this->db->prepare(
            'SELECT d.*, l.employee_no, l.employee_name
             FROM payslip_deliveries d
             JOIN payroll_lines l ON l.id = d.line_id
             WHERE d.company_id = ? AND l.run_id = ?
             ORDER BY d.created_at DESC, d.id DESC',
        );
        $statement->execute([$companyId, $runId]);

        return $statement->fetchAll();
    }

    private function record(int $companyId, int $lineId, ?string $email, string $fileRef, string $status, ?string $error): void
    {
        $this->db->prepare(
            'INSERT INTO payslip_deliveries
                (company_id, line_id, channel, email, file_ref, status, error, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $companyId,
            $lineId,
            'email',
            $email,
            $fileRef,
            $status,
            $error,
            $status === 'sent' ? gmdate('Y-m-d H:i:s') : null,
        ]);
    }
}
