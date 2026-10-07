<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable(array<string, string>, array<string, mixed>): Response|StreamedResponse|array}> */
    private array $routes = [];

    /** @param callable(array<string, string>, array<string, mixed>): Response|StreamedResponse|array $handler */
    public function get(string $path, callable $handler): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $path, 'handler' => $handler];
    }

    /** @param callable(array<string, string>, array<string, mixed>): Response|StreamedResponse|array $handler */
    public function post(string $path, callable $handler): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $path, 'handler' => $handler];
    }

    public function dispatch(string $method, string $requestUri): never
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? rawurldecode($path) : '/';
        foreach ($this->routes as $route) {
            if (strtoupper($method) !== $route['method']) {
                continue;
            }

            $names = [];
            $regex = preg_replace_callback('/\{([A-Za-z][A-Za-z0-9_]*)\}/', static function (array $match) use (&$names): string {
                $names[] = $match[1];
                return '([^/]+)';
            }, $route['pattern']);
            if (!is_string($regex) || preg_match('#^' . $regex . '$#u', $path, $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($names as $index => $name) {
                $params[$name] = $matches[$index] ?? '';
            }
            try {
                $result = ($route['handler'])($params, $_GET);
            } catch (\Throwable $exception) {
                error_log('Request failed: ' . $exception->getMessage());
                if (str_starts_with($path, '/api/')) {
                    $this->respond(503, json_encode(['error' => 'Service temporarily unavailable.'], JSON_THROW_ON_ERROR), 'application/json; charset=utf-8');
                }
                $this->respond(503, '<h1>Dịch vụ tạm thời chưa sẵn sàng</h1><p>Vui lòng tải lại sau.</p>');
            }
            if ($result instanceof StreamedResponse) {
                $result->send();
                exit;
            }
            if ($result instanceof Response) {
                $this->respond($result->status, $result->body, $result->contentType, $result->headers);
            }
            $this->respond(200, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'application/json; charset=utf-8');
        }

        if (str_starts_with($path, '/api/')) {
            $this->respond(404, json_encode(['error' => 'API endpoint not found.'], JSON_THROW_ON_ERROR), 'application/json; charset=utf-8');
        }
        $this->respond(404, '<h1>404 — Không tìm thấy trang</h1><p><a href="/">Về trang chủ</a></p>');
    }

    /** @param array<string, string> $headers */
    private function respond(int $status, string $body, string $contentType = 'text/html; charset=utf-8', array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $body;
        exit;
    }
}
