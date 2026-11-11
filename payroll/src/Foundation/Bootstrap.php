<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Foundation;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Auth\AuthenticationService;
use Mshahara\Payroll\Auth\InvitationRepository;
use Mshahara\Payroll\Auth\InvitationService;
use Mshahara\Payroll\Auth\PasswordResetService;
use Mshahara\Payroll\Authz\PermissionGate;
use Mshahara\Payroll\Authz\PermissionRepository;
use Mshahara\Payroll\Authz\RoleRepository;
use Mshahara\Payroll\Authz\RoleService;
use Mshahara\Payroll\Companies\CompanyRepository;
use Mshahara\Payroll\Companies\CompanyService;
use Mshahara\Payroll\Companies\MembershipService;
use Mshahara\Payroll\Http\Request;
use Mshahara\Payroll\Http\Router;
use Mshahara\Payroll\Modules\Employees\DepartmentRepository;
use Mshahara\Payroll\Modules\Employees\EmployeeModule;
use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Modules\Employees\EmployeeService;
use Mshahara\Payroll\Modules\ModuleRepository;
use Mshahara\Payroll\Modules\Attendance\AttendanceModule;
use Mshahara\Payroll\Modules\Attendance\AttendanceRepository;
use Mshahara\Payroll\Modules\Attendance\AttendanceService;
use Mshahara\Payroll\Modules\Attendance\DeviceRepository;
use Mshahara\Payroll\Modules\Attendance\DeviceService;
use Mshahara\Payroll\Modules\Attendance\HolidayRepository;
use Mshahara\Payroll\Modules\Attendance\HolidayService;
use Mshahara\Payroll\Modules\Attendance\ShiftRepository;
use Mshahara\Payroll\Modules\Attendance\ShiftService;
use Mshahara\Payroll\Modules\Attendance\TimesheetService;
use Mshahara\Payroll\Modules\Dashboard\DashboardRepository;
use Mshahara\Payroll\Modules\Leave\LeaveModule;
use Mshahara\Payroll\Modules\Leave\LeaveRepository;
use Mshahara\Payroll\Modules\Leave\LeaveService;
use Mshahara\Payroll\Modules\Payroll\PayrollModule;
use Mshahara\Payroll\Modules\Payroll\PayrollRepository;
use Mshahara\Payroll\Modules\Payroll\PayrollService;
use Mshahara\Payroll\Modules\Payroll\RateVersionRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\CsrfGuard;
use Mshahara\Payroll\Support\Db;
use Mshahara\Payroll\Support\Env;
use Mshahara\Payroll\Support\FileMailer;
use Mshahara\Payroll\Support\FlashBag;
use Mshahara\Payroll\Support\Money;
use Mshahara\Payroll\Support\RateLimiter;
use Mshahara\Payroll\Support\SessionManager;
use Mshahara\Payroll\Support\SmtpMailer;
use Mshahara\Payroll\Users\AccountService;
use Mshahara\Paye\Calculator;
use Mshahara\Payroll\Users\UserRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Composition root: reads configuration, opens the connection, builds every
 * repository and service once, and hands back the App request context.
 */
