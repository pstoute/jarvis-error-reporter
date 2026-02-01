<?php

namespace StouteWebSolutions\JarvisErrorReporter;

use Throwable;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

class JarvisErrorReporter
{
    protected array $customContext = [];

    public function __construct(
        protected array $config,
        protected Cache $cache,
        protected LoggerInterface $logger
    ) {}

    /**
     * Capture and report an exception to Jarvis.
     */
    public function capture(Throwable $e, array $extraContext = []): void
    {
        if (!$this->shouldCapture($e)) {
            return;
        }

        $payload = $this->buildPayload($e, $extraContext);

        if ($this->isDuplicate($payload['error_hash'])) {
            $this->logger->debug('Jarvis: Skipping duplicate error', ['hash' => $payload['error_hash']]);
            return;
        }

        if ($this->isRateLimited()) {
            $this->logger->warning('Jarvis: Rate limit reached, skipping error report');
            return;
        }

        $this->send($payload);
    }

    /**
     * Set custom context to be included with all reports.
     */
    public function setContext(array $context): self
    {
        $this->customContext = array_merge($this->customContext, $context);
        return $this;
    }

    /**
     * Set user context for the current request.
     */
    public function setUser(?int $id, ?string $email = null, ?string $name = null): self
    {
        $this->customContext['user'] = array_filter([
            'id' => $id,
            'email' => $email,
            'name' => $name,
        ]);
        return $this;
    }

