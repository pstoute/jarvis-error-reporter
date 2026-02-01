# Jarvis Error Reporter

A Laravel package that sends application errors to Jarvis for automatic analysis and fixing. Think Sentry, but with AI-powered auto-remediation.

## Installation

```bash
composer require stoutewebsolutions/jarvis-error-reporter
```

## Configuration

Add to your `.env` file:

```env
JARVIS_DSN=https://n8n.stoutesystems.com/webhook/autofix
JARVIS_PROJECT=your-project-slug
JARVIS_ENVIRONMENT=production
JARVIS_ENABLED=true
```

That's it! Errors will automatically be captured and sent to Jarvis.

### Optional Configuration

```env
# Only auto-fix in specific environments (comma-separated)
JARVIS_AUTOFIX_ENVIRONMENTS=production,staging

# Sample rate (0.0 to 1.0) - useful for high-traffic apps
JARVIS_SAMPLE_RATE=1.0

# Include full source file contents (helps Claude understand context)
JARVIS_INCLUDE_SOURCE=true

# Lines of context around error
JARVIS_SOURCE_CONTEXT_LINES=20

# Rate limiting
JARVIS_RATE_LIMIT=true
JARVIS_RATE_LIMIT_PER_MINUTE=10
JARVIS_DEDUP_WINDOW=60

# Queue for async sending (set to empty/false for sync)
JARVIS_QUEUE=default

# HTTP timeout in seconds
JARVIS_TIMEOUT=5
```

### Publishing Config

To customize ignored exceptions or sensitive fields:

```bash
php artisan vendor:publish --tag=jarvis-config
```

## Manual Usage

### Capture an Exception Manually

```php
use StouteWebSolutions\JarvisErrorReporter\Facades\Jarvis;

try {
    // risky operation
} catch (Exception $e) {
    Jarvis::capture($e, ['operation' => 'import', 'batch_id' => $batchId]);
    throw $e; // or handle it
}
```

### Add Context

```php
// In middleware or service provider
Jarvis::setUser(auth()->id(), auth()->user()->email)
    ->setContext([
        'tenant_id' => $tenant->id,
        'subscription_tier' => $tenant->plan,
    ]);
```

## What Gets Sent

Each error report includes:

- **Error details**: class, message, file, line, stack trace
- **Source code**: the file contents and surrounding context
- **Request info**: URL, method, sanitized input, headers
- **User context**: authenticated user info
- **App context**: Laravel version, PHP version, locale
- **Git info**: current branch and commit hash
- **Custom context**: anything you add via `setContext()`

### Sensitive Data

The following fields are automatically redacted:
- password, password_confirmation
- secret, token, api_key
- credit_card, card_number, cvv, ssn
- Authorization and Cookie headers

## How Auto-Fix Works

1. Your Laravel app throws an exception
2. Jarvis Error Reporter captures it and sends to your n8n webhook
3. n8n orchestrates the fix process:
   - Creates/updates a GitHub issue
   - Syncs the repo locally
   - Invokes Claude Code to analyze and fix
   - Runs tests to verify
   - Creates a PR (medium confidence) or pushes directly (high confidence)
   - Updates tracking in NocoDB
4. You get notified via ntfy with a rollback option

## Comparison to Sentry

| Feature | Sentry | Jarvis |
|---------|--------|--------|
| DSN-based config | ✓ | ✓ |
| Auto exception capture | ✓ | ✓ |
| Rate limiting | ✓ | ✓ |
| Deduplication | ✓ | ✓ |
| User context | ✓ | ✓ |
| Source code context | ✓ | ✓ (more aggressive) |
| Sensitive data scrubbing | ✓ | ✓ |
| Queued sending | ✓ | ✓ |
| **Auto-fix attempts** | ✗ | ✓ |
| **GitHub issue creation** | ✗ | ✓ |
| **Your infrastructure** | ✗ | ✓ |
| **Monthly cost** | $26+ | $0 |

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## License

MIT
