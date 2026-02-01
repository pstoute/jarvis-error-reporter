<?php

namespace StouteWebSolutions\JarvisErrorReporter;

use Throwable;
use Illuminate\Contracts\Debug\ExceptionHandler;

class JarvisExceptionHandler implements ExceptionHandler
{
    public function __construct(
        protected ExceptionHandler $fallback,
        protected JarvisErrorReporter $reporter
    ) {}

    /**
     * Report or log an exception.
     */
    public function report(Throwable $e): void
    {
        // Let Laravel's handler do its thing first
        $this->fallback->report($e);

        // Then send to Jarvis
        try {
            $this->reporter->capture($e);
        } catch (Throwable $jarvisException) {
            // Don't let Jarvis errors break the app
            logger()->error('Jarvis error reporter failed to capture exception. Check JARVIS_DSN configuration and network connectivity.', [
                'jarvis_error' => $jarvisException->getMessage(),
                'jarvis_exception_class' => get_class($jarvisException),
                'original_exception' => get_class($e),
                'dsn_configured' => !empty(config('jarvis.dsn')),
                'jarvis_enabled' => config('jarvis.enabled'),
                'troubleshooting' => 'Run "php artisan jarvis:test" to diagnose configuration issues',
            ]);
        }
    }

    /**
     * Determine if the exception should be reported.
     */
    public function shouldReport(Throwable $e): bool
    {
        return $this->fallback->shouldReport($e);
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e): mixed
    {
        return $this->fallback->render($request, $e);
    }

    /**
     * Render an exception to the console.
     */
    public function renderForConsole($output, Throwable $e): void
    {
        $this->fallback->renderForConsole($output, $e);
    }
}
