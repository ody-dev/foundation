<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Providers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Ody\Foundation\Application;
use Ody\Foundation\Http\HandlerPool;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service provider for core application services
 */
class ApplicationServiceProvider extends ServiceProvider
{
    /**
     * Services that should be registered as singletons
     *
     * @var array<string, mixed>
     */
    protected array $singletons = [
        Application::class => null,
        Psr17Factory::class => null,
        ServerRequestFactoryInterface::class => Psr17Factory::class,
        ResponseFactoryInterface::class => Psr17Factory::class,
        StreamFactoryInterface::class => Psr17Factory::class,
        UploadedFileFactoryInterface::class => Psr17Factory::class,
        UriFactoryInterface::class => Psr17Factory::class,
    ];

    /**
     * Register custom services
     *
     * @return void
     */
    public function register(): void
    {
        // Register application
        $this->singleton(Application::class, function ($container) {
            $providerManager = $container->make(\Ody\Foundation\Providers\ServiceProviderManager::class);

            if (!$providerManager) {
                $config = $container->has('config') ? $container->make('config') : null;
                $logger = $container->has(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;
                $providerManager = new \Ody\Foundation\Providers\ServiceProviderManager($container, $config, $logger);
                $container->instance(\Ody\Foundation\Providers\ServiceProviderManager::class, $providerManager);
            }

            return new Application($container, $providerManager);
        });

        $this->singleton(HandlerPool::class, function ($container) {
            $config = $container->make(\Ody\Support\Config::class);
            $logger = $container->make(\Psr\Log\LoggerInterface::class);

            $enableCaching = $config->get('app.handler_cache.enabled', true);
            $excludedControllers = $config->get('app.handler_cache.excluded', []);

            return new \Ody\Foundation\Http\HandlerPool(
                $container,
                $logger,
                $enableCaching,
                $excludedControllers
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
    }
}