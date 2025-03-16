# ODY Framework - Foundation

The Foundation module is the core of the ODY PHP Framework, providing essential functionality for building high-performance API applications with Swoole coroutines.

## Overview

The Foundation module serves as the backbone of the ODY framework.

- **Routing**: Efficient request routing with FastRoute
- **Middleware**: PSR-15 compatible middleware implementation
- **HTTP Handling**: PSR-7 compatible request/response handling
- **Service Container**: Dependency injection and service management
- **Service Providers**: Modular service registration
- **Error Handling**: Exception handling


## Installation

```bash
composer require ody/foundation
```

## Key Features

### Swoole Integration

ODY's foundation is built with native support for Swoole's coroutines, allowing for highly concurrent PHP applications:

```php
<?php
// Start an HTTP server with Swoole
HttpServer::start(ServerManager::init(ServerType::HTTP_SERVER)
    ->createServer($config)
    ->setServerConfig($config['additional'])
    ->registerCallbacks($config['callbacks'])
    ->getServerInstance()
);
```

### Routing System

Define routes with a clean, expressive syntax:

```php
<?php
// Define routes
Router::get('/users', [UserController::class, 'index']);
Router::post('/users', [UserController::class, 'store']);
Router::get('/users/{id}', [UserController::class, 'show']);

// Group routes with shared attributes
Router::group(['prefix' => '/api', 'middleware' => ['auth:api']], function ($router) {
    $router->get('/profile', [ProfileController::class, 'show']);
    $router->put('/profile', [ProfileController::class, 'update']);
});
```

### Middleware Pipeline

Create and register middleware for request/response processing:

```php
<?php
// Register middleware
$app->middleware->add('auth', AuthMiddleware::class);
$app->middleware->add('throttle', ThrottleMiddleware::class);

// Apply to routes
Router::get('/admin/dashboard', [AdminController::class, 'dashboard'])
    ->middleware('auth')
    ->middleware('throttle:60,1');
```

### Service Providers

Organize your application with service providers:

```php
<?php
class MyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(MyService::class, function () {
            return new MyService();
        });
    }

    public function boot(): void
    {
        // Bootstrap the service
    }
}
```

## Getting Started

Create a new ODY application:

```php
<?php
use Ody\Foundation\Bootstrap;

// Initialize the application
$app = Bootstrap::init();

// Bootstrap and run
$app->bootstrap();
$app->run();
```

## Configuration

Configuration files should be placed in the `config` directory. The main configuration file is `config/app.php`.

## Documentation

For more detailed documentation, please visit [https://ody.dev/docs](https://ody.dev/docs).

## License

The ODY Framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
