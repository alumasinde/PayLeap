<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Mshahara\Db;
use Mshahara\Paye\Calculator;
use Mshahara\RateSet;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

require dirname(__DIR__) . '/vendor/autoload.php';

$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);
set_exception_handler(static function (Throwable $error) use ($debug): void {
    error_log((string) $error);
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo $debug
        ? '<pre style="padding:20px;font:12px/1.5 monospace">' . htmlspecialchars((string) $error) . '</pre>'
        : '<div style="font:16px/1.6 system-ui;padding:48px 20px;max-width:34rem;margin:auto">'
            . '<h1 style="font-size:22px;margin:0 0 8px">Could not render this page</h1>'
            . '<p style="margin:0;color:#566674">Reload and try again.</p></div>';
});

$root = dirname(__DIR__);
$path = rtrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/') ?: '/';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8080');

// The built-in server hands static files straight back.
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

$config = Db::config($root . '/.env');
[$db, $dbError] = Db::tryConnect($config);

try {
    $rateSet = $db !== null
        ? RateSet::fromDatabase($db)
        : RateSet::fromFile($root . '/config/rates.php');
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Rate tables unavailable</h1><p>' . htmlspecialchars($error->getMessage()) . '</p>';
    exit;
}

$twig = new Environment(new FilesystemLoader($root . '/templates'), [
    'cache' => false,
    'autoescape' => 'html',
]);
$twig->addFilter(new TwigFilter('money', static fn ($v): string => number_format((float) $v, 2, '.', ',')));
$twig->addFilter(new TwigFilter('pct', static function ($v): string {
    $percent = round((float) $v * 100, 2);

    return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '%';
}));
$twig->addFilter(new TwigFilter('long_date', static fn ($iso): string => date('j F Y', (int) strtotime((string) $iso))));
$twig->addFilter(new TwigFilter('month_name', static fn ($m): string => [
    1 => 'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
][(int) $m] ?? ''));

$calculator = new Calculator();
$periods = $rateSet->periods();
$defaultPeriod = $rateSet->todayPeriod();

$modules = require $root . '/config/modules.php';
$pricing = require $root . '/config/pricing.php';
$employeeBands = ['1-10', '11-25', '26-100', '101-300', '300+'];

/**
 * Price a headcount and a set of modules from config/pricing.php.
 *
 * @param  list<string> $selected
 * @return array{band:array,selected:list<string>,monthly:?int,usd:?int,yearly:?int,saving:?int,lines:list<array>}
 */
$quoteFor = static function (int $employees, array $selected) use ($pricing, $modules): array {
    $band = $pricing['bands'][array_key_last($pricing['bands'])];

    foreach ($pricing['bands'] as $candidate) {
        if ($employees >= $candidate['min'] && ($candidate['max'] === null || $employees <= $candidate['max'])) {
            $band = $candidate;
            break;
        }
    }

    $selected = array_values(array_intersect($selected, array_keys($modules)));
    if ($selected === []) {
        $selected = ['employees', 'payroll'];
    }

    $payroll = in_array('payroll', $selected, true);
    $roundTo = max(1, (int) $pricing['currency']['round_to']);
    $shares = [];
    $totalShare = 0.0;

    foreach ($selected as $key) {
        $share = (float) ($pricing['modules'][$key] ?? $pricing['default_share']);
        if ($key === 'employees' && $payroll) {
            $share = 0.0;
        }

        $shares[$key] = $share;
        $totalShare += $share;
    }

    $bandPrice = $band['price'];
    $monthly = $bandPrice === null ? null : (int) (round($bandPrice * $totalShare / $roundTo) * $roundTo);

    $lines = [];
    foreach ($selected as $key) {
        $lines[] = [
            'key' => $key,
            'name' => $modules[$key]['name'] ?? $key,
            'amount' => $bandPrice === null ? null : (int) (round($bandPrice * $shares[$key] / $roundTo) * $roundTo),
            'included' => $shares[$key] === 0.0,
        ];
    }

    $yearly = $monthly === null
        ? null
        : (int) (round($monthly * 12 * (1 - (float) $pricing['yearly_discount']) / $roundTo) * $roundTo);

    return [
        'band' => $band,
        'selected' => $selected,
        'monthly' => $monthly,
        'usd' => $monthly === null ? null : (int) round($monthly * (float) $pricing['currency']['usd_rate']),
        'yearly' => $yearly,
        'saving' => $monthly === null ? null : $monthly * 12 - $yearly,
        'lines' => $lines,
    ];
};

