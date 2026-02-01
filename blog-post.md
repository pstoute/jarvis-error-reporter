# I Built a Self-Healing Laravel App with Claude Code and n8n (and You Can Too)

**TL;DR**: I replaced Sentry with a $0/month AI-powered error tracking system that automatically fixes bugs in production. Here's how.

---

## The Problem: Error Tracking Isn't Enough Anymore

If you're running a Laravel app in production, you probably use Sentry, Bugsnag, or a similar error tracking service. They're great at *telling* you when things break. But then what?

1. You get an alert
2. You context-switch from whatever you were doing
3. You dig through stack traces
4. You write a fix
5. You test it
6. You deploy it
7. You hope it worked

This process takes **15-60 minutes** per bug, even for simple issues. And if you're like me, running multiple Laravel apps, you're doing this dance several times a week.

## What If Errors Could Fix Themselves?

I had a thought: Claude Code is already amazing at understanding codebases and writing fixes. What if I could send my production errors directly to Claude, let it analyze them, write a fix, run tests, and deploy—all automatically?

So I built **Jarvis Error Reporter**: a Laravel package that captures errors and orchestrates an autonomous fix workflow using Claude Code, n8n, and GitHub.

### The Architecture

Here's how it works:

```
Laravel Error → Jarvis Package → n8n Webhook → Claude Code → Fixed & Deployed
```

When an exception occurs:

1. **Jarvis captures** comprehensive context (stack trace, source code, user info, git state)
2. **Sends to n8n** (your own infrastructure, not a third-party service)
3. **n8n orchestrates**:
   - Creates a GitHub issue
   - Clones the repo
   - Invokes Claude Code with full error context
   - Claude analyzes the error and writes a fix
   - Runs your test suite
4. **Deploys based on confidence**:
   - High confidence (tests pass) → Push to main
   - Medium confidence → Create PR for review
5. **Notifies you** via ntfy with rollback option

## Installation: Literally 2 Minutes

```bash
composer require pstoute/jarvis-error-reporter
```

Add to `.env`:

```env
JARVIS_DSN=https://n8n.yourdomain.com/webhook/autofix
JARVIS_PROJECT=your-repo-slug
JARVIS_ENVIRONMENT=production
```

That's it. Errors are now automatically tracked and fixed.

Verify it works:

```bash
php artisan jarvis:test
```

## Real Example: A Bug Fixed While I Slept

Last week, I deployed a feature that had a null pointer bug in an edge case. It only triggered when a specific combination of user settings occurred.

**11:47 PM**: User hits the bug
**11:47 PM**: Jarvis captures error with full context
**11:48 PM**: n8n receives payload, creates GitHub issue
**11:48 PM**: Claude Code analyzes the stack trace and source code
**11:49 PM**: Claude identifies the null check was missing
**11:49 PM**: Claude writes fix, runs tests (pass)
**11:50 PM**: Fix pushed to main, deployed via Forge
**11:51 PM**: I get ntfy notification on my phone

**Total time**: 4 minutes. **My involvement**: 0 minutes (I was asleep).

The next morning, I reviewed the fix in the GitHub issue. Claude had:
- Identified the exact line causing the problem
- Added the missing null check
- Updated the related test to cover the edge case
- Written a clear commit message explaining the fix

## Why This Works Better Than Sentry

| Feature | Sentry | Jarvis |
|---------|--------|--------|
| Error capture | ✓ | ✓ |
| Source code context | ✓ | ✓ (more aggressive) |
| Stack traces | ✓ | ✓ |
| **Auto-fix attempts** | ✗ | ✓ |
| **GitHub integration** | Limited | Full workflow |
| **Your infrastructure** | ✗ | ✓ |
| **Monthly cost** | $26-$200+ | $0* |

*You pay for Claude API usage only when errors occur. For most apps: $5-15/month.

## The Secret Sauce: Context is Everything

The key to making this work is sending Claude *everything* it needs to understand the error:

```json
{
  "error": {
    "class": "TypeError",
    "message": "Cannot read property 'id' of null",
    "file": "app/Services/PaymentService.php",
    "line": 147,
    "trace": [...]
  },
  "source": {
    "full_contents": "<?php ... entire file ...",
    "context": {
      137: "    private function processRefund(Order $order)",
      147: "        $customerId = $order->customer->id;",
      157: "    }"
    },
    "related_files": {
      "app/Models/Order.php": "<?php ...",
      "app/Models/Customer.php": "<?php ..."
    }
  },
  "user": {
    "id": 42,
    "email": "user@example.com"
  },
  "request": {
    "url": "/orders/123/refund",
    "method": "POST",
    "input": {"amount": 99.99}
  },
  "git": {
    "branch": "main",
    "commit": "abc1234"
  }
}
```

Claude sees:
- The exact error
- The surrounding code
- Related files from the stack trace
- What the user was trying to do
- The git state

This is like handing a senior developer a perfectly documented bug report. Claude can reason about the problem and write an appropriate fix.

## Confidence Levels: Not All Fixes Are Equal

Jarvis doesn't blindly push every fix to production. After Claude writes a fix and runs tests, the n8n workflow evaluates confidence:

**High Confidence** (auto-push):
- Tests pass
- Fix is localized (1-2 files)
- Clear cause identified
- No database migrations

**Medium Confidence** (create PR):
- Tests pass but fix touches many files
- Unclear root cause
- Performance implications
- Requires schema changes

**Low Confidence** (issue only):
- Tests fail
- Claude isn't confident
- Complex architectural change needed

I've found that about 60% of errors are high confidence auto-fixes. These are typically:
- Null checks
- Type errors
- Undefined variable/property
- Simple logic errors

The remaining 40% create PRs that I review before merging. This has been the perfect balance.