final class Bootstrap
{
    public static function create(string $root, ?Request $request = null): App
    {
        Env::load($root . '/.env');

        $config = require $root . '/config/app.php';
        $config['mail']['path'] = $root . '/storage/mail';
        date_default_timezone_set((string) $config['timezone']);

        $app = new App();
        $app->root = $root;
        $app->config = $config;
        $app->request = $request ?? Request::capture();

        $app->db = Db::connect($config['db']);
        $app->session = SessionManager::start($config['session'], $app->request->isSecure(), $app->db);
        $app->csrf = new CsrfGuard($app->session);
        $app->flash = new FlashBag($app->session);
        $app->auditLogger = new AuditLogger($app->db, $app->request);
        $app->limiter = new RateLimiter($app->db);
        $app->mailer = $config['mail']['driver'] === 'smtp'
            ? new SmtpMailer(
                (string) $config['mail']['host'],
                (int) $config['mail']['port'],
                (string) $config['mail']['username'],
                (string) $config['mail']['password'],
                (string) $config['mail']['encryption'],
                (string) $config['mail']['from'],
            )
            : new FileMailer($config['mail']['path'], (string) $config['mail']['from']);

        $app->authentication = new AuthenticationService($app->db, $config, $app->session, $app->csrf);
        $app->companyRepository = new CompanyRepository($app->db, $app->session);
        $app->roleRepository = new RoleRepository($app->db);
        $app->permissionRepository = new PermissionRepository($app->db);
        $app->roleService = new RoleService($app->db, $app->roleRepository, $app->permissionRepository, $app->auditLogger);
        $app->userRepository = new UserRepository($app->db);
        $app->accountService = new AccountService($app->userRepository, $app->auditLogger);
        $app->permissionGate = new PermissionGate($app->db);
        $leaveRepository = new LeaveRepository($app->db);
        $shiftRepository = new ShiftRepository($app->db);
        $holidayRepository = new HolidayRepository($app->db);
        $deviceRepository = new DeviceRepository($app->db);
        $app->companyService = new CompanyService(
            $app->db,
            $app->companyRepository,
            $app->roleRepository,
            $leaveRepository,
            $shiftRepository,
            $holidayRepository,
            $app->auditLogger,
        );
        $app->membershipService = new MembershipService($app->db, $app->companyRepository, $app->roleRepository, $app->auditLogger);
        $app->moduleRepository = new ModuleRepository($app->db);
        $app->dashboard = new DashboardRepository($app->db);
        $employeeRepository = new EmployeeRepository($app->db);
        $departmentRepository = new DepartmentRepository($app->db);
        $app->employees = new EmployeeModule(
            $employeeRepository,
            $departmentRepository,
            new EmployeeService($app->db, $employeeRepository, $departmentRepository, $app->auditLogger),
        );
        $app->leave = new LeaveModule(
            $leaveRepository,
            new LeaveService($leaveRepository, $employeeRepository, $app->auditLogger),
        );
        $attendanceRepository = new AttendanceRepository($app->db);
        $app->attendance = new AttendanceModule(
            $attendanceRepository,
            new AttendanceService($attendanceRepository, $employeeRepository, $app->auditLogger),
            $shiftRepository,
            new ShiftService($shiftRepository, $employeeRepository, $app->auditLogger),
            new TimesheetService($attendanceRepository, $shiftRepository, $leaveRepository, $holidayRepository),
            $holidayRepository,
            new HolidayService($holidayRepository, $app->auditLogger),
            $deviceRepository,
            new DeviceService($deviceRepository, $app->auditLogger),
        );
        $payrollRepository = new PayrollRepository($app->db);
        $rateVersionRepository = new RateVersionRepository($app->db);
        $app->payroll = new PayrollModule(
            $payrollRepository,
            $rateVersionRepository,
            new PayrollService(
                $app->db,
                $payrollRepository,
                $employeeRepository,
                $rateVersionRepository,
                $app->auditLogger,
                new Calculator(),
            ),
        );
        $app->invitationService = new InvitationService(
            $app->db,
            new InvitationRepository($app->db),
            $app->mailer,
            $app->auditLogger,
            (string) $config['url'],
            $app->roleRepository,
        );
        $app->passwordResetService = new PasswordResetService($app->db, $app->mailer, $app->auditLogger, (string) $config['url']);
        $app->router = new Router();
        $app->modules = $app->moduleRepository->all();

        $loader = new FilesystemLoader($root . '/templates');
        $twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => $app->csrf->token()));
        $twig->addFunction(new TwigFunction('can', static fn (string $permission): bool => $app->can($permission)));
        $twig->addFunction(new TwigFunction('pending_approvals', static function () use ($app): int {
            if ($app->company === null) {
                return 0;
            }

            $canAll = $app->can('leave.approve.all');
            $canTeam = $app->can('leave.approve.team');
            $canLeave = $app->can('leave.view') || $canAll || $canTeam;
            $scope = $canLeave && $app->user !== []
                ? $app->leave->service->scopeFor((int) $app->company['company_id'], (int) $app->user['id'], $canAll, $canTeam)
                : null;

            return $app->dashboard->pendingApprovalCount(
                (int) $app->company['company_id'],
                $canLeave,
                $app->can('payroll.view'),
                $scope,
            );
        }));
        $twig->addFilter(new TwigFilter('money', static function (mixed $cents): string {
            return Money::format(is_numeric($cents) ? (int) $cents : null);
        }));
        $app->twig = $twig;

        return $app;
    }
}