$num = static function (array $input, string $key): float {
    $raw = preg_replace('/[^0-9.]/', '', (string) ($input[$key] ?? '0'));

    return $raw === '' ? 0.0 : max(0.0, (float) $raw);
};

/** @param array<string,mixed> $input */
$readInput = static function (array $input) use ($rateSet, $defaultPeriod, $num): array {
    $year = (int) ($input['year'] ?? $defaultPeriod['year']);
    $month = (int) ($input['month'] ?? $defaultPeriod['month']);

    if (!$rateSet->hasPeriod($year, $month)) {
        $year = $defaultPeriod['year'];
        $month = $defaultPeriod['month'];
    }

    return [
        'year' => $year,
        'month' => $month,
        'gross' => min(array_key_exists('gross', $input) ? $num($input, 'gross') : 100_000.0, 100_000_000),
        'options' => [
            'pension' => $num($input, 'pension'),
            'mortgage_interest' => $num($input, 'mortgage'),
            'insurance_premium' => $num($input, 'insurance'),
            'benefits_in_kind' => $num($input, 'benefits'),
            'helb' => $num($input, 'helb'),
            'sacco' => $num($input, 'sacco'),
        ],
        'rules' => $rateSet->forPeriod($year, $month),
    ];
};

/** @return list<array{label:string,note:?string,value:float,negative?:bool,subtotal?:bool}> */
$rowsFor = static function (array $slip, array $rules): array {
    $rows = [['label' => 'Basic pay', 'note' => null, 'value' => $slip['gross']]];

    if ($slip['benefits_in_kind'] > 0) {
        $rows[] = [
            'label' => 'Benefits in kind',
            'note' => 'taxed, never paid in cash',
            'value' => $slip['benefits_in_kind'],
        ];
    }

    $rows[] = [
        'label' => 'NSSF',
        'note' => 'Tier I ' . number_format($slip['nssf']['tier1'], 2, '.', ',')
            . ' + Tier II ' . number_format($slip['nssf']['tier2'], 2, '.', ','),
        'value' => $slip['nssf']['total'],
    ];
    $rows[] = [
        'label' => 'SHIF',
        'note' => rtrim(rtrim(number_format($rules['shif']['employee_rate'] * 100, 2, '.', ''), '0'), '.') . '% of gross',
        'value' => $slip['shif'],
    ];
    $rows[] = [
        'label' => 'Housing levy',
        'note' => rtrim(rtrim(number_format($rules['housing_levy']['employee_rate'] * 100, 2, '.', ''), '0'), '.') . '% of gross',
        'value' => $slip['housing_levy'],
    ];

    if ($slip['pension'] > 0) {
        $rows[] = ['label' => 'Pension', 'note' => null, 'value' => $slip['pension']];
    }
    if ($slip['mortgage_interest'] > 0) {
        $rows[] = ['label' => 'Mortgage interest', 'note' => null, 'value' => $slip['mortgage_interest']];
    }

    $rows[] = ['label' => 'Taxable pay', 'note' => null, 'value' => $slip['taxable_pay'], 'subtotal' => true];
    $rows[] = ['label' => 'Income tax', 'note' => 'on the bands', 'value' => $slip['tax_before_relief']];
    $rows[] = ['label' => 'Personal relief', 'note' => null, 'value' => $slip['personal_relief'], 'negative' => true];

    if ($slip['insurance_relief'] > 0) {
        $rows[] = ['label' => 'Insurance relief', 'note' => null, 'value' => $slip['insurance_relief'], 'negative' => true];
    }

    $rows[] = ['label' => 'P.A.Y.E.', 'note' => null, 'value' => $slip['paye'], 'subtotal' => true];

    if ($slip['insurance_premium'] > 0) {
        $rows[] = ['label' => 'Insurance premiums', 'note' => 'after tax', 'value' => $slip['insurance_premium']];
    }
    if ($slip['helb'] > 0) {
        $rows[] = ['label' => 'HELB', 'note' => 'after tax', 'value' => $slip['helb']];
    }
    if ($slip['sacco'] > 0) {
        $rows[] = ['label' => 'SACCO check-off', 'note' => 'after tax', 'value' => $slip['sacco']];
    }

    return $rows;
};

