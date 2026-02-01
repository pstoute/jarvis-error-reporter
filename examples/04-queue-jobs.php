<?php

/**
 * Example: Jarvis Error Reporting in Queue Jobs
 *
 * This example demonstrates best practices for error tracking in
 * queued jobs, including retry logic, failure handling, and
 * progress tracking for long-running jobs.
 */

namespace App\Jobs;

use App\Models\User;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use StouteWebSolutions\JarvisErrorReporter\Facades\Jarvis;
use Throwable;

class SendWeeklyDigestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes
    public array $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public function __construct(
        public User $user,
        public string $periodStart,
        public string $periodEnd,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmailService $emailService): void
    {
        // Set initial context
        Jarvis::setUser($this->user->id, $this->user->email, $this->user->name);
        Jarvis::setContext([
            'job_class' => self::class,
            'job_id' => $this->job->getJobId(),
            'queue_name' => $this->queue ?? 'default',
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
        ]);

        try {
            // Gather digest data
            $digestData = $this->gatherDigestData();

            // Add data size to context
            Jarvis::setContext([
                'digest_items_count' => count($digestData['items'] ?? []),
                'digest_data_size' => strlen(json_encode($digestData)),
            ]);

            // Send email
            $emailService->sendDigest($this->user, $digestData);

            // Track successful completion
            logger()->info('Weekly digest sent successfully', [
                'user_id' => $this->user->id,
                'job_id' => $this->job->getJobId(),
            ]);

        } catch (Throwable $e) {
            // Add failure context
            Jarvis::setContext([
                'error_occurred_at' => now()->toIso8601String(),
                'is_final_attempt' => $this->attempts() >= $this->tries,
            ]);

            // Capture the error
            Jarvis::capture($e);

            // Re-throw to trigger retry logic
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Jarvis::setContext([
            'job_failed' => true,
            'final_attempt' => $this->attempts(),
            'time_in_queue' => now()->diffInSeconds($this->job->created_at ?? now()),
        ]);

        // Capture with critical severity
        Jarvis::capture($exception, [
            'severity' => 'critical',
            'requires_manual_intervention' => true,
            'fallback_action' => 'User will not receive digest email',
        ]);

        // Notify admin or log for manual follow-up
        logger()->critical('Weekly digest job permanently failed', [
            'user_id' => $this->user->id,
            'exception' => $exception->getMessage(),
        ]);
    }

    private function gatherDigestData(): array
    {
        // Implementation
        return ['items' => []];
    }
}

/**
 * Example: Batch processing job with progress tracking.
 */
class ProcessUserExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Don't retry batch jobs
    public int $timeout = 3600; // 1 hour

    public function __construct(
        public string $exportId,
        public array $userIds,
    ) {}

    public function handle(): void
    {
        $total = count($this->userIds);
        $processed = 0;
        $failed = 0;

        Jarvis::setContext([
            'job_class' => self::class,
            'export_id' => $this->exportId,
            'total_users' => $total,
            'started_at' => now()->toIso8601String(),
        ]);

        foreach ($this->userIds as $index => $userId) {
            // Update progress context
            Jarvis::setContext([
                'current_item' => $index + 1,
                'progress_percent' => round((($index + 1) / $total) * 100, 2),
                'processed_count' => $processed,
                'failed_count' => $failed,
            ]);

            try {
                $this->processUser($userId);
                $processed++;
            } catch (Throwable $e) {
                $failed++;

                // Capture individual failures but continue processing
                Jarvis::capture($e, [
                    'user_id' => $userId,
                    'batch_position' => $index + 1,
                    'will_continue' => true,
                ]);

                logger()->warning("Failed to process user in export", [
                    'export_id' => $this->exportId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Every 100 items, check if we should continue
            if (($index + 1) % 100 === 0) {
                // Prevent memory leaks
                $this->checkMemoryUsage();
            }
        }

        // Final summary
        logger()->info('Export processing completed', [
            'export_id' => $this->exportId,
            'total' => $total,
            'processed' => $processed,
            'failed' => $failed,
            'success_rate' => round(($processed / $total) * 100, 2),
        ]);
    }

    private function processUser(int $userId): void
    {
        // Implementation
    }

    private function checkMemoryUsage(): void
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitInBytes();
        $percentUsed = ($memoryUsage / $memoryLimit) * 100;

        Jarvis::setContext([
            'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'memory_percent_used' => round($percentUsed, 2),
        ]);

        if ($percentUsed > 80) {
            logger()->warning('High memory usage in export job', [
                'export_id' => $this->exportId,
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'percent_used' => round($percentUsed, 2),
            ]);
        }
    }

    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit == -1) {
            return PHP_INT_MAX;
        }

        $unit = strtoupper(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }
}

/**
 * Example: Job with external API dependency.
 */
class SyncStripeCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 60, 120, 300, 600]; // Exponential backoff

    public function __construct(
        public int $customerId,
    ) {}

    public function handle(): void
    {
        Jarvis::setContext([
            'job_class' => self::class,
            'customer_id' => $this->customerId,
            'attempt' => $this->attempts(),
        ]);

        try {
            $customer = User::findOrFail($this->customerId);

            // Attempt sync
            $result = $this->syncWithStripe($customer);

            Jarvis::setContext([
                'sync_result' => $result,
                'stripe_customer_id' => $customer->stripe_id,
            ]);

        } catch (Throwable $e) {
            $isRetriableError = $this->isRetriableError($e);
            $isLastAttempt = $this->attempts() >= $this->tries;

            Jarvis::capture($e, [
                'is_retriable' => $isRetriableError,
                'is_last_attempt' => $isLastAttempt,
                'next_retry_in_seconds' => $this->getNextRetryDelay(),
            ]);

            // Don't retry non-retriable errors
            if (!$isRetriableError) {
                $this->delete(); // Remove from queue
                return;
            }

            throw $e; // Retry
        }
    }

    private function syncWithStripe(User $customer): array
    {
        // Implementation
        return [];
    }

    private function isRetriableError(Throwable $e): bool
    {
        // Determine if this error should trigger a retry
        $message = $e->getMessage();

        // Don't retry validation or authentication errors
        if (str_contains($message, 'invalid_request_error')) {
            return false;
        }
        if (str_contains($message, 'authentication_error')) {
            return false;
        }

        // Retry network and rate limit errors
        return true;
    }

    private function getNextRetryDelay(): int
    {
        $attempt = $this->attempts();
        return $this->backoff[$attempt - 1] ?? end($this->backoff);
    }
}

/*
 * Queue Job Best Practices:
 *
 * 1. Set context in handle() method with job metadata
 * 2. Include attempt number and max attempts
 * 3. Update context during long-running operations
 * 4. Use failed() method for final cleanup and critical alerts
 * 5. Track progress for batch operations
 * 6. Monitor memory usage in long-running jobs
 * 7. Differentiate between retriable and non-retriable errors
 * 8. Include job ID and queue name for debugging
 */
