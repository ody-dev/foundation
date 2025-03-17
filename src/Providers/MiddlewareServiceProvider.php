<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Providers;

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
        // Register MiddlewareManager as a singleton
        $this->singleton(MiddlewareManager::class, function ($container) {
            $logger = $container->make(LoggerInterface::class);
            return new MiddlewareManager($container, $logger);
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
        $manager = $this->make(MiddlewareManager::class);
        $config = $this->make(Config::class);
        $logger = $this->make(LoggerInterface::class);

        $this->registerGlobalMiddleware($manager, $config, $logger);
    }

    /**
     * Register global middleware from configuration
     *
     * @param MiddlewareManager $manager
     * @param Config $config
     * @param LoggerInterface $logger
     * @return void
     */
    protected function registerGlobalMiddleware(
        MiddlewareManager $manager,
        Config $config,
        LoggerInterface $logger
    ): void {
        // Get global middleware from configuration
        $globalMiddleware = $config->get('app.middleware.global', []);

        // Register each middleware
        foreach ($globalMiddleware as $middleware) {
            try {
                $manager->addGlobal($middleware);
                logger()->debug("Registered global middleware: " . (is_string($middleware) ? $middleware : get_class($middleware)));
            } catch (\Throwable $e) {
                $logger->error("Failed to register global middleware", [
                    'middleware' => is_string($middleware) ? $middleware : get_class($middleware),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}