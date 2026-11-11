<?php

declare(strict_types=1);

namespace Mshahara\Payroll;

use Mshahara\Payroll\Auth\Auth;
use Mshahara\Payroll\Auth\Invitations;
use Mshahara\Payroll\Auth\Resets;
use Mshahara\Payroll\Authz\Gate;
use Mshahara\Payroll\Companies\Companies;
use Mshahara\Payroll\Http\Request;
use Mshahara\Payroll\Http\Router;
use Mshahara\Payroll\Support\Audit;
use Mshahara\Payroll\Support\Csrf;
use Mshahara\Payroll\Support\Db;
use Mshahara\Payroll\Support\Env;
use Mshahara\Payroll\Support\FileMailer;
use Mshahara\Payroll\Support\Flash;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Mailer;
use Mshahara\Payroll\Support\RateLimiter;
use Mshahara\Payroll\Support\Session;
use PDO;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class App
{
    /** @var array<string,mixed> */
    public array $config;
    public PDO $db;
    public Environment $twig;
    public Router $router;
    public Auth $auth;
    public Invitations $invitations;
    public Resets $resets;
    public Gate $gate;
    public Companies $companies;
    public Audit $audit;
    public RateLimiter $limiter;
    public Mailer $mailer;
    public Session $session;
    public Csrf $csrf;
    public Flash $flash;
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
        Env::load($root . '/.env');

        $config = require $root . '/config/app.php';
        $config['mail']['path'] = $root . '/storage/mail';

        $app = new self();
        $app->root = $root;
        $app->config = $config;
        $app->request = $request ?? Request::capture();

        $app->db = Db::connect($config['db']);
        $app->session = Session::start($config['session'], $app->request->isSecure(), $app->db);
        $app->csrf = new Csrf($app->session);
        $app->flash = new Flash($app->session);
        $app->audit = new Audit($app->db, $app->request);
        $app->limiter = new RateLimiter($app->db);
        $app->mailer = new FileMailer($config['mail']['path'], (string) $config['mail']['from']);
        $app->auth = new Auth($app->db, $config, $app->session, $app->csrf);
        $app->companies = new Companies($app->db, $app->session);
        $app->gate = new Gate(require $root . '/config/permissions.php');
        $app->invitations = new Invitations($app->db, $app->mailer, $app->audit, (string) $config['url']);
        $app->resets = new Resets($app->db, $app->mailer, $app->audit, (string) $config['url']);
        $app->router = new Router();
        $app->modules = require $root . '/config/modules.php';

        $loader = new FilesystemLoader($root . '/templates');
        $twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => $app->csrf->token()));
        $twig->addFunction(new TwigFunction('can', static fn (string $permission): bool => $app->can($permission)));
        $app->twig = $twig;

        return $app;
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
        $this->user = $this->auth->user() ?? [];

        if ($this->user === []) {
            $this->memberships = [];
            $this->company = null;

            return;
        }

        $this->memberships = $this->companies->membershipsFor((int) $this->user['id']);
        $this->company = $this->companies->active((int) $this->user['id'], $this->memberships);
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
        return $this->gate->allows($this->company['role'] ?? null, $permission);
    }

    public function requirePermission(string $permission): void
    {
        $this->requireCompany();

        if (!$this->can($permission)) {
            $this->abort(403, 'Your role does not allow that.');
        }
    }

    public function roleLabel(string $role): string
    {
        return $this->gate->label($role);
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
