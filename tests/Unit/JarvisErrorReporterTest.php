<?php

namespace StouteWebSolutions\JarvisErrorReporter\Tests\Unit;

use StouteWebSolutions\JarvisErrorReporter\JarvisErrorReporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Exception;

class JarvisErrorReporterTest extends TestCase
{
    protected JarvisErrorReporter $reporter;
    protected array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'enabled' => true,
            'dsn' => 'https://test.example.com/webhook',
            'project' => 'test-project',
            'environment' => 'testing',
            'autofix_environments' => ['production', 'staging'],
            'sample_rate' => 1.0,
            'ignored_exceptions' => [],
            'sensitive_fields' => ['password', 'token'],
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

    /**
     * Example: Test that payload includes all expected fields.
     */
    public function test_payload_structure(): void
    {
        $exception = new Exception('Test error', 500);

        // Use reflection to access protected method
        $reporter = new JarvisErrorReporter($this->config, Cache::driver(), Log::channel());
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($reporter, $exception, ['test_context' => true]);

        // Verify structure
        $this->assertArrayHasKey('error_hash', $payload);
        $this->assertArrayHasKey('project', $payload);
        $this->assertArrayHasKey('environment', $payload);
        $this->assertArrayHasKey('should_autofix', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('error', $payload);
        $this->assertArrayHasKey('context', $payload);

        // Verify error details
        $this->assertEquals('Exception', $payload['error']['class']);
        $this->assertEquals('Test error', $payload['error']['message']);
        $this->assertEquals(500, $payload['error']['code']);
        $this->assertArrayHasKey('file', $payload['error']);
        $this->assertArrayHasKey('line', $payload['error']);
        $this->assertArrayHasKey('trace', $payload['error']);

        // Verify context
        $this->assertTrue($payload['context']['test_context']);
    }

    /**
     * Example: Test that sensitive data is redacted.
     */
    public function test_sensitive_data_redaction(): void
    {
        $reporter = new JarvisErrorReporter($this->config, Cache::driver(), Log::channel());
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('sanitizeInput');
        $method->setAccessible(true);

        $input = [
            'email' => 'user@example.com',
            'password' => 'secret123',
            'token' => 'abc123xyz',
            'name' => 'John Doe',
        ];

        $sanitized = $method->invoke($reporter, $input);

        $this->assertEquals('user@example.com', $sanitized['email']);
        $this->assertEquals('[REDACTED]', $sanitized['password']);
        $this->assertEquals('[REDACTED]', $sanitized['token']);
        $this->assertEquals('John Doe', $sanitized['name']);
    }

    /**
     * Example: Test hash generation for deduplication.
     */
    public function test_error_hash_generation(): void
    {
        $reporter = new JarvisErrorReporter($this->config, Cache::driver(), Log::channel());
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('generateHash');
        $method->setAccessible(true);

        $exception1 = new Exception('Same error', 0);
        $exception2 = new Exception('Same error', 0);
        $exception3 = new Exception('Different error', 0);

        // Force same file/line by using same exception instance data
        $hash1 = $method->invoke($reporter, $exception1);
        $hash2 = $method->invoke($reporter, $exception2);
        $hash3 = $method->invoke($reporter, $exception3);

        // Same error should produce different hashes (different line numbers)
        // But we can verify the hash format
        $this->assertIsString($hash1);
        $this->assertEquals(32, strlen($hash1)); // MD5 hash length
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash1);
    }

    /**
     * Example: Test that ignored exceptions are not captured.
     */
    public function test_ignored_exceptions(): void
    {
        $this->config['ignored_exceptions'] = [Exception::class];
        $reporter = new JarvisErrorReporter($this->config, Cache::driver(), Log::channel());
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('shouldCapture');
        $method->setAccessible(true);

        $exception = new Exception('Should be ignored');
        $shouldCapture = $method->invoke($reporter, $exception);

        $this->assertFalse($shouldCapture);
    }

    /**
     * Example: Test sample rate filtering.
     */
    public function test_sample_rate(): void
    {
        // 0% sample rate - should never capture
        $this->config['sample_rate'] = 0.0;
        $reporter = new JarvisErrorReporter($this->config, Cache::driver(), Log::channel());
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('shouldCapture');
        $method->setAccessible(true);

        $exception = new Exception('Test');

        // Run multiple times to verify randomness doesn't interfere
        for ($i = 0; $i < 10; $i++) {
            $shouldCapture = $method->invoke($reporter, $exception);
            $this->assertFalse($shouldCapture, "Iteration {$i} should not capture with 0% sample rate");
        }

        // 100% sample rate - should always capture
        $this->config['sample_rate'] = 1.0;
        $reporter = new JarvisErrorReporter($this->config, Cache::driver(), Log::channel());
        $reflection = new \ReflectionClass($reporter);
        $method = $reflection->getMethod('shouldCapture');
        $method->setAccessible(true);

        for ($i = 0; $i < 10; $i++) {
            $shouldCapture = $method->invoke($reporter, $exception);
            $this->assertTrue($shouldCapture, "Iteration {$i} should capture with 100% sample rate");
        }
    }
}
