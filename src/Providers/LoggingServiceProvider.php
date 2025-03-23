<?php
namespace Ody\Foundation\Providers;

use Ody\Container\Container;
use Ody\Logger\LogManager;
use Ody\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Service provider for logging
 */
class LoggingServiceProvider extends ServiceProvider
{
    /**
     * Services that should be registered as aliases
     *
     * @var array
     */
    protected array $aliases = [
        'log' => LogManager::class,
        'logger' => LoggerInterface::class
    ];

    /**
     * Register custom services
     *
     * @return void
     */
    public function register(): void
    {
        $this->singleton(LogManager::class, function (Container $container) {
            $config = $container->make(Config::class);
            $loggingConfig = $config->get('logging', []);
            return new LogManager($loggingConfig);
        });
    }

    /**
     * Bootstrap any application services
     *
     * @return void
     */
    public function boot(): void
    {
        $this->singleton(LoggerInterface::class, function ($container) {
            return $container->make(LogManager::class)->channel();
        });
    }
}