<?php

namespace StouteWebSolutions\JarvisErrorReporter\Commands;

use Illuminate\Console\Command;
use StouteWebSolutions\JarvisErrorReporter\JarvisErrorReporter;
use Exception;

class JarvisTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jarvis:test
                            {--sync : Send synchronously instead of using queue}';

    /**
     * The console command description.
     */
    protected $description = 'Send a test error to Jarvis to verify configuration';

    /**
     * Execute the console command.
     */
    public function handle(JarvisErrorReporter $reporter): int
    {
        $this->info('Testing Jarvis Error Reporter configuration...');
        $this->newLine();

        // Validate configuration
        $this->validateConfig();

        // Create a test exception
        $this->info('Creating test exception...');

        try {
            $this->throwTestException();
        } catch (Exception $e) {
            // Temporarily override queue config if --sync flag is used
            $originalQueue = config('jarvis.queue');
            if ($this->option('sync')) {
                config(['jarvis.queue' => null]);
            }

            $this->info('Sending test report to Jarvis...');

            try {
                $reporter->capture($e, [
                    'test_report' => true,
                    'command' => 'jarvis:test',
                    'timestamp' => now()->toIso8601String(),
                ]);

                // Restore queue config
                config(['jarvis.queue' => $originalQueue]);

                $this->newLine();
                $this->info('✓ Test report sent successfully!');
                $this->newLine();

                $this->displayNextSteps();

                return self::SUCCESS;
            } catch (Exception $sendException) {
                config(['jarvis.queue' => $originalQueue]);

                $this->newLine();
                $this->error('✗ Failed to send test report');
                $this->error('Error: ' . $sendException->getMessage());
                $this->newLine();

                $this->displayTroubleshooting();

                return self::FAILURE;
            }
        }

        return self::FAILURE;
    }

    /**
     * Validate the Jarvis configuration.
     */
    protected function validateConfig(): void
    {
        $checks = [
            'enabled' => config('jarvis.enabled'),
            'dsn' => config('jarvis.dsn'),
            'project' => config('jarvis.project'),
        ];

        foreach ($checks as $key => $value) {
            if (empty($value)) {
                $this->warn("⚠ JARVIS_" . strtoupper($key) . " is not set");
            } else {
                $this->line("✓ JARVIS_" . strtoupper($key) . ": " . ($key === 'dsn' ? $this->maskDsn($value) : $value));
            }
        }

        if (config('jarvis.queue')) {
            $this->line("✓ Queue: " . config('jarvis.queue') . " (use --sync to send immediately)");
        } else {
            $this->line("✓ Sending synchronously (no queue configured)");
        }

        $this->newLine();

        // Stop if critical config is missing
        if (empty($checks['enabled']) || empty($checks['dsn'])) {
            $this->error('Missing required configuration. Please set JARVIS_ENABLED and JARVIS_DSN in your .env file.');
            exit(self::FAILURE);
        }
    }

    /**
     * Throw a test exception.
     */
    protected function throwTestException(): void
    {
        throw new Exception(
            'This is a test error from Jarvis Error Reporter. ' .
            'If you see this in your n8n webhook, your configuration is working correctly!'
        );
    }

    /**
     * Mask the DSN for display.
     */
    protected function maskDsn(string $dsn): string
    {
        $parsed = parse_url($dsn);
        $host = $parsed['host'] ?? 'unknown';
        $path = isset($parsed['path']) ? substr($parsed['path'], 0, 15) . '...' : '';

        return $parsed['scheme'] . '://' . $host . $path;
    }

    /**
     * Display next steps after successful test.
     */
    protected function displayNextSteps(): void
    {
        $this->line('Next steps:');
        $this->line('  1. Check your n8n webhook logs for the test error');

        if (config('jarvis.queue')) {
            $this->line('  2. Make sure your queue worker is running: php artisan queue:work');
            $this->line('  3. Check queue jobs table or horizon for job status');
        }

        $this->line('  ' . (config('jarvis.queue') ? '4' : '2') . '. Verify the payload contains error details and source code');
        $this->line('  ' . (config('jarvis.queue') ? '5' : '3') . '. Test auto-fix by triggering a real error in your ' . config('jarvis.environment') . ' environment');
    }

    /**
     * Display troubleshooting tips.
     */
    protected function displayTroubleshooting(): void
    {
        $this->line('Troubleshooting:');
        $this->line('  • Verify JARVIS_DSN is accessible from this server');
        $this->line('  • Check firewall rules and network connectivity');
        $this->line('  • Review logs: tail -f storage/logs/laravel.log');
        $this->line('  • Try with --sync flag to bypass queue issues');
        $this->line('  • Verify your n8n webhook is running and accessible');
        $this->newLine();
        $this->line('For more help, see: https://github.com/pstoute/jarvis-error-reporter#troubleshooting');
    }
}
