<?php

declare(strict_types=1);

namespace Wappo\LaravelUtils\Providers;

use Illuminate\Support\ServiceProvider;

class LaravelUtilsServiceProvider extends ServiceProvider
{
    public function register()
    {
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Wappo\LaravelUtils\Console\Commands\MakeEntity::class,
            ]);
        }
    }
}