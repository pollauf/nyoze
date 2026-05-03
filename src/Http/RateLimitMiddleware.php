<?php

namespace Nyoze\Http;

use Nyoze\Domain\Result;

/**
 * Rate limiting middleware for HTTP requests.
 *
 * Limits requests by client IP for configured URI patterns.
 * Uses file-based storage to track request counts per IP.
 * Returns HTTP 429 when the limit is exceeded.
 */
class RateLimitMiddleware implements Middleware
{
    /** @var string[] URI patterns to apply rate limiting to (matched via str_contains) */
    private array $patterns;

    /** Maximum number of requests allowed within the time window */
    private int $maxAttempts;

    /** Time window in seconds */
    private int $windowSeconds;

    /** Directory for storing rate limit data files */
    private string $storageDir;

    /**
     * @param string[] $patterns     URI patterns to rate-limit (matched via str_contains on the request URI)
     * @param int      $maxAttempts  Maximum requests allowed per IP within the window
     * @param int      $windowSeconds Time window in seconds
     * @param string   $storageDir  Directory for rate limit storage files
     */
    public function __construct(
        array $patterns = [],
        int $maxAttempts = 10,
        int $windowSeconds = 60,
        string $storageDir = '',
    ) {
        $this->patterns = $patterns;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->storageDir = $storageDir ?: sys_get_temp_dir() . '/nyoze_rate_limit';
    }

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->shouldLimit($request)) {
            return $next($request);
        }

        $ip = $request->clientIp();
        $key = $this->buildKey($ip);

        if ($this->isLimitExceeded($key)) {
            return Response::fromResult(
                Result::fail('Too many requests. Please try again later.', 429)
            );
        }

        $this->increment($key);

        return $next($request);
    }

    /**
     * Check if the request URI matches any of the configured patterns.
     */
    private function shouldLimit(Request $request): bool
    {
        $uri = $request->uri();

        foreach ($this->patterns as $pattern) {
            if (str_contains($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a safe filename key from the client IP.
     */
    private function buildKey(string $ip): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $ip);
    }

    /**
     * Check if the rate limit has been exceeded for the given key.
     */
    private function isLimitExceeded(string $key): bool
    {
        $data = $this->loadData($key);

        if ($data === null) {
            return false;
        }

        $now = time();

        // If the window has expired, the limit is not exceeded
        if ($now - $data['window_start'] >= $this->windowSeconds) {
            return false;
        }

        return $data['attempts'] >= $this->maxAttempts;
    }

    /**
     * Increment the request counter for the given key.
     */
    private function increment(string $key): void
    {
        $data = $this->loadData($key);
        $now = time();

        if ($data === null || ($now - $data['window_start']) >= $this->windowSeconds) {
            // Start a new window
            $data = [
                'attempts' => 1,
                'window_start' => $now,
            ];
        } else {
            $data['attempts']++;
        }

        $this->saveData($key, $data);
    }

    /**
     * Load rate limit data for a key from file storage.
     *
     * @return array{attempts: int, window_start: int}|null
     */
    private function loadData(string $key): ?array
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = @json_decode($content, true);
        if (!is_array($data) || !isset($data['attempts'], $data['window_start'])) {
            return null;
        }

        return $data;
    }

    /**
     * Save rate limit data for a key to file storage.
     *
     * @param array{attempts: int, window_start: int} $data
     */
    private function saveData(string $key, array $data): void
    {
        $this->ensureStorageDir();
        $file = $this->getFilePath($key);
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    /**
     * Get the file path for a rate limit key.
     */
    private function getFilePath(string $key): string
    {
        return $this->storageDir . '/' . $key . '.json';
    }

    /**
     * Ensure the storage directory exists.
     */
    private function ensureStorageDir(): void
    {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }
}
