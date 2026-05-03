<?php

namespace Nyoze\Core;

use Closure;

class Kernel
{
    private App $app;

    private function __construct(App $app)
    {
        $this->app = $app;
    }

    public static function load(Closure $fn): self
    {
        $app = new App();
        $fn($app);
        return new self($app);
    }

    public function app(): App
    {
        return $this->app;
    }
}
