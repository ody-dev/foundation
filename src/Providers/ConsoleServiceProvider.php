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
use Ody\Foundation\Console\CommandRegistry;
use Ody\Foundation\Console\Commands\EnvironmentCommand;
use Ody\Foundation\Console\Commands\PublishCommand;
use Ody\Foundation\Console\ConsoleKernel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application as SymfonyConsole;

/**
 * ConsoleServiceProvider
 *
 * Service provider for console commands and related services
 */
class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Services that should be registered as singletons
     *
     * @var array
     */
    protected array $singletons = [
        SymfonyConsole::class => null,
        ConsoleKernel::class => null,
    ];

    /**
     * Array of commands for deferred registration
     *
     * @var array
     */
    protected array $commands = [];

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Run common registration logic
        $this->registerCommon();

        // Register the Symfony console application
        $this->singleton(SymfonyConsole::class, function () {
            $console = new SymfonyConsole('ODY Framework', '1.0.0');
            return $console;
        });

        // Register the console kernel
        $this->singleton(ConsoleKernel::class, function (Container $container) {
            $console = $container->make(SymfonyConsole::class);
            return new ConsoleKernel($container, $console);
        });

        // Store commands for deferred registration in boot()
        $this->commands = [
            EnvironmentCommand::class,
            PublishCommand::class
        ];
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Skip if not in console
        if (!$this->isRunningInConsole()) {
            return;
        }

        // Register the command registry - do this in boot() when LoggerInterface should be available
        $this->singleton(CommandRegistry::class, function (Container $container) {
            // Get logger or create a fallback
            $logger = null;
            if ($container->has(LoggerInterface::class)) {
                $logger = $container->make(LoggerInterface::class);
            } else {
                // Create a NullLogger as fallback
                $logger = new NullLogger();
            }

            return new CommandRegistry($container, $logger);
        });

        // Register commands now that CommandRegistry is available
        if (!empty($this->commands)) {
            $this->registerCommands($this->commands);
        }
    }
}