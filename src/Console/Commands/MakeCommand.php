<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Console\Commands;

use Ody\Foundation\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MakeCommandCommand
 *
 * Create a new console command
 */
class MakeCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new console command';

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the command class')
            ->addOption('command', 'c', InputOption::VALUE_OPTIONAL, 'The terminal command name')
            ->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'The command namespace', 'App\\Console\\Commands')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing command');
    }

    /**
     * Handle the command.
     *
     * @return int
     */
    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $className = $this->input->getArgument('name');
        $commandName = $this->input->getOption('command') ?: $this->convertClassToCommandName($className);
        $namespace = $this->input->getOption('namespace');
        $force = $this->input->getOption('force');

        // Generate the command file path
        $path = $this->generatePath($namespace, $className);

        // Check if the file already exists
        if (file_exists($path) && !$force) {
            $this->error("Command already exists at: {$path}");

            if ($this->confirm('Do you want to overwrite it?', false)) {
                $force = true;
            } else {
                return self::FAILURE;
            }
        }

        // Create directory if it doesn't exist
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Generate command file content
        $content = $this->generateCommandContent($namespace, $className, $commandName);

        // Write to file
        if (file_put_contents($path, $content) !== false) {
            $this->info("Command created successfully: {$path}");
            $this->comment("Run `php ody {$commandName}` to execute your command");
            return self::SUCCESS;
        } else {
            $this->error("Failed to create command at: {$path}");
            return self::FAILURE;
        }
    }

    /**
     * Generate the file path for the command.
     *
     * @param string $namespace
     * @param string $className
     * @return string
     */
    protected function generatePath(string $namespace, string $className): string
    {
        // Convert namespace to directory path
        $baseDirectory = base_path();
        $relativePath = str_replace('\\', '/', $namespace);
        $relativePath = str_replace('App/', 'app/', $relativePath);

        return $baseDirectory . '/' . $relativePath . '/' . $className . '.php';
    }

    /**
     * Generate the content for the command file.
     *
     * @param string $namespace
     * @param string $className
     * @param string $commandName
     * @return string
     */
    protected function generateCommandContent(string $namespace, string $className, string $commandName): string
    {
        return <<<EOT
<?php

namespace {$namespace};

use Ody\Foundation\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class {$className} extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected \$name = '{$commandName}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected \$description = 'Command description';

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        // Define arguments and options
        // \$this->addArgument('name', InputArgument::REQUIRED, 'The name of the resource');
        // \$this->addOption('option', 'o', InputOption::VALUE_OPTIONAL, 'An optional option', 'default');
    }

    /**
     * Handle the command.
     *
     * @return int
     */
    protected function handle(): int
    {
        \$this->info('Command executed successfully!');
        
        // Your command logic here
        
        return self::SUCCESS;
    }
}
EOT;
    }

    /**
     * Convert a class name to a command name.
     *
     * @param string $className
     * @return string
     */
    protected function convertClassToCommandName(string $className): string
    {
        // Remove "Command" suffix if present
        $name = preg_replace('/Command$/', '', $className);

        // Convert camel case to kebab case (e.g., MyFancyCommand -> my-fancy)
        $name = preg_replace('/([a-z])([A-Z])/', '$1-$2', $name);
        $name = strtolower($name);

        return $name;
    }
}