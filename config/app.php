<?php


return [
    'name' => env('APP_NAME', 'Ody API'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool)env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'providers' => [
        Ody\Foundation\Providers\ErrorServiceProvider::class,

        // Add your application service providers here
        // App\Providers\CustomServiceProvider::class,
    ],

    'aliases' => [
        'App' => Ody\Foundation\Application::class,
        'Config' => \Ody\Support\Config::class,
        'Env' => \Ody\Support\Env::class,
        'Router' => \Ody\Foundation\Router\Router::class,
        'Request' => Ody\Foundation\Http\Request::class,
        'Response' => Ody\Foundation\Http\Response::class,
    ],

    'routes' => [
        'path' => env('ROUTES_PATH', base_path('routes')),
    ],

    'middleware' => [
        // Global middleware applied to all routes
        'global' => [
            Ody\Foundation\Middleware\ErrorHandlerMiddleware::class,
            Ody\Foundation\Middleware\CorsMiddleware::class,
            Ody\Foundation\Middleware\JsonBodyParserMiddleware::class,
            Ody\Foundation\Middleware\LoggingMiddleware::class,
        ],

        // Named middleware that can be referenced in routes
        'named' => [
            // Authentication middleware with different guards
            'auth' => Ody\Foundation\Middleware\AuthMiddleware::class,
            'auth:api' => \Ody\Auth\Middleware\AuthMiddleware::class,
            'auth:jwt' => Ody\Foundation\Middleware\AuthMiddleware::class,
            'auth:session' => Ody\Foundation\Middleware\AuthMiddleware::class,

            // Role-based access control
            'role' => Ody\Foundation\Middleware\RoleMiddleware::class,
            'role:admin' => Ody\Foundation\Middleware\RoleMiddleware::class,
            'role:user' => Ody\Foundation\Middleware\RoleMiddleware::class,
            'role:guest' => Ody\Foundation\Middleware\RoleMiddleware::class,

            // Rate limiting
            'throttle' => Ody\Foundation\Middleware\ThrottleMiddleware::class,
            'throttle:60,1' => Ody\Foundation\Middleware\ThrottleMiddleware::class,  // 60 requests per minute
            'throttle:1000,60' => Ody\Foundation\Middleware\ThrottleMiddleware::class, // 1000 requests per hour

            // Other middleware
            'cors' => Ody\Foundation\Middleware\CorsMiddleware::class,
            'json' => Ody\Foundation\Middleware\JsonBodyParserMiddleware::class,
            'log' => Ody\Foundation\Middleware\LoggingMiddleware::class,

            // Add your custom middleware here
            // 'cache' => App\Http\Middleware\CacheMiddleware::class,
            // 'csrf' => App\Http\Middleware\CsrfMiddleware::class,
        ],

        // Middleware groups for route groups
        'groups' => [
            'web' => [
                'auth',
                'json',
            ],
            'api' => [
                'throttle:60,1',
                'auth:api',
                'json',
            ],
            'admin' => [
                'auth',
                'role:admin',
                'json',
            ],
        ],

        // Middleware resolver options
        'resolvers' => [
            // Add custom resolver classes here if needed
            // 'custom' => App\Http\Middleware\Resolvers\CustomResolver::class,
        ],
    ],

    'cors' => [
        'origin' => env('CORS_ALLOW_ORIGIN', '*'),
        'methods' => env('CORS_ALLOW_METHODS', 'GET, POST, PUT, DELETE, OPTIONS'),
        'headers' => env('CORS_ALLOW_HEADERS', 'Content-Type, Authorization, X-Requested-With, X-API-Key'),
        'credentials' => env('CORS_ALLOW_CREDENTIALS', false),
        'max_age' => env('CORS_MAX_AGE', 86400), // 24 hours
    ],
];