$shortNames = [
    'pension' => 'pension',
    'mortgage_interest' => 'mortgage',
    'insurance_premium' => 'insurance',
    'benefits_in_kind' => 'benefits',
    'helb' => 'helb',
    'sacco' => 'sacco',
];

$pdfUrl = static function (array $input, float $gross) use ($shortNames): string {
    $query = [
        'gross' => (string) $gross,
        'month' => (string) $input['month'],
        'year' => (string) $input['year'],
    ];

    foreach ($shortNames as $option => $param) {
        if (($input['options'][$option] ?? 0) > 0) {
            $query[$param] = (string) $input['options'][$option];
        }
    }

    return '/payslip.pdf?' . http_build_query($query);
};

/** Public shape for a ruleset: its readable name plus the parameters, no
 *  internal database key. */
$publicRuleset = static function (array $rules): array {
    $public = $rules;
    unset($public['id'], $public['label']);

    return ['name' => $rules['label']] + $public;
};

/** Drop the internal ruleset key wherever it appears in a payload. */
$withoutVersion = static function (array $data) use (&$withoutVersion): array {
    unset($data['rate_version']);

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = $withoutVersion($value);
        }
    }

    return $data;
};

/** @return array<string,mixed> */
$publicPayslip = static function (array $rules, array $slip, array $employer, int $year, int $month) use ($withoutVersion): array {
    return [
        'ruleset' => $rules['label'],
        'period' => ['year' => $year, 'month' => $month],
        'payslip' => $withoutVersion($slip),
        'employer_cost' => $withoutVersion($employer),
    ];
};

$monthsFor = static function (int $year) use ($periods): array {
    foreach ($periods as $period) {
        if ($period['year'] === $year) {
            return $period['months'];
        }
    }

    return $periods[0]['months'];
};

$periodPayload = static function (array $input) use ($periods, $monthsFor, $rateSet): array {
    return [
        'year' => $input['year'],
        'month' => $input['month'],
        'years' => array_column($periods, 'year'),
        'month_options' => $monthsFor($input['year']),
        'rules' => $input['rules'],
        'source' => $rateSet->source(),
    ];
};

// ---------------------------------------------------------------- marketing

/** A sample run for the payroll page and the public preview. */
$runFor = static function (array $rules) use ($calculator): array {
    $staff = [
        ['name' => 'Amina Otieno', 'job' => 'Product designer', 'gross' => 145_000.0, 'pension' => 0.0],
        ['name' => 'Brian Kiptoo', 'job' => 'Field supervisor', 'gross' => 62_000.0, 'pension' => 3_000.0],
        ['name' => 'Faith Wanjiku', 'job' => 'Accounts assistant', 'gross' => 88_000.0, 'pension' => 0.0],
    ];

    $totals = ['gross' => 0.0, 'net' => 0.0, 'statutory' => 0.0, 'employer' => 0.0];

    foreach ($staff as &$person) {
        $options = ['pension' => $person['pension']];
        $person['slip'] = $calculator->payslip($person['gross'], $rules, $options);
        $person['employer'] = $calculator->employerCost($person['gross'], $rules, $options);
        $person['statutory'] = $person['slip']['nssf']['total'] + $person['slip']['shif']
            + $person['slip']['housing_levy'] + $person['slip']['paye'];

        $totals['gross'] += $person['slip']['gross'];
        $totals['net'] += $person['slip']['net'];
        $totals['statutory'] += $person['statutory'];
        $totals['employer'] += $person['employer']['total_cost'];
    }
    unset($person);

    return ['people' => $staff, 'totals' => $totals];
};

