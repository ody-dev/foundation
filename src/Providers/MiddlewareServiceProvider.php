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
            $config = $container->make(Config::class);

            // Get cache configuration
            $enableStats = $config->get('app.middleware.cache.stats', false);

            // Create registry (without loading config yet)
            return new MiddlewareRegistry($container, $logger, $enableStats);
        });

        // Register MiddlewareManager as a singleton
        $this->singleton(MiddlewareManager::class, function (Container $container) {
            $logger = $container->make(LoggerInterface::class);
            $registry = $container->make(MiddlewareRegistry::class);
            $config = $container->make(Config::class);

            // Get stats configuration
            $enableStats = $config->get('app.middleware.cache.stats', false);

            // Create manager with existing registry
            $manager = new MiddlewareManager($container, $logger, $enableStats);

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

        // Set up middleware cache stats collection if enabled
        $config = $this->make(Config::class);

        if ($config->get('app.middleware.cache.stats', false)) {
            $this->setupStatsCollection();
        }
    }

    /**
     * Set up middleware cache stats collection
     *
     * @return void
     */
    protected function setupStatsCollection(): void
    {
        // Get the registry instance
        $registry = $this->make(MiddlewareRegistry::class);
        $logger = $this->make(LoggerInterface::class);

        // For Swoole environments, periodically log cache stats
        if (extension_loaded('swoole')) {
            // Check if we can register a timer
            if (function_exists('Swoole\Timer::tick')) {
                \Swoole\Timer::tick(60000, function () use ($registry, $logger) {
                    $stats = $registry->getCacheStats();
                    $logger->info('Middleware resolution cache stats', $stats);
                });
            }
        }
    }
}