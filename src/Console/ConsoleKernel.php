<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Console;

use Ody\Container\Container;
use Ody\Foundation\Exceptions\Handler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ConsoleKernel
 *
 * Central class for managing console commands and handling command-line requests.
 */
class ConsoleKernel
{
    /**
     * @var Container
     */
    protected Container $container;

    /**
     * @var ConsoleApplication
     */
    protected ConsoleApplication $console;

    /**
     * @var CommandRegistry
     */
    protected CommandRegistry $commandRegistry;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * ConsoleKernel constructor
     *
     * @param Container $container
     * @param ConsoleApplication|null $console
     */
    public function __construct(
        Container $container,
        ?ConsoleApplication $console = null
    ) {
        // Initialize the container
        $this->container = $container;
        Container::setInstance($this->container);

        // Use provided console application or create a new one
        $this->console = $console ?? $this->container->make(ConsoleApplication::class);

        // Register self in container
        $this->container->instance(self::class, $this);
    }

    /**
     * Handle an incoming console command
     *
     * @param InputInterface|null $input
     * @param OutputInterface|null $output
     * @return int
     */
    public function handle(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        try {
            return $this->console->run($input, $output);
        } catch (\Throwable $e) {
            // Report the exception using the central handler
            if ($this->container->has(Handler::class)) {
                $this->container->make(Handler::class)->report($e);
            }

            // Existing console output for the error
            if ($output) {
                // Use SymfonyStyle for better formatting if available
                $io = new \Symfony\Component\Console\Style\SymfonyStyle($input ?? new \Symfony\Component\Console\Input\ArgvInput(), $output);
                $io->error('Error: ' . $e->getMessage());
                if ($output->isVerbose()) {
                    $io->writeln('<comment>Exception:</comment> ' . get_class($e));
                    $io->writeln('<comment>File:</comment> ' . $e->getFile() . ':' . $e->getLine());
                    // Maybe output trace in very verbose mode -vvv
                }
            } else {
                echo "Error: " . $e->getMessage() . PHP_EOL;
            }

            return 1; // Indicate failure
        }
    }
}