<?php

/**
 * Example: Jarvis Error Reporting for External API Integrations
 *
 * This example shows how to add comprehensive error tracking when
 * integrating with third-party APIs, including retry logic and
 * detailed diagnostic information.
 */

namespace App\Services\Integrations;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use StouteWebSolutions\JarvisErrorReporter\Facades\Jarvis;
use Exception;

class StripeIntegration
{
    private string $apiKey;
    private int $maxRetries = 3;

    public function __construct()
    {
        $this->apiKey = config('services.stripe.secret');
    }

    /**
     * Create a Stripe customer with comprehensive error tracking.
     */
    public function createCustomer(array $data): array
    {
        Jarvis::setContext([
            'integration' => 'stripe',
            'operation' => 'create_customer',
            'api_version' => '2023-10-16',
        ]);

        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            $attempt++;

            Jarvis::setContext([
                'retry_attempt' => $attempt,
                'max_retries' => $this->maxRetries,
            ]);

            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                ])
                    ->timeout(10)
                    ->post('https://api.stripe.com/v1/customers', $data);

                if ($response->successful()) {
                    return $response->json();
                }

                // Handle specific Stripe error codes
                $this->handleStripeError($response, $attempt);

            } catch (Exception $e) {
                $isLastAttempt = $attempt >= $this->maxRetries;

                Jarvis::capture($e, [
                    'is_last_attempt' => $isLastAttempt,
                    'will_retry' => !$isLastAttempt,
                    'customer_email' => $data['email'] ?? null,
                    'api_endpoint' => 'POST /v1/customers',
                ]);

                if ($isLastAttempt) {
                    throw new Exception(
                        "Failed to create Stripe customer after {$this->maxRetries} attempts: {$e->getMessage()}",
                        0,
                        $e
                    );
                }

                // Exponential backoff
                sleep(pow(2, $attempt - 1));
            }
        }

        throw new Exception('Unexpected state in createCustomer');
    }

    /**
     * Handle Stripe-specific error responses.
     */
    private function handleStripeError(Response $response, int $attempt): void
    {
        $error = $response->json('error');
        $errorType = $error['type'] ?? 'unknown';
        $errorCode = $error['code'] ?? 'unknown';

        Jarvis::setContext([
            'stripe_error_type' => $errorType,
            'stripe_error_code' => $errorCode,
            'stripe_error_message' => $error['message'] ?? null,
            'stripe_request_id' => $response->header('Request-Id'),
            'http_status' => $response->status(),
        ]);

        // Determine if we should retry based on error type
        $shouldRetry = match ($errorType) {
            'api_error' => true,
            'rate_limit_error' => true,
            'invalid_request_error' => false, // Don't retry validation errors
            'authentication_error' => false,  // Don't retry auth errors
            default => $response->serverError(),
        };

        if (!$shouldRetry || $attempt >= $this->maxRetries) {
            Jarvis::capture(
                new Exception("Stripe API error: {$error['message']}"),
                [
                    'should_retry' => $shouldRetry,
                    'retry_exhausted' => $attempt >= $this->maxRetries,
                ]
            );

            throw new Exception(
                "Stripe error [{$errorCode}]: {$error['message']}"
            );
        }

        // Rate limit specific handling
        if ($errorType === 'rate_limit_error') {
            $retryAfter = (int) $response->header('Retry-After', 5);
            Jarvis::setContext(['rate_limit_retry_after' => $retryAfter]);
            sleep($retryAfter);
        }
    }

    /**
     * Example: Webhook processing with error tracking.
     */
    public function handleWebhook(array $payload): void
    {
        $eventType = $payload['type'] ?? 'unknown';
        $eventId = $payload['id'] ?? 'unknown';

        Jarvis::setContext([
            'integration' => 'stripe',
            'operation' => 'webhook_processing',
            'event_type' => $eventType,
            'event_id' => $eventId,
            'webhook_received_at' => now()->toIso8601String(),
        ]);

        try {
            // Verify webhook signature
            $this->verifyWebhookSignature($payload);

            // Process based on event type
            match ($eventType) {
                'payment_intent.succeeded' => $this->handlePaymentSuccess($payload),
                'payment_intent.payment_failed' => $this->handlePaymentFailure($payload),
                'customer.subscription.updated' => $this->handleSubscriptionUpdate($payload),
                default => logger()->info("Unhandled Stripe webhook: {$eventType}"),
            };

        } catch (Exception $e) {
            // Critical: webhook processing failures need immediate attention
            Jarvis::capture($e, [
                'severity' => 'critical',
                'webhook_payload_size' => strlen(json_encode($payload)),
                'requires_manual_intervention' => true,
            ]);

            throw $e;
        }
    }

    /**
     * Example: Batch operations with progress tracking.
     */
    public function syncCustomers(array $customerIds): array
    {
        $total = count($customerIds);
        $results = ['success' => 0, 'failed' => 0];

        Jarvis::setContext([
            'integration' => 'stripe',
            'operation' => 'batch_sync_customers',
            'total_customers' => $total,
        ]);

        foreach ($customerIds as $index => $customerId) {
            Jarvis::setContext([
                'current_customer' => $index + 1,
                'progress_percent' => round((($index + 1) / $total) * 100, 2),
            ]);

            try {
                $this->syncSingleCustomer($customerId);
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;

                // Only capture if it's not a known/expected error
                if (!$this->isExpectedError($e)) {
                    Jarvis::capture($e, [
                        'customer_id' => $customerId,
                        'batch_position' => $index + 1,
                    ]);
                }
            }

            // Rate limiting: Stripe recommends max 100 requests/second
            usleep(10000); // 10ms delay
        }

        return $results;
    }

    // Helper methods...
    private function verifyWebhookSignature(array $payload): void
    {
        // Implementation
    }

    private function handlePaymentSuccess(array $payload): void
    {
        // Implementation
    }

    private function handlePaymentFailure(array $payload): void
    {
        // Implementation
    }

    private function handleSubscriptionUpdate(array $payload): void
    {
        // Implementation
    }

    private function syncSingleCustomer(string $customerId): void
    {
        // Implementation
    }

    private function isExpectedError(Exception $e): bool
    {
        // Check if this is a known, expected error that doesn't need tracking
        return false;
    }
}

/*
 * Best Practices for API Integration Error Tracking:
 *
 * 1. Set context before each API call
 * 2. Include retry attempt information
 * 3. Capture API-specific error codes and messages
 * 4. Track request IDs from API responses
 * 5. Differentiate between retriable and non-retriable errors
 * 6. Include rate limit information when applicable
 * 7. Mark critical errors (like webhook failures) with severity
 * 8. Track progress for batch operations
 */
