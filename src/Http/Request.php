<?php

namespace Nyoze\Http;

class Request
{
    private array $body;
    private array $query;
    private array $params;
    private array $headers;
    private string $method;
    private string $uri;

    public function __construct(
        string $method = 'GET',
        string $uri = '/',
        array $body = [],
        array $query = [],
        array $headers = [],
    ) {
        $this->method  = strtoupper($method);
        $this->uri     = '/' . trim(parse_url($uri, PHP_URL_PATH) ?? '/', '/');
        $this->body    = $body;
        $this->query   = $query;
        $this->headers = $headers;
        $this->params  = [];
    }

    /**
     * Capture the current HTTP request from PHP superglobals.
     */
    public static function capture(): self
    {
        $method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri     = $_SERVER['REQUEST_URI'] ?? '/';
        $headers = self::parseHeaders();

        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw  = file_get_contents('php://input');
            $body = json_decode($raw, true) ?? [];
        } else {
            $body = $_POST;
        }

        return new self($method, $uri, $body, $_GET, $headers);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Get the client IP address from the request.
     */
    public function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return $auth;
    }

    private static function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        // Apache may pass Authorization via REDIRECT_HTTP_AUTHORIZATION
        if (!isset($headers['authorization'])) {
            if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (function_exists('apache_request_headers')) {
                $apacheHeaders = apache_request_headers();
                foreach ($apacheHeaders as $k => $v) {
                    if (strtolower($k) === 'authorization') {
                        $headers['authorization'] = $v;
                        break;
                    }
                }
            }
        }
        return $headers;
    }
}
