<?php

declare(strict_types=1);

namespace Mshahara\Payroll;

use Mshahara\Payroll\Auth\AuthenticationService;
use Mshahara\Payroll\Auth\InvitationService;
use Mshahara\Payroll\Auth\PasswordResetService;
use Mshahara\Payroll\Authz\PermissionGate;
use Mshahara\Payroll\Authz\PermissionRepository;
use Mshahara\Payroll\Authz\Portal;
use Mshahara\Payroll\Authz\PortalResolver;
use Mshahara\Payroll\Authz\RoleRepository;
use Mshahara\Payroll\Authz\RoleService;
use Mshahara\Payroll\Companies\CompanyRepository;
use Mshahara\Payroll\Companies\CompanyService;
use Mshahara\Payroll\Companies\MembershipService;
use Mshahara\Payroll\Foundation\Bootstrap;
use Mshahara\Payroll\Http\Request;
use Mshahara\Payroll\Http\Router;
use Mshahara\Payroll\Modules\Announcements\AnnouncementModule;
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
    public PortalResolver $portalResolver;
    public UserRepository $userRepository;
    public AccountService $accountService;
    public ModuleRepository $moduleRepository;
    public EmployeeModule $employees;
    public AnnouncementModule $announcements;
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
    public ?Portal $portal = null;

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

    private bool $employeeResolved = false;
    /** @var array<string,mixed>|null */
    private ?array $employeeCache = null;

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
        $this->employeeResolved = false;
        $this->employeeCache = null;

        if ($this->user === []) {
            $this->memberships = [];
            $this->company = null;
            $this->portal = null;
            $this->session->forget('portal');

            return;
        }

        $this->memberships = $this->companyRepository->membershipsFor((int) $this->user['id']);
        $this->company = $this->companyRepository->active((int) $this->user['id'], $this->memberships);

        if ($this->company === null) {
            $this->portal = null;
            $this->session->forget('portal');

            return;
        }

        $available = $this->portalResolver->available($this->permissionCheck(), $this->currentEmployee() !== null);
        $default = $this->portalResolver->resolve($this->permissionCheck(), $this->currentEmployee() !== null);
        $preferred = Portal::tryFrom((string) $this->session->get('portal', ''));

        if ($preferred !== null && !in_array($preferred, $available, true)) {
            $preferred = null;
        }

        $portal = $this->portalResolver->forPath($this->request->path(), $default, $preferred);

        if (!in_array($portal, $available, true)) {
            $portal = $default;
        }

        $this->portal = $portal;
        $this->session->put('portal', $portal->value);
    }

    /** @return \Closure(string):bool */
    private function permissionCheck(): \Closure
    {
        return fn (string $permission): bool => $this->can($permission);
    }

    /** @return list<Portal> */
    public function availablePortals(): array
    {
        if ($this->user === [] || $this->company === null) {
            return [];
        }

        return $this->portalResolver->available($this->permissionCheck(), $this->currentEmployee() !== null);
    }

    public function portalAvailable(Portal $portal): bool
    {
        return in_array($portal, $this->availablePortals(), true);
    }

    /** The portal this person lands in after signing in. */
    public function homePath(): string
    {
        if ($this->user === [] || $this->company === null) {
            return '/app';
        }

        return $this->portalResolver->resolve($this->permissionCheck(), $this->currentEmployee() !== null)->path();
    }

    /** @return list<array{value:string,label:string,path:string,active:bool}> */
    public function portalLinks(): array
    {
        $links = [];

        foreach ($this->availablePortals() as $portal) {
            $links[] = [
                'value' => $portal->value,
                'label' => $portal->short(),
                'path' => $portal->path(),
                'active' => $this->portal === $portal,
            ];
        }

        return $links;
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
            'current_employee' => $this->currentEmployee(),
            'has_workspace' => $this->hasWorkspace(),
            'portal' => $this->portal?->value,
            'portal_label' => $this->portal?->label(),
            'portals' => $this->portalLinks(),
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

    /** @return array<string,mixed>|null */
    private function currentEmployee(): ?array
    {
        if ($this->employeeResolved) {
            return $this->employeeCache;
        }

        $this->employeeResolved = true;

        if ($this->user === [] || $this->company === null) {
            return $this->employeeCache = null;
        }

        return $this->employeeCache = $this->employees->register->forUser(
            (int) $this->company['company_id'],
            (int) $this->user['id'],
        );
    }

    private function hasWorkspace(): bool
    {
        if ($this->user === [] || $this->company === null) {
            return false;
        }

        $permissions = [
            'employees.view', 'payroll.view', 'leave.view', 'leave.approve.team',
            'leave.approve.all', 'attendance.view', 'users.manage', 'users.invite',
            'roles.manage', 'audit.view',
        ];

        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
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