## Privacy & Security Considerations

**"Wait, you're sending source code to an external webhook?"**

Yes, but with caveats:

1. **Your infrastructure**: The webhook goes to YOUR n8n instance, not a third-party service
2. **Sensitive data is redacted**: Passwords, tokens, credit cards automatically scrubbed
3. **You control it**: Toggle `JARVIS_INCLUDE_SOURCE=false` to disable source code sending
4. **HTTPS only**: Secure transmission required
5. **Webhook auth**: Add authentication to your n8n webhook

For apps with extremely sensitive IP, you can disable source code inclusion and rely on stack traces only. Claude is still surprisingly effective with just the error message and trace.

## Handling Edge Cases

### What if tests don't catch the bug?

Then the fix will likely fail tests, get marked low confidence, and create an issue instead of auto-deploying. The key is having good test coverage.

### What if Claude makes it worse?

I added a rollback mechanism. The ntfy notification includes a rollback button that reverts to the previous commit. I've only needed it twice in 3 months.

### What about rate limits?

Built-in deduplication and rate limiting:

```env
JARVIS_RATE_LIMIT_PER_MINUTE=10  # Max 10 errors/minute
JARVIS_DEDUP_WINDOW=60           # Don't send same error twice within 60s
```

### What about costs?

Claude API costs are surprisingly low for this use case. Most errors are simple (50K tokens to analyze + 5K to fix = ~$0.15 per error). For a typical app with 10-20 errors per week: ~$10-20/month.

Compare that to Sentry's $26/month base plan (limited to 5K errors) or $80/month for 50K errors.

## Real-World Patterns: Examples Included

The package includes comprehensive examples showing how to integrate Jarvis into different parts of your app:

**Middleware** (auto-track tenant context):
```php
public function handle(Request $request, Closure $next)
{
    if ($tenant = $request->tenant) {
        Jarvis::setContext([
            'tenant_id' => $tenant->id,
            'subscription_tier' => $tenant->plan,
        ]);
    }

    return $next($request);
}
```

**Service Classes** (business logic errors):
```php
try {
    $payment = $this->processPayment($order);
} catch (Exception $e) {
    Jarvis::capture($e, [
        'order_id' => $order->id,
        'payment_amount' => $order->total,
    ]);
    throw $e;
}
```

**Queue Jobs** (async error tracking):
```php
public function handle()
{
    Jarvis::setContext([
        'job_class' => self::class,
        'attempt' => $this->attempts(),
        'max_attempts' => $this->tries,
    ]);

    // Job logic...
}
```

## Setting This Up Yourself

The package is open source and well-documented. Here's what you need:

1. **n8n instance** (free, self-hosted or cloud)
2. **GitHub repository** with API access
3. **Claude Code** installed and configured
4. **Optional**: NocoDB for tracking issues

The README has full setup instructions, troubleshooting guides, and environment-specific configurations for Forge, Vapor, Docker, etc.

**GitHub**: [pstoute/jarvis-error-reporter](https://github.com/pstoute/jarvis-error-reporter)
**Packagist**: `composer require pstoute/jarvis-error-reporter`

## Lessons Learned

### 1. Context is King

Initially, I only sent stack traces. Claude could identify problems but couldn't write good fixes without seeing the surrounding code. Adding full file contents was a game-changer.

### 2. Test Coverage Matters More Than Ever

Your test suite becomes the gatekeeper for auto-deployment. If your tests don't catch regressions, Claude might push bad fixes. This motivated me to improve test coverage across the board.

### 3. Not Every Error Needs Fixing

Some errors are user errors (validation failures, auth issues). The package auto-ignores these by default:

```php
'ignored_exceptions' => [
    AuthenticationException::class,
    ValidationException::class,
    NotFoundHttpException::class,
],
```

### 4. Queue Workers Are Essential

Sending error reports synchronously can slow down error responses. Always use queues in production:

```env
JARVIS_QUEUE=default
```

The package supports both sync (for testing) and async (for production).

## The Future: Where This Could Go

I'm experimenting with a few enhancements:

1. **Pattern Detection**: If the same type of error occurs across multiple projects, suggest architectural improvements
2. **Proactive Fixes**: Analyze code for potential issues before they cause errors
3. **Performance Optimization**: Use Claude to suggest performance improvements based on slow query logs
4. **Security Scanning**: Automatically identify and fix security vulnerabilities

The core idea—autonomous code improvement—has massive potential beyond just error fixing.

## Is This Production-Ready?

I've been running this on 3 production Laravel apps for the past 3 months:

- **127 errors captured**
- **76 auto-fixed** (60% success rate)
- **41 PRs created** for review
- **10 manual interventions** required
- **0 production incidents** caused by bad auto-fixes

The time savings are real. Rough math:

- 76 errors × 20 minutes average = **25 hours saved**
- 41 PRs × 10 minutes to review = **7 hours of my time**
- **Net savings: ~18 hours** over 3 months

That's half a work week I spent building features instead of fixing bugs.

## Try It Yourself

If you're running Laravel apps and tired of the bug-fix dance, give Jarvis a shot:

```bash
composer require pstoute/jarvis-error-reporter
```

Full documentation: [github.com/pstoute/jarvis-error-reporter](https://github.com/pstoute/jarvis-error-reporter)

---

**Questions? Feedback?** Open an issue on GitHub or reach out on Twitter [@paulstoute](https://twitter.com/paulstoute).

If you found this interesting, you might also like my posts on:
- [Building n8n Workflows for Development Automation](#)
- [How Claude Code Became My Pair Programmer](#)
- [Running a SaaS with 90% Automated Operations](#)

---

*Update 2026-01-31: Added comprehensive examples, test suite, and platform-specific guides based on community feedback.*
