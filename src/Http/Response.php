<?php

namespace Nyoze\Http;

use Nyoze\Domain\Result;

class Response
{
    private int $statusCode = 200;
    private array $headers  = [];
    private mixed $body     = null;
    private ?string $preparedBody = null;

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function json(mixed $data): self
    {
        $this->body = $data;
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $this;
    }

    /**
     * Build a Response from a Result object.
     */
    public static function fromResult(Result $result): self
    {
        $response = new self();

        if ($result->redirect !== null) {
            $response->status($result->status);
            $response->header('Location', $result->redirect);
            return $response;
        }

        $response->status($result->status);
        $response->json($result->toArray());
        return $response;
    }

    /**
     * Prepare the response for sending: serialize body to JSON and compute
     * Content-Length. After calling this, use getPreparedBody() and
     * getHeaders() to inspect the result without triggering output or exit.
     */
    public function prepare(): self
    {
        if ($this->body !== null) {
            $safe = self::bigintToString($this->body);
            $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                $this->statusCode = 500;
                $json = json_encode(['success' => false, 'error' => 'JSON encoding failed: ' . json_last_error_msg()]);
            }
            $this->preparedBody = $json;
            $this->headers['Content-Length'] = (string) strlen($json);
        } else {
            $this->preparedBody = null;
        }

        return $this;
    }

    /**
     * Send the response to the client (headers + body) and terminate.
     *
     * This is the only place that should call echo/exit. It is meant to be
     * called exclusively from the application entry point (index.php / App::run()).
     */
    public function send(): never
    {
        $this->prepare();

        // Clean any previous output
        while (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($this->preparedBody !== null) {
            echo $this->preparedBody;
        }

        exit;
    }

    /**
     * Recursively convert integers exceeding JS MAX_SAFE_INTEGER to strings.
     */
    private static function bigintToString(mixed $data): mixed
    {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = self::bigintToString($v);
            }
            return $data;
        }

        if (is_int($data) && ($data > 9007199254740991 || $data < -9007199254740991)) {
            return (string) $data;
        }

        return $data;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    /**
     * Return all response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Return the serialized body string after prepare() has been called.
     * Returns null if prepare() has not been called or the body was null.
     */
    public function getPreparedBody(): ?string
    {
        return $this->preparedBody;
    }
}
