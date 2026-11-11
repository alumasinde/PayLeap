<?php

declare(strict_types=1);

namespace Mshahara\Payroll;

use Mshahara\Payroll\Auth\AuthenticationService;
use Mshahara\Payroll\Auth\InvitationService;
use Mshahara\Payroll\Auth\PasswordResetService;
use Mshahara\Payroll\Authz\PermissionGate;
use Mshahara\Payroll\Authz\PermissionRepository;
use Mshahara\Payroll\Authz\RoleRepository;
use Mshahara\Payroll\Authz\RoleService;
use Mshahara\Payroll\Companies\CompanyRepository;
use Mshahara\Payroll\Companies\CompanyService;
use Mshahara\Payroll\Companies\MembershipService;
use Mshahara\Payroll\Foundation\Bootstrap;
use Mshahara\Payroll\Http\Request;
use Mshahara\Payroll\Http\Router;
use Mshahara\Payroll\Modules\Employees\EmployeeModule;
use Mshahara\Payroll\Modules\ModuleRepository;
use Mshahara\Payroll\Modules\Attendance\AttendanceModule;
use Mshahara\Payroll\Modules\Dashboard\DashboardRepository;
use Mshahara\Payroll\Modules\Documents\DocumentModule;
use Mshahara\Payroll\Modules\Leave\LeaveModule;
use Mshahara\Payroll\Modules\Payroll\PayrollModule;
use Mshahara\Payroll\Users\AccountService;
use Mshahara\Payroll\Users\UserRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\CsrfGuard;
use Mshahara\Payroll\Support\FlashBag;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Mailer;
use Mshahara\Payroll\Support\RateLimiter;
use Mshahara\Payroll\Support\SessionManager;
use PDO;
use Twig\Environment;

final class App
{
    /** @var array<string,mixed> */
    public array $config;
    public PDO $db;
    public Environment $twig;
    public Router $router;
    public AuthenticationService $authentication;
    public InvitationService $invitationService;
    public PasswordResetService $passwordResetService;
    public PermissionGate $permissionGate;
    public CompanyRepository $companyRepository;
    public CompanyService $companyService;
    public MembershipService $membershipService;
    public RoleRepository $roleRepository;
    public RoleService $roleService;
    public PermissionRepository $permissionRepository;
    public UserRepository $userRepository;
    public AccountService $accountService;
    public ModuleRepository $moduleRepository;
    public EmployeeModule $employees;
    public DocumentModule $documents;
    public PayrollModule $payroll;
    public LeaveModule $leave;
    public AttendanceModule $attendance;
    public DashboardRepository $dashboard;
    public AuditLogger $auditLogger;
    public RateLimiter $limiter;
    public Mailer $mailer;
    public SessionManager $session;
    public CsrfGuard $csrf;
    public FlashBag $flash;
    public Request $request;
    public string $root;

    /** @var array<string,mixed> */
    public array $user = [];
    /** @var array<string,mixed>|null */
    public ?array $company = null;
    /** @var list<array<string,mixed>> */
    public array $memberships = [];
    /** @var array<string,array<string,string>> */
    public array $modules = [];

    /** @var array<string,object> */
    private array $controllers = [];

    public static function boot(string $root, ?Request $request = null): self
    {
        return Bootstrap::create($root, $request);
    }

    /** @param class-string $class */
    public function controller(string $class): object
    {
        if (!isset($this->controllers[$class])) {
            $this->controllers[$class] = new $class($this);
        }

        return $this->controllers[$class];
    }

    public function loadRoutes(string $file): void
    {
        $this->router->register(
            require $file,
            fn (string $class): object => $this->controller($class),
        );
    }

    public function resolveContext(): void
    {
        $this->user = $this->authentication->user() ?? [];

        if ($this->user === []) {
            $this->memberships = [];
            $this->company = null;

            return;
        }

        $this->memberships = $this->companyRepository->membershipsFor((int) $this->user['id']);
        $this->company = $this->companyRepository->active((int) $this->user['id'], $this->memberships);
    }

    /** @return array<string,mixed> */
    public function requireUser(): array
    {
        if ($this->user === []) {
            $this->redirect('/login');
        }

        return $this->user;
    }

    /** @return array<string,mixed> */
    public function requireCompany(): array
    {
        $this->requireUser();

        if ($this->company === null) {
            $this->abort(403, 'This account is not attached to a company yet. Ask an administrator for an invitation.');
        }

        return $this->company;
    }

    public function can(string $permission): bool
    {
        if ($this->user === [] || $this->company === null) {
            return false;
        }

        return $this->permissionGate->allows((int) $this->user['id'], (int) $this->company['company_id'], $permission);
    }

    public function requirePermission(string $permission): void
    {
        $this->requireCompany();

        if (!$this->can($permission)) {
            $this->abort(403, 'Your role does not allow that.');
        }
    }

    public function roleLabel(): string
    {
        $names = $this->company['role_names'] ?? '';

        return is_string($names) && $names !== '' ? $names : 'No role';
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): string
    {
        if ($status !== 200) {
            http_response_code($status);
        }

        $defaults = [
            'app_name' => $this->config['name'],
            'base_url' => $this->config['url'],
            'current_user' => $this->user === [] ? null : $this->user,
            'current_company' => $this->company,
            'memberships' => $this->memberships,
            'flashes' => $this->flash->pull(),
            'modules' => $this->modules,
            'role_label' => $this->roleLabel(),
            'current_path' => $this->request->path(),
            'current_query' => $this->request->queryString(),
            'today' => date('Y-m-d'),
        ];

        return $this->twig->render($template, $data + $defaults);
    }

    public function redirect(string $to, int $status = 302): never
    {
        header('Location: ' . $to, true, $status);
        exit;
    }

    public function abort(int $status, string $message = ''): never
    {
        throw new HttpException($status, $message);
    }

    public function errorPage(int $status, string $message = ''): void
    {
        $titles = [
            403 => 'Not allowed',
            404 => 'Not found',
            410 => 'Link no longer works',
            419 => 'Page expired',
            429 => 'Too many attempts',
            500 => 'Something went wrong',
        ];

        echo $this->render('errors/error.twig', [
            'code' => $status,
            'title' => $titles[$status] ?? 'Error',
            'message' => $message,
        ], $status);
    }
}
