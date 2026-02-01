<?php

namespace StouteWebSolutions\JarvisErrorReporter\Tests\Feature;

use StouteWebSolutions\JarvisErrorReporter\JarvisErrorReporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Exception;

/**
 * This test file documents the actual payload structure sent to n8n.
 * These examples are useful for:
 * - Configuring your n8n workflow
 * - Understanding what data Claude Code receives
 * - Debugging payload issues
 */
class PayloadExamplesTest extends TestCase
{
    /**
     * Example payload for a simple exception.
     *
     * This is the minimal payload structure you'll receive.
     */
    public function test_simple_exception_payload(): void
    {
        $config = $this->getTestConfig();
        $reporter = new JarvisErrorReporter($config, Cache::driver(), Log::channel());

        $exception = new Exception('Division by zero in calculator', 500);

        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($reporter, $exception, []);

        // Print example payload for documentation
        $this->assertIsArray($payload);

        // Document structure
        $expectedStructure = [
            'error_hash' => 'string (32 char MD5)',
            'project' => 'test-project',
            'environment' => 'testing',
            'should_autofix' => false,
            'timestamp' => 'ISO8601 string',
            'error' => [
                'class' => 'Exception',
                'message' => 'Division by zero in calculator',
                'code' => 500,
                'file' => 'string (file path)',
                'line' => 'integer (line number)',
                'trace' => 'array of stack frames',
            ],
            'source' => 'array|null (source code context)',
            'request' => 'array|null (HTTP request data)',
            'user' => 'array|null (authenticated user)',
            'app' => [
                'laravel_version' => 'string',
                'php_version' => 'string',
                'locale' => 'string',
            ],
            'context' => 'array (custom context)',
            'git' => 'array|null (git info)',
        ];

        foreach (array_keys($expectedStructure) as $key) {
            $this->assertArrayHasKey($key, $payload, "Missing key: {$key}");
        }
    }

    /**
     * Example payload with rich context.
     *
     * This shows a payload with user info, custom context, and extra metadata.
     */
    public function test_rich_context_payload(): void
    {
        $config = $this->getTestConfig();
        $reporter = new JarvisErrorReporter($config, Cache::driver(), Log::channel());

        // Set user context
        $reporter->setUser(42, 'user@example.com', 'John Doe');

        // Set custom context
        $reporter->setContext([
            'tenant_id' => 123,
            'tenant_name' => 'Acme Corp',
            'subscription_tier' => 'enterprise',
            'feature_flags' => ['new_ui' => true, 'beta_features' => false],
        ]);

        $exception = new Exception('Payment processing failed');

        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        // Add extra context at capture time
        $payload = $method->invoke($reporter, $exception, [
            'payment_amount' => 99.99,
            'currency' => 'USD',
            'gateway' => 'stripe',
            'card_last_four' => '4242',
        ]);

        // Verify user context
        $this->assertEquals(42, $payload['user']['id']);
        $this->assertEquals('user@example.com', $payload['user']['email']);
        $this->assertEquals('John Doe', $payload['user']['name']);

        // Verify custom context merged with capture context
        $this->assertEquals(123, $payload['context']['tenant_id']);
        $this->assertEquals('Acme Corp', $payload['context']['tenant_name']);
        $this->assertEquals(99.99, $payload['context']['payment_amount']);
        $this->assertEquals('stripe', $payload['context']['gateway']);

        // Example JSON output (pretty-printed for documentation)
        $exampleJson = [
            'error_hash' => md5('example'),
            'project' => 'test-project',
            'environment' => 'testing',
            'should_autofix' => false,
            'timestamp' => '2024-01-15T10:30:00+00:00',
            'error' => [
                'class' => 'Exception',
                'message' => 'Payment processing failed',
                'code' => 0,
                'file' => '/app/src/Services/PaymentService.php',
                'line' => 145,
                'trace' => [
                    [
                        'file' => '/app/src/Services/PaymentService.php',
                        'line' => 145,
                        'class' => 'App\\Services\\PaymentService',
                        'function' => 'chargeCard',
                        'type' => '->',
                    ],
                    // ... more stack frames
                ],
            ],
            'source' => [
                'file' => '/app/src/Services/PaymentService.php',
                'line' => 145,
                'context' => [
                    135 => '    private function chargeCard(Order $order): Payment',
                    136 => '    {',
                    137 => '        $amount = $order->total;',
                    // ... surrounding lines
                    145 => '        throw new Exception("Payment processing failed");',
                    // ... surrounding lines
                ],
                'full_contents' => '<?php /* entire file contents */',
                'related_files' => [
                    'app/Models/Order.php' => '<?php /* file contents */',
                    'app/Services/StripeService.php' => '<?php /* file contents */',
                ],
            ],
            'request' => null, // Would be populated in actual HTTP requests
            'user' => [
                'id' => 42,
                'email' => 'user@example.com',
                'name' => 'John Doe',
            ],
            'app' => [
                'laravel_version' => '11.0.0',
                'php_version' => '8.3.0',
                'locale' => 'en',
            ],
            'context' => [
                'tenant_id' => 123,
                'tenant_name' => 'Acme Corp',
                'subscription_tier' => 'enterprise',
                'feature_flags' => ['new_ui' => true, 'beta_features' => false],
                'payment_amount' => 99.99,
                'currency' => 'USD',
                'gateway' => 'stripe',
                'card_last_four' => '4242',
            ],
            'git' => [
                'branch' => 'main',
                'commit' => 'abc12345',
            ],
        ];

        $this->assertIsArray($exampleJson); // For documentation
    }

