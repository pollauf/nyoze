<?php

namespace Nyoze\Domain;

/**
 * Universal result object returned by every action.
 * Can be converted to HTTP response, CLI output, or used internally.
 */
final class Result
{
    private function __construct(
        public readonly bool   $success,
        public readonly mixed  $data    = null,
        public readonly ?string $message = null,
        public readonly int    $status  = 200,
        public readonly ?string $redirect = null,
    ) {}

    public static function ok(mixed $data = null, int $status = 200): self
    {
        return new self(success: true, data: $data, status: $status);
    }

    public static function created(mixed $data = null): self
    {
        return new self(success: true, data: $data, status: 201);
    }

    public static function noContent(): self
    {
        return new self(success: true, status: 204);
    }

    public static function fail(string $message, int $status = 400): self
    {
        return new self(success: false, message: $message, status: $status);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(success: false, message: $message, status: 404);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(success: false, message: $message, status: 403);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(success: false, message: $message, status: 401);
    }

    public static function invalid(string $message): self
    {
        return new self(success: false, message: $message, status: 422);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self(success: true, redirect: $url, status: $status);
    }

    public static function json(mixed $data): self
    {
        return new self(success: true, data: $data);
    }

    public function toArray(): array
    {
        $out = ['success' => $this->success];

        if ($this->data !== null) {
            $out['data'] = $this->data;
        }

        if ($this->message !== null) {
            $out['error'] = $this->message;
        }

        return $out;
    }
}
