<?php

declare(strict_types=1);

namespace Cosmira\Conform\Laravel;

use Cosmira\Conform\OpenApi;
use Illuminate\Support\ServiceProvider;

class OpenApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/openapi.php', 'openapi');

        $this->app->singleton(OpenApi::class, function () {
            return OpenApi::from((string) config('openapi.path'))
                ->basePath((string) config('openapi.base_path', ''));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/openapi.php' => config_path('openapi.php'),
            ], 'openapi-config');
        }
    }
}
