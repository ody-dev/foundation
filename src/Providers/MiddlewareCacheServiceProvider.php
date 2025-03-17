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
use Ody\Foundation\Middleware\MiddlewareDispatcher;
use Ody\Foundation\Middleware\MiddlewareResolutionCache;
use Ody\Foundation\MiddlewareManager;
use Ody\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * MiddlewareCacheServiceProvider
 *
 * Registers and configures middleware caching services.
 */
class MiddlewareCacheServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        // Register MiddlewareResolutionCache as a singleton
        $this->singleton(MiddlewareResolutionCache::class, function (Container $container) {
            $config = $container->make(Config::class);
            $logger = $container->make(LoggerInterface::class);

            // Get cache configuration
            $enableStats = $config->get('app.middleware.cache.stats', false);

            return new MiddlewareResolutionCache(
                $container,
                $logger,
                $enableStats
            );
        });

        // Register MiddlewareManager with enhanced caching
        $this->singleton(MiddlewareManager::class, function (Container $container) {
            $config = $container->make(Config::class);
            $logger = $container->make(LoggerInterface::class);
            $cache = $container->make(MiddlewareResolutionCache::class);

            // Create the enhanced middleware manager
            $enableStats = $config->get('app.middleware.cache.stats', false);
            $manager = new MiddlewareManager($container, $logger, $enableStats);

            // Register configuration
            $middlewareConfig = $config->get('app.middleware', []);
            if (is_array($middlewareConfig)) {
                $manager->registerFromConfig($middlewareConfig);
            }

            return $manager;
        });

        // Override the previous middleware dispatcher implementation
        $this->bind(MiddlewareDispatcher::class, function (Container $container) {
            $logger = $container->make(LoggerInterface::class);

            return new MiddlewareDispatcher(
                $container,
                null, // No default final handler
                $logger
            );
        });
    }

    /**
     * Bootstrap the service provider.
     *
     * @return void
     */
    public function boot(): void
    {
        // Register stats collection for the caching system if enabled
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
        // Get the cache instance
        $cache = $this->make(MiddlewareResolutionCache::class);
        $logger = $this->make(LoggerInterface::class);

        // For Swoole environments, periodically log cache stats
        if (extension_loaded('swoole')) {
            // Check if we can register a timer
            if (function_exists('Swoole\Timer::tick')) {
                \Swoole\Timer::tick(60000, function () use ($cache, $logger) {
                    $stats = $cache->getStats();
                    $logger->info('Middleware resolution cache stats', $stats);
                });
            }
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            MiddlewareResolutionCache::class,
            MiddlewareManager::class,
        ];
    }
}