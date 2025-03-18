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
use Ody\Foundation\Console\ConsoleKernel;
use Ody\Support\Config;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * Console Service Provider
 *
 * Registers console-related services in the container
 */
class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * The core console commands provided by the framework
     *
     * @var array
     */
    protected array $commands = [
        \Ody\Foundation\Console\Commands\ServeCommand::class,
        \Ody\Foundation\Console\Commands\EnvironmentCommand::class,
        \Ody\Foundation\Console\Commands\TestCommand::class,
        \Ody\Foundation\Console\Commands\MakeCommandCommand::class,
//        \Ody\Foundation\Console\Commands\ListCommand::class,
    ];

    /**
     * Register console-related services
     *
     * @return void
     */
    public function register(): void
    {
        // Register Symfony Console application
        $this->singleton(ConsoleApplication::class, function (Container $container) {
            $version = $this->getFrameworkVersion($container);
            return new ConsoleApplication('ODY Console', $version);
        });

        // Register CommandRegistry as a singleton
        $this->singleton(CommandRegistry::class, function (Container $container) {
            return new CommandRegistry(
                $container,
                $container->make(LoggerInterface::class)
            );
        });

        // Register ConsoleKernel - should be registered last
        // as it depends on the other services
        $this->singleton(ConsoleKernel::class, function (Container $container) {
            return new ConsoleKernel(
                $container,
                $container->make(ConsoleApplication::class)
            );
        });
    }

    /**
     * Bootstrap console services
     *
     * @return void
     */
    public function boot(): void
    {
        // Skip if not running in console (prevents unnecessary scanning in HTTP requests)
        if ($this->container->has('app')) {
            $app = $this->container->make('app');
            if (method_exists($app, 'isConsole') && !$app->isConsole()) {
                return;
            }
        }

        $registry = $this->make(CommandRegistry::class);
        $console = $this->make(ConsoleApplication::class);

        // Register built-in framework commands
        $this->registerFrameworkCommands($registry);

        // Register application commands from config
        $this->registerApplicationCommands($registry);

        // Register all commands with the Symfony console application
        $this->registerCommandsWithConsole($registry, $console);
    }

    /**
     * Register framework built-in commands
     *
     * @param CommandRegistry $registry
     * @return void
     */
    protected function registerFrameworkCommands(CommandRegistry $registry): void
    {
        foreach ($this->commands as $commandClass) {
            // Simply pass the class name to the registry
            $registry->add($commandClass);
        }
    }

    /**
     * Register application commands from config
     *
     * @param CommandRegistry $registry
     * @return void
     */
    protected function registerApplicationCommands(CommandRegistry $registry): void
    {
        $config = $this->container->make(Config::class);
        $commands = $config->get('app.commands', []);

        foreach ($commands as $command) {
            if (class_exists($command)) {
                $registry->add($command);
            }
        }
    }

    /**
     * Register commands with Symfony Console
     *
     * @param CommandRegistry $registry
     * @param ConsoleApplication $console
     * @return void
     */
    protected function registerCommandsWithConsole(CommandRegistry $registry, ConsoleApplication $console): void
    {
        $logger = $this->container->make(LoggerInterface::class);

        foreach ($registry->getCommands() as $command) {
            if (!$console->has($command->getName())) {
                $console->add($command);
                $logger->debug("Registered command with console: " . $command->getName());
            }
        }
    }

    /**
     * Get the framework version
     *
     * @param Container $container
     * @return string
     */
    protected function getFrameworkVersion(Container $container): string
    {
        if ($container->has(Config::class)) {
            $config = $container->make(Config::class);
            $version = $config->get('app.version');

            if ($version) {
                return $version;
            }
        }

        // Fallback version
        return '1.0.0';
    }

    /**
     * Get the services provided by the provider
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            CommandRegistry::class,
            ConsoleKernel::class,
            ConsoleApplication::class,
        ];
    }
}