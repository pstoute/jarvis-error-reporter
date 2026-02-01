# Jarvis Error Reporter - Examples

This directory contains real-world examples of how to integrate Jarvis Error Reporter into your Laravel application.

## Overview

Each example demonstrates a specific use case with best practices for error tracking, context management, and failure handling.

## Examples

### 1. Middleware Context (`01-middleware-context.php`)

**Use Case**: Automatically add context to all error reports

**Key Concepts**:
- Setting user context from authenticated requests
- Multi-tenant application tracking
- API versioning context
- Request metadata capture
- Feature flag integration

**When to use**:
- Multi-tenant applications
- Apps with complex user hierarchies
- API services with versioning
- When you need request-level context on all errors

---

### 2. Service Pattern (`02-service-pattern.php`)

**Use Case**: Error tracking in business logic layers

**Key Concepts**:
- Adding operation context before try blocks
- Capturing with additional failure details
- Non-critical error handling (capture but don't fail)
- Progressive context updates during long operations
- Import/batch processing patterns

**When to use**:
- Service classes with complex business logic
- Operations that can partially fail
- Import/export operations
- Multi-step processes

---

### 3. API Integration (`03-api-integration.php`)

**Use Case**: Comprehensive error tracking for external API calls

**Key Concepts**:
- Retry logic with attempt tracking
- API-specific error codes and messages
- Request ID capture
- Rate limit handling
- Webhook processing
- Batch operations with progress tracking
- Differentiating retriable vs non-retriable errors

**When to use**:
- Payment gateway integrations
- Third-party API clients
- Webhook handlers
- Any external service integration

---

### 4. Queue Jobs (`04-queue-jobs.php`)

**Use Case**: Error tracking in asynchronous job processing

**Key Concepts**:
- Job metadata context (ID, queue, attempts)
- Retry attempt tracking
- Failed job handling with critical alerts
- Batch processing with progress updates
- Memory usage monitoring
- External dependency error handling

**When to use**:
- Background email processing
- Data exports/imports
- External API synchronization
- Long-running batch operations

## Common Patterns

### Setting Context

Always set context **before** the try block:

```php
Jarvis::setContext([
    'operation' => 'payment_processing',
    'order_id' => $order->id,
]);

try {
    // Risky operation
} catch (Exception $e) {
    Jarvis::capture($e);
    throw $e;
}
```

### Capturing with Additional Context

Add specific failure information when capturing:

```php
try {
    $payment = $this->chargeCard($amount);
} catch (Exception $e) {
    Jarvis::capture($e, [
        'payment_attempted' => $amount,
        'card_last_four' => $card->last4,
        'transaction_id' => $transactionId ?? null,
    ]);

    throw $e;
}
```

### Non-Critical Errors

For errors that shouldn't break the flow:

```php
try {
    $this->notifyExternalService($data);
} catch (Exception $e) {
    // Capture but don't throw
    Jarvis::capture($e, [
        'severity' => 'warning',
        'non_critical' => true,
    ]);

    logger()->warning('External notification failed', [
        'error' => $e->getMessage()
    ]);

    // Continue execution
}
```

### Progressive Context Updates

For long-running operations:

```php
$total = count($items);

foreach ($items as $index => $item) {
    Jarvis::setContext([
        'current_item' => $index + 1,
        'progress_percent' => round((($index + 1) / $total) * 100, 2),
    ]);

    $this->processItem($item);
}
```

## Tips for Maximum Value

1. **Be Specific**: Use descriptive operation names that clearly indicate what failed
2. **Add Business Context**: Include IDs, amounts, states that help understand the error
3. **Track Progress**: For loops and batches, include position and percentage
4. **Differentiate Severity**: Mark critical vs warning vs informational errors
5. **Include Retry Info**: For retry loops, include attempt number and max attempts
6. **Capture Before Throw**: Always capture the error before re-throwing
7. **Use User Context**: Set user info early (middleware) so it's on all errors
8. **Memory Awareness**: Monitor memory in long-running jobs

## Anti-Patterns to Avoid

❌ **Don't** set context after the error occurs
```php
try {
    $this->doSomething();
} catch (Exception $e) {
    Jarvis::setContext(['operation' => 'something']); // Too late!
    Jarvis::capture($e);
}
```

❌ **Don't** capture without relevant context
```php
try {
    $payment = $this->process($order);
} catch (Exception $e) {
    Jarvis::capture($e); // Missing order context!
}
```

❌ **Don't** swallow errors silently
```php
try {
    $this->criticalOperation();
} catch (Exception $e) {
    // Silent failure - no capture, no log, nothing!
}
```

❌ **Don't** capture the same error multiple times
```php
try {
    $this->doSomething();
} catch (Exception $e) {
    Jarvis::capture($e);
    throw $e; // Will be captured again by exception handler
}
```

## Testing Your Integration

After implementing Jarvis in your code, test it:

```bash
# Verify configuration
php artisan jarvis:test

# Trigger a real error in your code
# Check storage/logs/laravel.log for Jarvis entries
tail -f storage/logs/laravel.log | grep -i jarvis

# Verify webhook received the payload
# Check your n8n workflow execution logs
```

## Questions?

- Review the [main README](../README.md) for configuration options
- Check the [Troubleshooting section](../README.md#troubleshooting)
- Open an issue on [GitHub](https://github.com/pstoute/jarvis-error-reporter/issues)
