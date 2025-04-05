<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Console;

use Ody\Container\Container;
use Ody\Logger\StreamLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;

/**
 * CommandRegistry
 *
 * Registry for managing console commands with simplified loading.
 */
class CommandRegistry
{
    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var Command[]
     */
    protected array $commands = [];

    /**
     * @var array
     */
    protected array $registered = [];

    /**
     * CommandRegistry constructor
     *
     * @param Container $container
     * @param LoggerInterface|null $logger
     */
    public function __construct(Container $container, ?LoggerInterface $logger = null)
    {
        $this->container = $container;
        $this->logger = $logger ?? new StreamLogger('php://stdout');
    }

    /**
     * Add a command to the registry by class name
     *
     * @param string $commandClass Command class name
     * @return self
     */
    public function add(string $commandClass): self
    {
        // Skip if already registered
        if (isset($this->registered[$commandClass])) {
            return $this;
        }

        // Check if the class exists
        if (!class_exists($commandClass)) {
            $this->logger->warning("Command class does not exist: {$commandClass}");
            return $this;
        }

        try {
            // Create the command instance
            $instance = $this->resolveCommand($commandClass);

            // Skip if resolving failed
            if (!$instance) {
                return $this;
            }

            // Add to commands
            $name = $instance->getName();
            $this->commands[$name] = $instance;
            $this->registered[$commandClass] = true;

            $this->logger->debug("Command registered: {$name} ({$commandClass})");
        } catch (\Throwable $e) {
            $this->logger->error("Failed to register command {$commandClass}: " . $e->getMessage());
        }

        return $this;
    }

    /**
     * Get all registered commands
     *
     * @return Command[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Check if a command exists by name
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Get a command by name
     *
     * @param string $name
     * @return Command|null
     */
    public function get(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * Clear the registry
     *
     * @return self
     */
    public function clear(): self
    {
        $this->commands = [];
        $this->registered = [];
        return $this;
    }

    /**
     * Resolve a command from a class name
     *
     * @param string $class
     * @return Command|null
     */
    protected function resolveCommand(string $class): ?Command
    {
        try {
            // Try resolving from container first
            if ($this->container->has($class)) {
                $command = $this->container->make($class);
            } else {
                // Otherwise create a new instance
                $command = new $class();
            }

            // Validate it's a Command
            if (!$command instanceof Command) {
                $this->logger->warning("Class {$class} is not a Symfony Command");
                return null;
            }

            return $command;
        } catch (\Throwable $e) {
            $this->logger->error("Error resolving command {$class}: " . $e->getMessage());
            return null;
        }
    }
}