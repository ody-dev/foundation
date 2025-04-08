<?php

return [
    'name' => env('APP_NAME', 'Ody API'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool)env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'providers' => [
        /**
         * | --- HTTP Worker Providers ---
         * | Service Providers listed here are registered and booted within each
         * | individual Swoole HTTP worker process when it starts (triggered by `onWorkerStart`).
         * |
         * | Purpose: Configure the application instance within each worker specifically
         * | for handling incoming HTTP requests. This is where you should register
         * | providers responsible for:
         * |   - Routing & Controller Resolution (\Ody\Foundation\Providers\RouteServiceProvider)
         * |   - HTTP Middleware Pipeline (\Ody\Foundation\Providers\MiddlewareServiceProvider)
         * |   - Request/Response Services & Exception Handling (\Ody\Foundation\Providers\ErrorServiceProvider)
         * |   - Database/ORM access needed during web requests (\Ody\DB\Providers\DatabaseServiceProvider, ...)
         * |   - Authentication & Authorization for requests (\Ody\Auth\Providers\AuthServiceProvider)
         * |   - Caching, Sessions, etc. used by HTTP requests (\Ody\Foundation\Providers\CacheServiceProvider)
         * |   - Your main application services triggered by controllers (\App\Providers\AppServiceProvider).
         * |
         * | Context & Isolation: Each worker initializes these providers using its own
         * | separate container instance, ensuring isolation between workers. If a provider
         * | (like Database) is needed by both HTTP workers AND background processes,
         * | it must also be listed in 'beforeServerStart' to be initialized there too.
         */
        'http' => [
            // Core providers
            \Ody\Foundation\Providers\FacadeServiceProvider::class,
            \Ody\Middleware\Providers\MiddlewareServiceProvider::class,
            \Ody\Foundation\Providers\RouteServiceProvider::class,
            \Ody\Foundation\Providers\ErrorServiceProvider::class,
            \Ody\Foundation\Providers\CacheServiceProvider::class,

            // Package providers
            \Ody\DB\Providers\DatabaseServiceProvider::class,

            // Add your application service providers here
            \App\Providers\AppServiceProvider::class,
        ],

        /**
         * | --- Pre-Server Start Providers ---
         * | Service Providers listed here are registered and booted *once* in the main
         * | process when the `server:start` command executes, BEFORE the Swoole HTTP
         * | server begins listening for requests.
         * |
         * | Purpose: Use this section for providers that need to:
         * |   1. Initialize services required by long-running background tasks or
         * |      custom Swoole processes (e.g., Database/Eloquent for queue consumers,
         * |      ProcessManager for forking).
         * |   2. Start/fork those background processes themselves (e.g., AMQP consumers).
         * |   3. Perform other one-time setup actions during server initialization.
         * |
         * | Context & Isolation: Services booted here operate in the initial command's
         * | process context. This context is separate from the HTTP worker processes
         * | that handle web requests (which use providers from the 'http' array).
         * |
         * | Important: If a service (like Database) is needed by *both* background
         * | processes AND HTTP requests, its provider MUST be listed here *and* in the
         * | 'http' array to ensure it's correctly initialized in each distinct context.
         */
        'beforeServerStart' => []

    ],
    'aliases' => [
        'App' => \Ody\Foundation\Application::class,
        'Config' => \Ody\Support\Config::class,
        'Env' => \Ody\Support\Env::class,
        'Router' => \Ody\Foundation\Router\Router::class,
        'Request' => \Ody\Foundation\Http\Request::class,
        'Response' => \Ody\Foundation\Http\Response::class,
    ],
    'routes' => [
        'path' => env('ROUTES_PATH', base_path('routes')),
    ],
    'middleware' => [
        'global' => [
            \Ody\Middleware\ErrorHandlerMiddleware::class,
            \Ody\Middleware\CorsMiddleware::class,
            \Ody\Middleware\JsonBodyParserMiddleware::class,
        ],
        'named' => [
            'auth' => \Ody\Auth\Middleware\AuthMiddleware::class,
        ],
        'groups' => [
            'api' => [
                \Ody\Auth\Middleware\AuthMiddleware::class,
                \Ody\Middleware\ThrottleMiddleware::class,
            ]
        ]
    ],
    'cors' => [
        'origin' => env('CORS_ALLOW_ORIGIN', '*'),
        'methods' => env('CORS_ALLOW_METHODS', 'GET, POST, PUT, DELETE, OPTIONS'),
        'headers' => env('CORS_ALLOW_HEADERS', 'Content-Type, Authorization, X-Requested-With, X-API-Key'),
        'credentials' => env('CORS_ALLOW_CREDENTIALS', false),
        'max_age' => env('CORS_MAX_AGE', 86400), // 24 hours
    ],

    /**
     * Controller caching configuration
     *
     * Controls the behavior of the framework's controller caching mechanism
     * Enabling this gives a slight performance boost.
     */
    'controller_cache' => [
        // Whether controller caching is enabled globally
        'enabled' => true,

        // Controllers that should be excluded from caching (helpful for controllers with serialization issues)
        'excluded' => [
            // Example: 'App\Http\Controllers\ComplexController',
            // Example: 'App\Http\Controllers\ResourceIntensiveController',
        ],
    ],
];