if ($path === '/interest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'company' => trim((string) ($_POST['company'] ?? '')),
        'employees' => trim((string) ($_POST['employees'] ?? '')),
        'module' => trim((string) ($_POST['module'] ?? 'payroll')),
        'message' => mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 2000),
    ];
    $source = mb_substr(trim((string) ($_POST['source'] ?? 'site')), 0, 40);

    if (!isset($modules[$values['module']])) {
        $values['module'] = 'payroll';
    }
    if ($values['employees'] !== '' && !in_array($values['employees'], $employeeBands, true)) {
        $values['employees'] = '';
    }

    $errors = [];
    if (mb_strlen($values['name']) < 2) {
        $errors['name'] = 'Please give your name.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'That email does not look right.';
    }

    // Bots fill the hidden field. Accept, but store nothing.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        echo $twig->render('partials/interest-sent.twig', [
            'name' => $values['name'],
            'module_name' => $modules[$values['module']]['name'],
        ]);
        exit;
    }

    if ($errors === [] && $db === null) {
        $errors['form'] = 'Our database is offline. Email hello@mshaharacheck.co.ke and we will add you.';
    }

    if ($errors === []) {
        try {
            $db->prepare('INSERT INTO leads (name, email, company, employees, module, message, source) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $values['name'],
                    $values['email'],
                    $values['company'] !== '' ? $values['company'] : null,
                    $values['employees'] !== '' ? $values['employees'] : null,
                    $values['module'],
                    $values['message'] !== '' ? $values['message'] : null,
                    $source,
                ]);
        } catch (Throwable $error) {
            error_log('Lead insert failed: ' . $error->getMessage());
            $errors['form'] = 'We could not save that just now. Try again, or email hello@mshaharacheck.co.ke.';
        }
    }

    if ($errors === []) {
        echo $twig->render('partials/interest-sent.twig', [
            'name' => $values['name'],
            'module_name' => $modules[$values['module']]['name'],
        ]);
        exit;
    }

    echo $twig->render('partials/interest-form.twig', [
        'values' => $values,
        'errors' => $errors,
        'module_key' => $values['module'],
        'modules' => $modules,
        'employee_bands' => $employeeBands,
        'source' => $source,
    ]);
    exit;
}

if ($path === '/') {
    $input = $readInput([]);
    $slip = $calculator->payslip($input['gross'], $input['rules'], $input['options']);

    $paidBands = array_values(array_filter(
        $pricing['bands'],
        static fn (array $band): bool => $band['price'] !== null && $band['price'] > 0,
    ));

    echo $twig->render('home.twig', [
        'current' => 'home',
        'modules' => $modules,
        'pricing' => $pricing,
        'pricing_min' => $paidBands[0],
        'pricing_max' => $paidBands[array_key_last($paidBands)],
        'employee_bands' => $employeeBands,
        'hero' => [
            'slip' => $slip,
            'rows' => $rowsFor($slip, $input['rules']),
            'rules' => $input['rules'],
            'year' => $input['year'],
            'month' => $input['month'],
        ],
    ]);
    exit;
}

if ($path === '/payroll') {
    $input = $readInput([]);
    $slip = $calculator->payslip(100_000, $input['rules']);
    $run = $runFor($input['rules']);

    echo $twig->render('payroll.twig', [
        'current' => 'payroll',
        'modules' => $modules,
        'employee_bands' => $employeeBands,
        'hero' => [
            'slip' => $slip,
            'rows' => $rowsFor($slip, $input['rules']),
            'rules' => $input['rules'],
            'year' => $input['year'],
            'month' => $input['month'],
        ],
        'people' => $run['people'],
        'totals' => $run['totals'],
    ]);
    exit;
}

