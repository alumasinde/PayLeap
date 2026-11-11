<?php

declare(strict_types=1);

use Mshahara\Db;
use Mshahara\Paye\Calculator;
use Mshahara\RateSet;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$path = rtrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/') ?: '/';

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
$defaultPeriod = $rateSet->defaultPeriod();
$periods = $rateSet->periods();

$num = static function (array $input, string $key): float {
    $raw = preg_replace('/[^0-9.]/', '', (string) ($input[$key] ?? '0'));

    return $raw === '' ? 0.0 : max(0.0, (float) $raw);
};

/**
 * Everything the form submitted, validated.
 *
 * @param array<string,mixed> $input
 * @return array{year:int,month:int,gross:float,options:array<string,float>,rules:array<string,mixed>}
 */
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
        // A first visit opens on the headline example; a cleared field is zero.
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

/**
 * The rows of the results sheet, in the order payroll applies them.
 *
 * @param array<string,mixed> $slip
 * @param array<string,mixed> $rules
 * @return list<array{label:string,note:?string,value:float,negative?:bool,subtotal?:bool}>
 */
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

// ------------------------------------------------------------------ api/v1

if (str_starts_with($path, '/api/v1/')) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($path === '/api/v1/rates' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            echo json_encode([
                'source' => $rateSet->source(),
                'periods' => $periods,
                'versions' => $rateSet->all(),
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

            echo json_encode([
                'rate_version' => $input['rules']['id'],
                'period' => ['year' => $input['year'], 'month' => $input['month']],
                'payslip' => $slip,
                'employer_cost' => $calculator->employerCost($input['gross'], $input['rules'], $input['options']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
        'id' => $match[1],
    ]);
    exit;
}

// -------------------------------------------------------------- calculator

$input = $readInput($_POST !== [] ? $_POST : $_GET);
$slip = $calculator->payslip($input['gross'], $input['rules'], $input['options']);
$rows = $rowsFor($slip, $input['rules']);

$monthOptions = $periods[array_search($input['year'], array_column($periods, 'year'), true)]['months']
    ?? array_column($periods, 'months')[0];

$context = [
    'year' => $input['year'],
    'month' => $input['month'],
    'years' => array_column($periods, 'year'),
    'month_options' => $monthOptions,
    'gross' => $input['gross'],
    'options' => $input['options'],
    'rules' => $input['rules'],
    'slip' => $slip,
    'rows' => $rows,
    'source' => $rateSet->source(),
    'db_error' => $dbError,
];

if ($path === '/preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === '') {
        // A plain form post still works: render the whole page.
        echo $twig->render('calculator.twig', $context);
        exit;
    }

    echo $twig->render('payslip.twig', $context);
    exit;
}

if ($path === '/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($db === null) {
        http_response_code(503);
        echo '<p class="note">Saving needs MySQL. Set up <code>php/.env</code> and run the migration.</p>';
        exit;
    }

    $id = substr(bin2hex(random_bytes(8)), 0, 12);
    $stmt = $db->prepare(
        'INSERT INTO saved_payslips (id, rate_version, gross, slip) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $input['rules']['id'],
        $slip['gross'],
        json_encode(
            ['slip' => $slip, 'rules' => $input['rules'], 'options' => $input['options']],
            JSON_THROW_ON_ERROR,
        ),
    ]);

    $safeId = htmlspecialchars($id);
    echo '<p class="saved">Saved with the ' . htmlspecialchars($input['rules']['id'])
        . ' rules. Share it: <a href="/p/' . $safeId . '">/p/' . $safeId . '</a></p>';
    exit;
}

if ($path === '/' || $path === '/preview') {
    echo $twig->render('calculator.twig', $context);
    exit;
}

http_response_code(404);
echo '<p>Not found.</p>';
