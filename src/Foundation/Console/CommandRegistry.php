<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Console;

use Ody\Container\Container;
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
     * @param LoggerInterface $logger
     */
    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
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
            return $this;
        }

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
        // Try resolving from container first
        if ($this->container->has($class)) {
            $command = $this->container->make($class);
        } else {
            // Otherwise create a new instance
            $command = new $class();
        }

        // Validate it's a Command
        if (!$command instanceof Command) {
            throw new \Exception("Class {$class} is not a Symfony Command");
        }

        return $command;
    }

    /**
     * Extract class name from a file
     *
     * @param string $file
     * @return string|null
     */
    protected function getClassNameFromFile(string $file): ?string
    {
        $content = file_get_contents($file);

        // Extract namespace
        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = $matches[1];
        }

        // Extract class name
        $className = null;
        if (preg_match('/class\s+([a-zA-Z0-9_]+)(?:\s+extends|\s+implements|\s*{)/', $content, $matches)) {
            $className = $matches[1];
        }

        if ($namespace && $className) {
            return $namespace . '\\' . $className;
        }

        return null;
    }

    /**
     * Normalize a path
     *
     * @param string $path
     * @return string
     */
    protected function normalizePath(string $path): string
    {
        // If starts with a slash, it's an absolute path
        if ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Z]:/i', $path)) {
            return $path;
        }

        // Otherwise, make it relative to app base path
        $basePath = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__, 3);

        return $basePath . DIRECTORY_SEPARATOR . $path;
    }
}