if ($path === '/pricing' || $path === '/pricing/quote') {
    $isQuote = $path === '/pricing/quote';

    if ($isQuote && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }

    $source = $isQuote ? $_POST : $_GET;
    $employees = max(1, min(10_000, (int) ($source['employees'] ?? 12)));
    $selected = $source['modules'] ?? ['employees', 'payroll'];
    $selected = is_array($selected) ? array_values(array_filter($selected, 'is_string')) : ['employees', 'payroll'];
    $quote = $quoteFor($employees, $selected);

    if ($isQuote) {
        echo $twig->render('partials/pricing-quote.twig', $quote);
        exit;
    }

    $bands = array_map(static function (array $band) use ($pricing): array {
        $band['usd'] = $band['price'] === null
            ? null
            : (int) round($band['price'] * (float) $pricing['currency']['usd_rate']);

        return $band;
    }, $pricing['bands']);

    echo $twig->render('pricing.twig', [
        'current' => 'pricing',
        'modules' => $modules,
        'employee_bands' => $employeeBands,
        'pricing' => $pricing,
        'bands' => $bands,
        'quote' => $quote,
        'employees' => $employees,
        'selected' => $quote['selected'],
    ]);
    exit;
}

if (preg_match('#^/modules/([a-z0-9-]+)$#', $path, $match)
    && isset($modules[$match[1]])
    && $modules[$match[1]]['path'] === $path) {
    $slug = $match[1];

    echo $twig->render('module.twig', [
        'current' => 'module',
        'module' => $modules[$slug],
        'module_key' => $slug,
        'modules' => $modules,
        'employee_bands' => $employeeBands,
    ]);
    exit;
}

// ------------------------------------------------------------------- api/v1

