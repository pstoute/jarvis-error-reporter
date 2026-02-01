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
                Log::warning('Jarvis error report failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'project' => $this->config['project'],
                ]);

                // Retry on 5xx errors
                if ($response->serverError()) {
                    throw new \RuntimeException('Jarvis server error: ' . $response->status());
                }
            } else {
                Log::debug('Jarvis error report sent', [
                    'project' => $this->config['project'],
                    'error_hash' => $this->payload['error_hash'] ?? 'unknown',
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Jarvis error report exception', [
                'message' => $e->getMessage(),
                'project' => $this->config['project'],
            ]);
            throw $e; // Retry via queue
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Jarvis error report permanently failed', [
            'error' => $exception->getMessage(),
            'project' => $this->config['project'],
            'error_hash' => $this->payload['error_hash'] ?? 'unknown',
        ]);
    }
}
