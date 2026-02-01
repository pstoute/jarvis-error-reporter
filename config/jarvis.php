<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Jarvis DSN (Webhook URL)
    |--------------------------------------------------------------------------
    |
    | The n8n webhook endpoint that receives error reports. This is similar
    | to Sentry's DSN concept.
    |
    */
    'dsn' => env('JARVIS_DSN'),

    /*
    |--------------------------------------------------------------------------
    | Project Identifier
    |--------------------------------------------------------------------------
    |
    | A unique slug identifying this project/repo. Used by Jarvis to determine
    | which repository to work on when fixing errors.
    |
    */
    'project' => env('JARVIS_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The environment name (production, staging, local). Jarvis can use this
    | to determine whether to auto-fix or just log.
    |
    */
    'environment' => env('JARVIS_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for error reporting. Set to false to disable entirely.
    |
    */
    'enabled' => env('JARVIS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Auto-fix Environments
    |--------------------------------------------------------------------------
    |
    | Which environments should trigger auto-fix attempts. You probably only
    | want this for production/staging, not local development.
    |
    */
    'autofix_environments' => explode(',', env('JARVIS_AUTOFIX_ENVIRONMENTS', 'production,staging')),

    /*
    |--------------------------------------------------------------------------
    | Sample Rate
    |--------------------------------------------------------------------------
    |
    | Float between 0 and 1. Percentage of errors to report. Use this to
    | reduce noise in high-traffic apps. 1.0 = report everything.
    |
    */
    'sample_rate' => (float) env('JARVIS_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    |
    | Exception classes that should never be reported to Jarvis.
    |
    */
    'ignored_exceptions' => [
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Auth\Access\AuthorizationException::class,
        Illuminate\Database\Eloquent\ModelNotFoundException::class,
        Illuminate\Validation\ValidationException::class,
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Fields
    |--------------------------------------------------------------------------
    |
    | Request fields that should be redacted before sending to Jarvis.
    |
    */
    'sensitive_fields' => [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'api_key',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
    ],

    /*
    |--------------------------------------------------------------------------
    | Include File Contents
    |--------------------------------------------------------------------------
    |
    | Whether to include the source file contents in the report. This helps
    | Claude Code understand context but increases payload size.
    |
    */
    'include_source' => env('JARVIS_INCLUDE_SOURCE', true),

    /*
    |--------------------------------------------------------------------------
    | Source Context Lines
    |--------------------------------------------------------------------------
    |
    | Number of lines before/after the error line to include for context.
    |
    */
    'source_context_lines' => (int) env('JARVIS_SOURCE_CONTEXT_LINES', 20),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Prevent flooding Jarvis with duplicate errors.
    |
    */
    'rate_limit' => [
        'enabled' => env('JARVIS_RATE_LIMIT', true),
        'max_per_minute' => (int) env('JARVIS_RATE_LIMIT_PER_MINUTE', 10),
        'dedup_window_seconds' => (int) env('JARVIS_DEDUP_WINDOW', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | HTTP timeout for sending reports (in seconds). Keep low so errors
    | don't slow down user requests.
    |
    */
    'timeout' => (int) env('JARVIS_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Queue Reports
    |--------------------------------------------------------------------------
    |
    | Send reports via queue to avoid blocking requests. Recommended for
    | production. Set to null/false to send synchronously.
    |
    */
    'queue' => env('JARVIS_QUEUE', 'default'),
];
