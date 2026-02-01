<?php

namespace StouteWebSolutions\JarvisErrorReporter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendJarvisReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public array $backoff = [5, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected array $payload,
        protected array $config
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $response = Http::timeout($this->config['timeout'])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Jarvis-Project' => $this->config['project'] ?? 'unknown',
                ])
                ->post($this->config['dsn'], $this->payload);

            if (!$response->successful()) {
                Log::warning('Jarvis error report failed. Verify JARVIS_DSN is correct and n8n webhook is accessible.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'project' => $this->config['project'],
                    'dsn' => parse_url($this->config['dsn'], PHP_URL_HOST),
                    'error_hash' => $this->payload['error_hash'] ?? 'unknown',
                    'troubleshooting' => 'Check n8n webhook logs and verify network connectivity',
                ]);

                // Retry on 5xx errors
                if ($response->serverError()) {
                    throw new \RuntimeException(
                        'Jarvis n8n webhook returned server error ' . $response->status() .
                        '. This job will be retried. Check n8n instance health.'
                    );
                }
            } else {
                Log::debug('Jarvis error report sent successfully', [
                    'project' => $this->config['project'],
                    'error_hash' => $this->payload['error_hash'] ?? 'unknown',
                    'environment' => $this->payload['environment'] ?? 'unknown',
                    'should_autofix' => $this->payload['should_autofix'] ?? false,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Jarvis error report failed with exception. Check network connectivity and JARVIS_DSN configuration.', [
                'exception_message' => $e->getMessage(),
                'exception_class' => get_class($e),
                'project' => $this->config['project'],
                'dsn' => parse_url($this->config['dsn'], PHP_URL_HOST) ?? 'invalid',
                'timeout_config' => $this->config['timeout'] . 's',
                'troubleshooting' => [
                    'Verify n8n webhook is running and accessible',
                    'Check firewall rules between app and n8n',
                    'Confirm JARVIS_DSN URL is correct',
                    'Run: php artisan jarvis:test --sync',
                ],
            ]);
            throw $e; // Retry via queue
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Jarvis error report permanently failed after all retry attempts. Your n8n webhook did not receive this error.', [
            'failure_reason' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'project' => $this->config['project'],
            'error_hash' => $this->payload['error_hash'] ?? 'unknown',
            'original_error_class' => $this->payload['error']['class'] ?? 'unknown',
            'original_error_file' => $this->payload['error']['file'] ?? 'unknown',
            'retry_attempts' => $this->tries,
            'action_required' => [
                'Verify n8n instance is running and accessible',
                'Check JARVIS_DSN configuration in .env',
                'Review n8n webhook logs for issues',
                'Test connectivity: php artisan jarvis:test --sync',
                'Check Laravel queue worker logs',
            ],
        ]);
    }
}