if (str_starts_with($path, '/api/v1')) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($path === '/api/v1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            echo json_encode([
                'name' => 'Mshahara Check API',
                'version' => 'v1',
                'documentation' => '/api',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/api/v1/rates', 'description' => 'Rate versions and selectable periods'],
                    ['method' => 'POST', 'path' => '/api/v1/payslip', 'description' => 'Gross to net plus employer cost'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($path === '/api/v1/rates' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            echo json_encode([
                'source' => $rateSet->source(),
                'periods' => $periods,
                'versions' => array_map($publicRuleset, $rateSet->all()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($path === '/api/v1/payslip' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($body)) {
                $body = $_POST;
            }

            $input = $readInput($body);
            $slip = $calculator->payslip($input['gross'], $input['rules'], $input['options']);

            echo json_encode($publicPayslip(
                $input['rules'],
                $slip,
                $calculator->employerCost($input['gross'], $input['rules'], $input['options']),
                $input['year'],
                $input['month'],
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    } catch (Throwable $error) {
        http_response_code(400);
        echo json_encode(['error' => $error->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------- api docs

if ($path === '/api' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sampleRules = $rateSet->forPeriod($defaultPeriod['year'], $defaultPeriod['month']);
    $sampleSlip = $calculator->payslip(100_000, $sampleRules);

    echo $twig->render('api.twig', [
        'base_url' => $origin . '/api/v1',
        'rates_json' => json_encode([
            'source' => $rateSet->source(),
            'periods' => $periods,
            'versions' => [$publicRuleset($rateSet->current())],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        'request_json' => json_encode([
            'gross' => 100_000,
            'year' => $defaultPeriod['year'],
            'month' => $defaultPeriod['month'],
            'pension' => 0,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        'payslip_json' => json_encode($publicPayslip(
            $sampleRules,
            $sampleSlip,
            $calculator->employerCost(100_000, $sampleRules),
            $defaultPeriod['year'],
            $defaultPeriod['month'],
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ]);
    exit;
}

// --------------------------------------------------------------------- pdf

if ($path === '/payslip.pdf' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $input = $readInput($_GET);
    $slip = $calculator->payslip($input['gross'], $input['rules'], $input['options']);

    $html = $twig->render('pdf.twig', [
        'slip' => $slip,
        'rows' => $rowsFor($slip, $input['rules']),
        'rules' => $input['rules'],
        'year' => $input['year'],
        'month' => $input['month'],
    ]);

    $dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'tempDir' => sys_get_temp_dir()]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream(sprintf('payslip-%04d-%02d.pdf', $input['year'], $input['month']), ['Attachment' => true]);
    exit;
}

// ------------------------------------------------------------ saved payslip

if (preg_match('#^/p/([a-f0-9]{12})$#', $path, $match) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($db === null) {
        http_response_code(503);
        echo '<p>This payslip was saved when the database was available. Connect MySQL to open it.</p>';
        exit;
    }

    $stmt = $db->prepare('SELECT slip FROM saved_payslips WHERE id = ?');
    $stmt->execute([$match[1]]);
    $stored = $stmt->fetchColumn();

    if ($stored === false) {
        http_response_code(404);
        echo '<p>No payslip with that id.</p>';
        exit;
    }

    $record = json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);
    echo $twig->render('saved.twig', [
        'slip' => $record['slip'],
        'rules' => $record['rules'],
        'rows' => $rowsFor($record['slip'], $record['rules']),
        'year' => (int) ($record['year'] ?? substr((string) $record['rules']['effective_from'], 0, 4)),
        'month' => (int) ($record['month'] ?? substr((string) $record['rules']['effective_from'], 5, 2)),
        'id' => $match[1],
        'origin' => $origin,
    ]);
    exit;
}

// ---------------------------------------------------------------- calculators

/** @return array<string,mixed> */
$toolContext = static function (array $source) use ($readInput, $calculator, $rowsFor, $periodPayload, $pdfUrl): array {
    $input = $readInput($source);
    $slip = $calculator->payslip($input['gross'], $input['rules'], $input['options']);

    return $periodPayload($input) + [
        'mode' => 'tool',
        'gross' => $input['gross'],
        'options' => $input['options'],
        'slip' => $slip,
        'rows' => $rowsFor($slip, $input['rules']),
        'pdf_url' => $pdfUrl($input, $slip['gross']),
    ];
};

/** @return array<string,mixed> */
$netContext = static function (array $source) use ($readInput, $calculator, $rowsFor, $periodPayload, $num): array {
    $input = $readInput(['month' => $source['month'] ?? null, 'year' => $source['year'] ?? null]);
    $target = array_key_exists('target', $source) ? min($num($source, 'target'), 50_000_000) : 100_000.0;
    $options = [
        'pension' => $num($source, 'pension'),
        'mortgage_interest' => 0.0,
        'insurance_premium' => 0.0,
        'benefits_in_kind' => 0.0,
        'helb' => 0.0,
        'sacco' => 0.0,
    ];

    $solution = $target > 0
        ? $calculator->solveGrossForNet($target, $input['rules'], $options)
        : null;

    return $periodPayload($input) + [
        'mode' => 'net',
        'target' => $target,
        'pension' => $options['pension'],
        'solution' => $solution,
        'solution_rows' => $solution !== null ? $rowsFor($solution, $input['rules']) : [],
        'pdf_url' => $solution !== null
            ? '/payslip.pdf?' . http_build_query([
                'gross' => (string) $solution['gross'],
                'month' => (string) $input['month'],
                'year' => (string) $input['year'],
                'pension' => (string) $options['pension'],
            ])
            : '#',
    ];
};

/** @return array<string,mixed> */
$compareContext = static function (array $source) use ($readInput, $calculator, $periodPayload, $num): array {
    $input = $readInput(['month' => $source['month'] ?? null, 'year' => $source['year'] ?? null]);

    $defaults = [1 => 100_000, 2 => 120_000, 3 => 0];
    $source = $source === [] ? [] : $source;

    $offers = [];
    for ($i = 1; $i <= 3; $i++) {
        $basic = array_key_exists("o{$i}b", $source)
            ? $num($source, "o{$i}b")
            : (float) $defaults[$i];
        $allowances = array_key_exists("o{$i}a", $source) ? $num($source, "o{$i}a") : 0.0;

        $gross = $basic + $allowances;
        if ($gross <= 0) {
            $offers[$i] = ['basic' => $basic, 'allowances' => $allowances, 'slip' => null];
            continue;
        }

        $slip = $calculator->payslip($gross, $input['rules']);
        $offers[$i] = ['basic' => $basic, 'allowances' => $allowances, 'slip' => $slip];
    }

    $scored = [];
    foreach ($offers as $index => $offer) {
        if ($offer['slip'] === null) {
            continue;
        }
        $scored[] = [
            'label' => chr(64 + count($scored) + 1),
            'basic' => $offer['basic'],
            'allowances' => $offer['allowances'],
            'slip' => $offer['slip'],
            'statutory' => $offer['slip']['nssf']['total'] + $offer['slip']['shif'] + $offer['slip']['housing_levy'],
        ];
    }

    $bestNet = 0.0;
    foreach ($scored as $result) {
        $bestNet = max($bestNet, $result['slip']['net']);
    }

    foreach ($scored as &$result) {
        $result['delta'] = $bestNet - $result['slip']['net'];
    }
    unset($result);

    $nets = array_map(static fn (array $result): float => (float) $result['slip']['net'], $scored);
    rsort($nets);

    return $periodPayload($input) + [
        'mode' => 'compare',
        'offers' => $offers,
        'results' => $scored,
        'best_net' => $bestNet,
        'best_margin' => count($nets) > 1 ? $nets[0] - $nets[1] : 0.0,
    ];
};

if ($path === '/preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (string) ($_POST['mode'] ?? 'tool');

    if ($mode === 'net') {
        echo $twig->render('net-result.twig', $netContext($_POST));
    } elseif ($mode === 'compare') {
        echo $twig->render('compare-result.twig', $compareContext($_POST));
    } else {
        echo $twig->render('payslip.twig', $toolContext($_POST));
    }
    exit;
}

if ($path === '/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($db === null) {
        http_response_code(503);
        echo '<p class="banner">Sharing needs MySQL.</p>';
        exit;
    }

    $input = $readInput($_POST);
    $slip = $calculator->payslip($input['gross'], $input['rules'], $input['options']);

    $id = substr(bin2hex(random_bytes(8)), 0, 12);
    $db->prepare('INSERT INTO saved_payslips (id, rate_version, gross, slip) VALUES (?, ?, ?, ?)')
        ->execute([
            $id,
            $input['rules']['id'],
            $slip['gross'],
            json_encode([
                'slip' => $slip,
                'rules' => $input['rules'],
                'options' => $input['options'],
                'year' => $input['year'],
                'month' => $input['month'],
            ], JSON_THROW_ON_ERROR),
        ]);

    $shareUrl = htmlspecialchars($origin . '/p/' . $id);
    echo '<div class="saved-card">'
        . '<span>Link ready</span>'
        . '<a class="saved-link" href="/p/' . htmlspecialchars($id) . '">/p/' . htmlspecialchars($id) . '</a>'
        . '<button type="button" class="btn-ghost" data-copy="' . $shareUrl . '">Copy</button>'
        . '</div>';
    exit;
}

if ($path === '/net-to-gross') {
    echo $twig->render('net.twig', $netContext($_POST !== [] ? $_POST : $_GET));
    exit;
}

if ($path === '/compare') {
    echo $twig->render('compare.twig', $compareContext($_POST !== [] ? $_POST : $_GET));
    exit;
}

if ($path === '/paye-calculator' || $path === '/preview') {
    echo $twig->render('calculator.twig', $toolContext($_POST !== [] ? $_POST : $_GET));
    exit;
}

http_response_code(404);
echo '<p>Not found.</p>';
