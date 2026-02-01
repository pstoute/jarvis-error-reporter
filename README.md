# Jarvis Error Reporter

A Laravel package that sends application errors to Jarvis for automatic analysis and fixing. Think Sentry, but with AI-powered auto-remediation.

## Prerequisites

Before installing this package, you'll need:

1. **n8n Instance**: A running n8n server (cloud or self-hosted) to receive error webhooks and orchestrate the auto-fix workflow
2. **GitHub Access**: Your Laravel application must be in a GitHub repository with API access configured
3. **Claude Code Setup**: Claude Code CLI installed and configured to interact with your repositories
4. **Queue Workers** (Recommended): Laravel queue workers running for asynchronous error reporting
5. **NocoDB** (Optional): For tracking auto-fix attempts and outcomes

> **Note**: This package sends error data to YOUR infrastructure (n8n webhook), not a third-party service. You have complete control over your error data.

## Installation

```bash
composer require pstoute/jarvis-error-reporter
```

## Configuration

Add to your `.env` file:

```env
JARVIS_DSN=https://n8ndomain/webhook/autofix
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

### Test Your Installation

Verify everything is configured correctly:

```bash
php artisan jarvis:test
```

This command will:
- Validate your configuration settings
- Send a test error to your n8n webhook
- Provide troubleshooting guidance if anything fails

Use the `--sync` flag to bypass the queue and send immediately:

```bash
php artisan jarvis:test --sync
```

## Manual Usage

### Capture an Exception Manually

```php
use Pstoute\JarvisErrorReporter\Facades\Jarvis;

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

### Middleware Example

For multi-tenant applications or complex context tracking, create a middleware:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use StouteWebSolutions\JarvisErrorReporter\Facades\Jarvis;

class JarvisContext
{
    public function handle(Request $request, Closure $next)
    {
        // Set user if authenticated
        if ($user = $request->user()) {
            Jarvis::setUser($user->id, $user->email, $user->name);
        }

        // Set tenant context for multi-tenant apps
        if ($tenant = $request->tenant) {
            Jarvis::setContext([
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'plan' => $tenant->plan,
                'subscription_status' => $tenant->subscription_status,
            ]);
        }

        // Add request-specific context
        Jarvis::setContext([
            'route_name' => $request->route()?->getName(),
            'api_version' => $request->header('X-API-Version'),
        ]);

        return $next($request);
    }
}
```

Register in `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \App\Http\Middleware\JarvisContext::class,
];
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

## Security & Privacy

### What Data Leaves Your Application

By default, Jarvis sends comprehensive error context to help with auto-fixing:

**⚠️ Important**: Review these settings carefully for production environments.

#### Source Code Inclusion (`JARVIS_INCLUDE_SOURCE`)

When enabled (default: `true`), the package sends:
- **Full file contents** of the file where the error occurred
- **Related files** from the stack trace (up to 5 files, excluding vendor)
- **Line-by-line context** around the error

**Privacy Considerations**:
- May include sensitive business logic or proprietary algorithms
- Could expose internal architecture and design patterns
- Might contain TODO comments with internal information
- Files could have hardcoded values (though secrets should be in .env)

**Recommendations**:
- Set `JARVIS_INCLUDE_SOURCE=false` if your codebase contains highly sensitive IP
- Review your `.env.example` to ensure no secrets are in code
- Consider adding `JARVIS_EXCLUDE_PATHS` for sensitive directories (coming soon)
- Ensure your n8n webhook is secured with authentication and HTTPS

#### Data Sent to Your n8n Webhook

All data is sent to **your infrastructure** (not a third-party service):
- Error details, stack traces, and source code (if enabled)
- Request data (URL, method, sanitized input, headers)
- User information (ID, email, name of authenticated user)
- Application context (Laravel version, PHP version, locale)
- Git information (current branch and commit hash)
- Custom context you've added via `setContext()`

**Best Practices**:
- Use HTTPS for your n8n webhook
- Implement webhook authentication
- Restrict network access to your n8n instance
- Regularly audit what data is being sent
- Consider different settings for production vs. staging

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

## Troubleshooting

### Errors Not Being Sent

**Check Configuration**:
```bash
php artisan jarvis:test
```

This will validate your settings and attempt to send a test error.

**Common Issues**:

1. **Queue Not Processing**
   - Verify queue worker is running: `php artisan queue:work`
   - Check queue jobs table for failed jobs
   - Review `storage/logs/laravel.log` for queue errors
   - Try sending synchronously: `php artisan jarvis:test --sync`

2. **Network/Firewall Issues**
   - Verify n8n webhook URL is accessible: `curl -X POST YOUR_JARVIS_DSN`
   - Check firewall rules on both application and n8n servers
   - Ensure Docker containers can reach external networks (if using Docker)
   - Verify DNS resolution for your n8n hostname

3. **Configuration Problems**
   - Ensure `JARVIS_ENABLED=true` in `.env`
   - Verify `JARVIS_DSN` is set and correct
   - Check that error isn't in `ignored_exceptions` list
   - Confirm environment matches `JARVIS_AUTOFIX_ENVIRONMENTS` (if expecting auto-fix)

4. **Rate Limiting**
   - Check logs for "Rate limit reached" messages
   - Increase `JARVIS_RATE_LIMIT_PER_MINUTE` if needed
   - Adjust `JARVIS_DEDUP_WINDOW` for deduplication sensitivity

### Debugging Tips

**Enable Debug Logging**:
```php
// In config/jarvis.php or .env
'sample_rate' => 1.0, // Capture all errors
```

**Check Laravel Logs**:
```bash
tail -f storage/logs/laravel.log | grep -i jarvis
```

**Verify Payload Structure**:
Add temporary logging in `SendJarvisReport.php`:
```php
Log::info('Jarvis payload', ['payload' => $this->payload]);
```

**Test Webhook Manually**:
```bash
curl -X POST YOUR_JARVIS_DSN \
  -H "Content-Type: application/json" \
  -d '{"test": true, "message": "Manual test from curl"}'
```

### Queue Worker Not Running

**Forge/Envoyer**: Ensure queue workers are configured in deployment
**Vapor**: Queues are required - cannot send synchronously
**Docker**: Add queue worker as separate service in `docker-compose.yml`
**Local**: Run `php artisan queue:listen` in a separate terminal

### Still Having Issues?

1. Review the full error logs: `storage/logs/laravel.log`
2. Check n8n workflow execution logs
3. Verify GitHub repository access from your n8n instance
4. Open an issue: [GitHub Issues](https://github.com/pstoute/jarvis-error-reporter/issues)

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
