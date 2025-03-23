<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Providers;

use Ody\Container\Container;
use Ody\Foundation\Middleware\MiddlewareRegistry;
use Ody\Foundation\MiddlewareManager;
use Ody\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Service provider for middleware
 */
class MiddlewareServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        // Register MiddlewareRegistry as a singleton
        $this->singleton(MiddlewareRegistry::class, function (Container $container) {
            $logger = $container->make(LoggerInterface::class);

            // Create registry (without loading config yet)
            return new MiddlewareRegistry($container, $logger);
        });

        // Register MiddlewareManager as a singleton
        $this->singleton(MiddlewareManager::class, function (Container $container) {
            $logger = $container->make(LoggerInterface::class);
            $config = $container->make(Config::class);

            // Create manager
            $manager = new MiddlewareManager($container, $logger);

            // Load configuration here - ONLY ONCE
            $middlewareConfig = $config->get('app.middleware', []);
            if (is_array($middlewareConfig)) {
                $manager->registerFromConfig($middlewareConfig);
            }

            return $manager;
        });

        // Add middleware manager alias for easier access
        $this->alias(MiddlewareManager::class, 'middleware');
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        // No bootstrapping needed as the middleware configuration
        // is loaded during registration
    }
}