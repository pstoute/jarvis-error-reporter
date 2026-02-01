<?php

namespace StouteWebSolutions\JarvisErrorReporter;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;

class JarvisServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/jarvis.php', 'jarvis');

        $this->app->singleton(JarvisErrorReporter::class, function ($app) {
            return new JarvisErrorReporter(
                config('jarvis'),
                $app->make('cache.store'),
                $app->make('log')
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config file
        $this->publishes([
            __DIR__ . '/../config/jarvis.php' => config_path('jarvis.php'),
        ], 'jarvis-config');

        // Register exception handler if enabled
        if (config('jarvis.enabled') && config('jarvis.dsn')) {
            $this->registerExceptionHandler();
        }
    }

    /**
     * Wrap the exception handler to capture errors.
     */
    protected function registerExceptionHandler(): void
    {
        $this->app->extend(ExceptionHandler::class, function ($handler, $app) {
            return new JarvisExceptionHandler(
                $handler,
                $app->make(JarvisErrorReporter::class)
            );
        });
    }
}
