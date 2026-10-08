<?php

declare(strict_types=1);

namespace Cockpit\Http;

final class Router
{
    /** @var list<array{string, string, callable(Request): Response}> */
    private array $routes = [];

    /** @param callable(Request): Response $handler */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $allowed = true;
            if ($method !== $request->method) {
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request);
        }
        throw $allowed ? new HttpException(405, 'Methode nicht erlaubt.') : new HttpException(404, 'Nicht gefunden.');
    }
}
