<?php

declare(strict_types=1);

namespace Kuyash\Core;

use Closure;
use RuntimeException;

/**
 * Simple method+path router with {param} segments.
 * Deliberately NOT general-purpose: no middleware stack, no route groups,
 * no regex routes — add capabilities only when a phase actually needs them.
 *
 * Handlers are either a Closure or [ControllerClass::class, 'method'];
 * controller instances are resolved from the container (explicit bindings).
 */
final class Router
{
    /** @var array<string, array<string, Closure|array{0: class-string, 1: string}>> */
    private array $routes = [];

    public function __construct(
        private readonly Container $container,
        private readonly View $view,
    ) {
    }

    /** @param Closure|array{0: class-string, 1: string} $handler */
    public function get(string $path, Closure|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param Closure|array{0: class-string, 1: string} $handler */
    public function post(string $path, Closure|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @param Closure|array{0: class-string, 1: string} $handler */
    private function add(string $method, string $path, Closure|array $handler): void
    {
        $this->routes[$method][$this->normalize($path)] = $handler;
    }

    public function dispatch(string $method, string $uri): Response
    {
        $path = $this->normalize(parse_url($uri, PHP_URL_PATH) ?: '/');
        $method = strtoupper($method);

        // HEAD reuses GET handlers; the SAPI strips the body (uptime checks use HEAD)
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;
        $candidates = $this->routes[$lookupMethod] ?? [];

        foreach ($candidates as $routePath => $handler) {
            $params = $this->match($routePath, $path);
            if ($params === null) {
                continue;
            }

            return $this->invoke($handler, $params);
        }

        $allowed = $this->allowedMethods($path);
        if ($allowed !== []) {
            return $this->methodNotAllowed($allowed);
        }

        return $this->notFound();
    }

    /**
     * Methods (other routes' verbs + implied HEAD) that DO match this path —
     * non-empty means the path exists and the verb is wrong: 405, not 404.
     *
     * @return list<string>
     */
    private function allowedMethods(string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $method => $routes) {
            foreach (array_keys($routes) as $routePath) {
                if ($this->match($routePath, $path) !== null) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }
        sort($allowed);

        return $allowed;
    }

    /** @param list<string> $allowed */
    private function methodNotAllowed(array $allowed): Response
    {
        return new Response(
            '<h1>405 — Method Not Allowed</h1>',
            405,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Allow' => implode(', ', $allowed),
            ],
        );
    }

    public function notFound(): Response
    {
        return Response::html($this->view->render('errors/404', ['title' => '404 — Not Found']), 404);
    }

    /**
     * Match a registered route against a request path.
     * Returns extracted {param} values, or null when the route does not match.
     *
     * @return array<string, string>|null
     */
    private function match(string $routePath, string $requestPath): ?array
    {
        if ($routePath === $requestPath) {
            return [];
        }

        if (!str_contains($routePath, '{')) {
            return null;
        }

        $routeSegments = explode('/', $routePath);
        $requestSegments = explode('/', $requestPath);

        if (count($routeSegments) !== count($requestSegments)) {
            return null;
        }

        $params = [];
        foreach ($routeSegments as $i => $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $m) === 1) {
                if ($requestSegments[$i] === '') {
                    return null;
                }
                $params[$m[1]] = rawurldecode($requestSegments[$i]);
                continue;
            }
            if ($segment !== $requestSegments[$i]) {
                return null;
            }
        }

        return $params;
    }

    /**
     * @param Closure|array{0: class-string, 1: string} $handler
     * @param array<string, string>                     $params
     */
    private function invoke(Closure|array $handler, array $params): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($params);
        } else {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            $result = $controller->{$method}($params);
        }

        if (!$result instanceof Response) {
            throw new RuntimeException('Route handler must return a ' . Response::class);
        }

        return $result;
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path;
    }
}