    /**
     * Determine if this exception should be captured.
     */
    protected function shouldCapture(Throwable $e): bool
    {
        if (!$this->config['enabled']) {
            return false;
        }

        if (empty($this->config['dsn'])) {
            return false;
        }

        // Check ignored exceptions
        foreach ($this->config['ignored_exceptions'] as $ignored) {
            if ($e instanceof $ignored) {
                return false;
            }
        }

        // Check sample rate
        if ($this->config['sample_rate'] < 1.0) {
            if (mt_rand() / mt_getrandmax() > $this->config['sample_rate']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the full payload for Jarvis.
     */
    protected function buildPayload(Throwable $e, array $extraContext): array
    {
        $errorHash = $this->generateHash($e);
        $request = request();

        return [
            // Identifiers
            'error_hash' => $errorHash,
            'project' => $this->config['project'],
            'environment' => $this->config['environment'],
            'should_autofix' => in_array(
                $this->config['environment'],
                $this->config['autofix_environments']
            ),
            'timestamp' => now()->toIso8601String(),

            // Error details
            'error' => [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->sanitizeTrace($e->getTrace()),
            ],

            // Source code context
            'source' => $this->config['include_source']
                ? $this->getSourceContext($e->getFile(), $e->getLine())
                : null,

            // Request context
            'request' => $request ? [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'input' => $this->sanitizeInput($request->all()),
                'headers' => $this->sanitizeHeaders($request->headers->all()),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ] : null,

            // User context
            'user' => $this->customContext['user'] ?? $this->getCurrentUser(),

            // App context
            'app' => [
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'locale' => app()->getLocale(),
            ],

            // Custom context
            'context' => array_merge(
                $this->customContext,
                $extraContext
            ),

            // Git info (if available)
            'git' => $this->getGitInfo(),
        ];
    }

    /**
     * Generate a unique hash for deduplication.
     */
    protected function generateHash(Throwable $e): string
    {
        return md5(implode('|', [
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ]));
    }

    /**
     * Get source code context around the error.
     */
    protected function getSourceContext(string $file, int $line): ?array
    {
        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }

        $lines = file($file);
        $contextLines = $this->config['source_context_lines'];
        $start = max(0, $line - $contextLines - 1);
        $end = min(count($lines), $line + $contextLines);

        $context = [];
        for ($i = $start; $i < $end; $i++) {
            $context[$i + 1] = rtrim($lines[$i]);
        }

        // Also include related files from the stack trace
        $relatedFiles = $this->getRelatedFileContents();

        return [
            'file' => $file,
            'line' => $line,
            'context' => $context,
            'full_contents' => file_get_contents($file),
            'related_files' => $relatedFiles,
        ];
    }

    /**
     * Get contents of related files from the stack trace.
     */
    protected function getRelatedFileContents(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $files = [];
        $basePath = base_path();

        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            // Only include project files, not vendor
            if (str_contains($frame['file'], '/vendor/')) {
                continue;
            }
            if (!str_starts_with($frame['file'], $basePath)) {
                continue;
            }

            $relativePath = str_replace($basePath . '/', '', $frame['file']);

            if (!isset($files[$relativePath]) && file_exists($frame['file'])) {
                $files[$relativePath] = file_get_contents($frame['file']);
            }

            // Limit to 5 related files
            if (count($files) >= 5) {
                break;
            }
        }

        return $files;
    }

    /**
     * Sanitize stack trace for transmission.
     */
    protected function sanitizeTrace(array $trace): array
    {
        return collect($trace)
            ->take(20)
            ->map(fn($frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
                'type' => $frame['type'] ?? null,
            ])
            ->toArray();
    }

    /**
     * Sanitize request input to remove sensitive data.
     */
    protected function sanitizeInput(array $input): array
    {
        $sensitive = array_map('strtolower', $this->config['sensitive_fields']);

        return collect($input)
            ->map(function ($value, $key) use ($sensitive) {
                if (in_array(strtolower($key), $sensitive)) {
                    return '[REDACTED]';
                }
                if (is_array($value)) {
                    return $this->sanitizeInput($value);
                }
                return $value;
            })
            ->toArray();
    }

    /**
     * Sanitize request headers.
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $redact = ['authorization', 'cookie', 'x-api-key', 'x-csrf-token'];

        return collect($headers)
            ->mapWithKeys(function ($value, $key) use ($redact) {
                $key = strtolower($key);
                if (in_array($key, $redact)) {
                    return [$key => '[REDACTED]'];
                }
                return [$key => is_array($value) ? ($value[0] ?? $value) : $value];
            })
            ->toArray();
    }

    /**
     * Get current authenticated user info.
     */
    protected function getCurrentUser(): ?array
    {
        if (!auth()->check()) {
            return null;
        }

        $user = auth()->user();
        return [
            'id' => $user->id ?? null,
            'email' => $user->email ?? null,
            'name' => $user->name ?? null,
        ];
    }

    /**
     * Get git repository info.
     */
    protected function getGitInfo(): ?array
    {
        $head = base_path('.git/HEAD');
        if (!file_exists($head)) {
            return null;
        }

        $headContents = trim(file_get_contents($head));
        $branch = null;
        $commit = null;

        if (str_starts_with($headContents, 'ref: ')) {
            $ref = substr($headContents, 5);
            $branch = str_replace('refs/heads/', '', $ref);
            $refFile = base_path('.git/' . $ref);
            if (file_exists($refFile)) {
                $commit = trim(file_get_contents($refFile));
            }
        } else {
            $commit = $headContents;
        }

        return [
            'branch' => $branch,
            'commit' => $commit ? substr($commit, 0, 8) : null,
        ];
    }

    /**
     * Check if this error was recently reported (deduplication).
     */
    protected function isDuplicate(string $hash): bool
    {
        if (!$this->config['rate_limit']['enabled']) {
            return false;
        }

        $key = "jarvis:dedup:{$hash}";
        $window = $this->config['rate_limit']['dedup_window_seconds'];

        if ($this->cache->has($key)) {
            return true;
        }

        $this->cache->put($key, true, $window);
        return false;
    }

    /**
     * Check if we've hit the rate limit.
     */
    protected function isRateLimited(): bool
    {
        if (!$this->config['rate_limit']['enabled']) {
            return false;
        }

        $key = 'jarvis:rate_limit:' . now()->format('Y-m-d-H-i');
        $max = $this->config['rate_limit']['max_per_minute'];

        $current = $this->cache->get($key, 0);

        if ($current >= $max) {
            return true;
        }

        $this->cache->put($key, $current + 1, 60);
        return false;
    }

    /**
     * Send the error report to Jarvis.
     */
    protected function send(array $payload): void
    {
        $queue = $this->config['queue'];

        if ($queue) {
            dispatch(new Jobs\SendJarvisReport($payload, $this->config))
                ->onQueue($queue);
        } else {
            (new Jobs\SendJarvisReport($payload, $this->config))->handle();
        }
    }
}
