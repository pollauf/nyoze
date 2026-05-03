<?php

namespace Nyoze\Http;

/**
 * Middleware interface for HTTP request processing.
 */
interface Middleware
{
    /**
     * Process the request. Return a Response to short-circuit, or null to continue.
     */
    public function handle(Request $request, \Closure $next): Response;
}