    /**
     * Example payload from a queue job failure.
     *
     * Shows typical context from asynchronous job processing.
     */
    public function test_queue_job_payload_example(): void
    {
        $config = $this->getTestConfig();
        $reporter = new JarvisErrorReporter($config, Cache::driver(), Log::channel());

        $reporter->setContext([
            'job_class' => 'App\\Jobs\\SendWeeklyDigest',
            'job_id' => 'job-uuid-123',
            'queue_name' => 'emails',
            'attempt' => 2,
            'max_attempts' => 3,
            'user_id' => 42,
        ]);

        $exception = new Exception('SMTP connection timeout');

        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($reporter, $exception, [
            'email_to' => 'user@example.com',
            'email_subject' => 'Your Weekly Digest',
            'smtp_server' => 'smtp.example.com',
            'timeout_after_seconds' => 30,
        ]);

        $this->assertEquals('App\\Jobs\\SendWeeklyDigest', $payload['context']['job_class']);
        $this->assertEquals(2, $payload['context']['attempt']);
        $this->assertEquals(3, $payload['context']['max_attempts']);

        // This payload helps Jarvis understand:
        // - It's a queue job (can check job status)
        // - It's retrying (might not need immediate fix)
        // - It's an email job (can check SMTP config)
    }

    /**
     * Example payload from API integration error.
     *
     * Shows external service failure context.
     */
    public function test_api_integration_payload_example(): void
    {
        $config = $this->getTestConfig();
        $reporter = new JarvisErrorReporter($config, Cache::driver(), Log::channel());

        $reporter->setContext([
            'integration' => 'stripe',
            'operation' => 'create_customer',
            'api_version' => '2023-10-16',
            'retry_attempt' => 1,
            'max_retries' => 3,
        ]);

        $exception = new Exception('Stripe API error: rate_limit_error');

        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($reporter, $exception, [
            'stripe_error_type' => 'rate_limit_error',
            'stripe_error_code' => 'rate_limit',
            'stripe_request_id' => 'req_abc123',
            'http_status' => 429,
            'retry_after_seconds' => 60,
        ]);

        $this->assertEquals('stripe', $payload['context']['integration']);
        $this->assertEquals('rate_limit_error', $payload['context']['stripe_error_type']);
        $this->assertEquals(429, $payload['context']['http_status']);

        // This helps Jarvis:
        // - Identify it's a rate limit (not a code bug)
        // - See retry is already in progress
        // - Know when to retry (60 seconds)
    }

    protected function getTestConfig(): array
    {
        return [
            'enabled' => true,
            'dsn' => 'https://test.example.com/webhook',
            'project' => 'test-project',
            'environment' => 'testing',
            'autofix_environments' => ['production'],
            'sample_rate' => 1.0,
            'ignored_exceptions' => [],
            'sensitive_fields' => ['password', 'token', 'api_key'],
            'include_source' => true,
            'source_context_lines' => 10,
            'rate_limit' => [
                'enabled' => false,
                'max_per_minute' => 10,
                'dedup_window_seconds' => 60,
            ],
            'timeout' => 5,
            'queue' => null,
        ];
    }
}
