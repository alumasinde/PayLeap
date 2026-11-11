<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http;

/**
 * Small pattern router: /reset/{token} compiles to a named capture.
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9\-_]+)', $pattern) . '$#';

        $this->routes[] = [
            'method' => $method,
            'regex' => $regex,
            'handler' => $handler,
        ];
    }

    /**
     * Load a route table whose handlers are [Controller::class, 'action'].
     *
     * @param list<array{0:string,1:string,2:array{0:class-string,1:string}}> $routes
     */
    public function register(array $routes, callable $resolve): void
    {
        foreach ($routes as $route) {
            [$method, $pattern, $handler] = $route;
            [$class, $action] = $handler;

            $this->add($method, $pattern, static function (array $params) use ($resolve, $class, $action): void {
                $controller = $resolve($class);
                $controller->{$action}($params);
            });
        }
    }

    /** @param array<string,string> $params */
    public function dispatch(string $method, string $path): bool
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) === 1) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                ($route['handler'])($params);

                return true;
            }
        }

        return false;
    }
}
