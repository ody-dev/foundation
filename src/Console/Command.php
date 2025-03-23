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
use Ody\Foundation\Application;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base Command class for console commands
 */
abstract class Command extends SymfonyCommand
{
    /**
     * The input interface implementation.
     *
     * @var InputInterface|null
     */
    protected $input;

    /**
     * The output interface implementation.
     *
     * @var OutputInterface|null
     */
    protected $output;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description;

    /**
     * The container instance.
     *
     * @var Container|null
     */
    protected $container;

    /**
     * The application instance.
     *
     * @var Application|null
     */
    protected $app;

    /**
     * The logger instance.
     *
     * @var LoggerInterface|null
     */
    protected $logger;

    /**
     * Symfony input/output style instance.
     *
     * @var SymfonyStyle|null
     */
    protected $io;

    /**
     * Create a new console command instance.
     */
    public function __construct()
    {
        parent::__construct($this->name);

        $this->setDescription($this->description);

        // Add custom options and arguments
//        $this->configure();
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        // Override in child classes to add options and arguments
    }

    /**
     * Execute the console command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->io = new SymfonyStyle($input, $output);

        // Initialize container and application if available
        $this->initializeContainer();

        try {
            return $this->handle($input, $output);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            if ($this->logger) {
                $this->logger->error('Command error: ' . $e->getMessage(), [
                    'exception' => $e,
                    'trace' => $e->getTraceAsString(),
                    'command' => $this->getName()
                ]);
            }

            if ($output->isVerbose()) {
                $this->io->error([
                    'Exception: ' . get_class($e),
                    'File: ' . $e->getFile() . ':' . $e->getLine(),
                    'Message: ' . $e->getMessage()
                ]);
            }

            return self::FAILURE;
        }
    }

    /**
     * Initialize container and application
     *
     * @return void
     */
    protected function initializeContainer(): void
    {
        // Get container instance
        $this->container = Container::getInstance();

        if ($this->container) {
            $this->logger = $this->container->make(LoggerInterface::class);
        } else {
            $this->io->warning('Container not initialized');
        }
    }

    /**
     * Handle the command.
     *
     * @return int
     */
    abstract protected function handle(InputInterface $input, OutputInterface $output): int;

    /**
     * Ask a question.
     *
     * @param string $question
     * @param string|null $default
     * @return string
     */
    protected function ask(string $question, ?string $default = null): string
    {
        return $this->io->ask($question, $default);
    }

    /**
     * Ask for confirmation.
     *
     * @param string $question
     * @param bool $default
     * @return bool
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->io->confirm($question, $default);
    }

    /**
     * Ask for a secret value.
     *
     * @param string $question
     * @return string
     */
    protected function secret(string $question): string
    {
        return $this->io->askHidden($question);
    }

    /**
     * Give the user a choice from an array of options.
     *
     * @param string $question
     * @param array $choices
     * @param mixed $default
     * @return mixed
     */
    protected function choice(string $question, array $choices, $default = null)
    {
        return $this->io->choice($question, $choices, $default);
    }

    /**
     * Write an info message.
     *
     * @param string|array $messages
     * @return void
     */
    protected function info($messages): void
    {
        $this->io->writeln($this->formatMessages($messages, 'info'));
    }

    /**
     * Write a comment message.
     *
     * @param string|array $messages
     * @return void
     */
    protected function comment($messages): void
    {
        $this->io->writeln($this->formatMessages($messages, 'comment'));
    }

    /**
     * Write a question message.
     *
     * @param string|array $messages
     * @return void
     */
    protected function question($messages): void
    {
        $this->io->writeln($this->formatMessages($messages, 'question'));
    }

    /**
     * Write a warning message.
     *
     * @param string|array $messages
     * @return void
     */
    protected function warning($messages): void
    {
        $this->io->warning($this->toArray($messages));
    }

    /**
     * Write an error message.
     *
     * @param string|array $messages
     * @return void
     */
    protected function error($messages): void
    {
        $this->io->error($this->toArray($messages));
    }

    /**
     * Write a success message.
     *
     * @param string|array $messages
     * @return void
     */
    protected function success($messages): void
    {
        $this->io->success($this->toArray($messages));
    }

    /**
     * Create a table.
     *
     * @param array $headers
     * @return Table
     */
    protected function table(array $headers = []): Table
    {
        $table = new Table($this->output);

        if (!empty($headers)) {
            $table->setHeaders($headers);
        }

        return $table;
    }

    /**
     * Format messages with a specific style.
     *
     * @param string|array $messages
     * @param string $style
     * @return array
     */
    protected function formatMessages($messages, string $style): array
    {
        return array_map(function ($message) use ($style) {
            return "<{$style}>{$message}</{$style}>";
        }, $this->toArray($messages));
    }

    /**
     * Convert the given value to an array.
     *
     * @param string|array $value
     * @return array
     */
    protected function toArray($value): array
    {
        return is_array($value) ? $value : [$value];
    }
}