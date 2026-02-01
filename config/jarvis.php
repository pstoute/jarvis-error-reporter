<?php

return [
    /*
    |--------------------------------------------------------------------------
    | General Settings
    |--------------------------------------------------------------------------
    */

    'enabled' => env('JARVIS_ENABLED', true),

    'dsn' => env('JARVIS_DSN'),

    'project' => env('JARVIS_PROJECT'),

    'environment' => env('JARVIS_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Auto-Fix Configuration
    |--------------------------------------------------------------------------
    |
    | Control which environments should trigger automatic fix attempts.
    | You typically want this enabled for production/staging only.
    |
    */

    'autofix_environments' => explode(',', env('JARVIS_AUTOFIX_ENVIRONMENTS', 'production,staging')),

    /*
    |--------------------------------------------------------------------------
    | Capture Settings
    |--------------------------------------------------------------------------
    |
    | Control what errors are captured and how aggressively.
    |
    */

    'capture' => [
        // Percentage of errors to report (0.0 to 1.0)
        'sample_rate' => (float) env('JARVIS_SAMPLE_RATE', 1.0),

        // Exception classes that should never be reported
        'ignored_exceptions' => [
            Illuminate\Auth\AuthenticationException::class,
            Illuminate\Auth\Access\AuthorizationException::class,
            Illuminate\Database\Eloquent\ModelNotFoundException::class,
            Illuminate\Validation\ValidationException::class,
            Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Code Settings
    |--------------------------------------------------------------------------
    |
    | Control how much source code is included in error reports.
    | WARNING: Sending full file contents may expose proprietary code.
    |
    */

    'source' => [
        // Include source file contents (helps Claude understand context)
        'include_contents' => env('JARVIS_INCLUDE_SOURCE', true),

        // Number of lines before/after the error line to include
        'context_lines' => (int) env('JARVIS_SOURCE_CONTEXT_LINES', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy & Security
    |--------------------------------------------------------------------------
    |
    | Fields that should be redacted before sending to Jarvis.
    |
    */

    'privacy' => [
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Prevent flooding Jarvis with duplicate or excessive errors.
    |
    */

    'rate_limit' => [
        'enabled' => env('JARVIS_RATE_LIMIT', true),

        // Maximum errors to send per minute
        'max_per_minute' => (int) env('JARVIS_RATE_LIMIT_PER_MINUTE', 10),

        // Seconds to wait before sending same error again (deduplication)
        'dedup_window_seconds' => (int) env('JARVIS_DEDUP_WINDOW', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery Settings
    |--------------------------------------------------------------------------
    |
    | Control how error reports are sent to your n8n webhook.
    |
    */

    'delivery' => [
        // HTTP timeout for sending reports (in seconds)
        'timeout' => (int) env('JARVIS_TIMEOUT', 5),

        // Queue name for async sending (null/false for synchronous)
        'queue' => env('JARVIS_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Configuration Keys
    |--------------------------------------------------------------------------
    |
    | For backwards compatibility. These will be deprecated in v2.0.
    |
    */

    'sample_rate' => (float) env('JARVIS_SAMPLE_RATE', 1.0),
    'ignored_exceptions' => [
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Auth\Access\AuthorizationException::class,
        Illuminate\Database\Eloquent\ModelNotFoundException::class,
        Illuminate\Validation\ValidationException::class,
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],
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
    'include_source' => env('JARVIS_INCLUDE_SOURCE', true),
    'source_context_lines' => (int) env('JARVIS_SOURCE_CONTEXT_LINES', 20),
    'timeout' => (int) env('JARVIS_TIMEOUT', 5),
    'queue' => env('JARVIS_QUEUE', 'default'),
];
