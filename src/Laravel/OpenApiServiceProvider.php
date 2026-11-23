<?php

declare(strict_types=1);

namespace Cosmira\Conform\Laravel;

use Cosmira\Conform\OpenApi;
use Illuminate\Support\ServiceProvider;

class OpenApiServiceProvider extends ServiceProvider
{
    /**
     * Register configuration defaults and a lazily loaded OpenAPI validator.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/conform.php', 'conform');

        $this->app->singleton(
            OpenApi::class,
            static fn (): OpenApi => OpenApi::from((string) config('conform.path'))
                ->basePath((string) config('conform.base_path', 'api')),
        );
    }

    /**
     * Register the publishable configuration file for Artisan.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/conform.php' => config_path('conform.php'),
            ], 'conform-config');
        }
    }
}
