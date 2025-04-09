<?php

namespace Ody\Foundation\Providers;

use Ody\Container\Container;
use Ody\Foundation\Cache\CacheManager;
use Ody\Foundation\Cache\PSR6\CacheItemPool;
use Ody\Support\Config;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Service provider for cache services
 */
class CacheServiceProvider extends ServiceProvider
{
    /**
     * Services that should be registered as singletons
     *
     * @var array<string, mixed>
     */
    protected array $singletons = [
        CacheManager::class => null,
        CacheInterface::class => null,
        CacheItemPoolInterface::class => null,
    ];

    /**
     * Services that should be registered as aliases
     *
     * @var array<string, string>
     */
    protected array $aliases = [
        'cache' => CacheManager::class,
        'cache.store' => CacheInterface::class,
        'cache.pool' => CacheItemPoolInterface::class,
    ];

    /**
     * Register cache services
     *
     * @return void
     */
    public function register(): void
    {
        // Register CacheManager as the main entry point
        $this->singleton(CacheManager::class, function (Container $container) {
            $config = $container->make(Config::class);
            $cacheConfig = $config->get('cache', []);

            return new CacheManager($cacheConfig);
        });

        // Register PSR-16 SimpleCache interface
        $this->singleton(CacheInterface::class, function (Container $container) {
            return $container->make(CacheManager::class)->driver();
        });

        // Register PSR-6 CacheItemPool interface
        $this->singleton(CacheItemPoolInterface::class, function (Container $container) {
            $simpleCache = $container->make(CacheInterface::class);
            return new CacheItemPool($simpleCache);
        });
    }

    /**
     * Bootstrap cache services
     *
     * @return void
     */
    public function boot(): void
    {
        // No bootstrapping needed
    }
}