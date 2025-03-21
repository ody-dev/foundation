<?php

namespace Ody\Foundation\Providers;

use Ody\Foundation\MiddlewareManager;
use Ody\Foundation\Router\Router;
use Ody\Foundation\Router\RouteService;

/**
 * Route Service Provider
 *
 * Registers and bootstraps the routing services for the application.
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * Register route-related services
     *
     * @return void
     */
    public function register(): void
    {
        // Register the Router in the container
        $this->container->singleton(Router::class, function ($container) {
            return new Router(
                $container,
                $container->make(MiddlewareManager::class),
            );
        });

        // Register the RouteService in the container
        $this->container->singleton(RouteService::class, function ($container) {
            return new RouteService(
                $container,
                $container->make(Router::class),
                $container->make(MiddlewareManager::class),
                logger(),
            );
        });

        // Make Router and RouteService available via aliases
        $this->container->alias(Router::class, 'router');
        $this->container->alias(RouteService::class, 'route.service');
    }

    /**
     * Bootstrap routing services
     *
     * Load all application routes during bootstrap phase
     *
     * @return void
     */
    public function boot(): void
    {
        // Only bootstrap in HTTP context (skip for console)
//        if ($this->isRunningInConsole()) {
//            logger()->debug("RouteServiceProvider: Skipping route loading in console context");
//            return;
//        }

        // Load all application routes
        $this->container->make('route.service')->bootRoutes();
    }
}