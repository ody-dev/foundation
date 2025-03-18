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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * TestCommand
 *
 * A simple command to test console functionality
 */
class TestCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'A test command to ensure console system is working';

    /**
     * Handle the command.
     *
     * @return int
     */
    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->success('Console system is working correctly!');

        $this->info('Application details:');
        $this->line('- Running in console: Yes');
        $this->line('- PHP Version: ' . PHP_VERSION);
        $this->line('- Server API: ' . PHP_SAPI);

        if ($this->app) {
            $this->line('- Application initialized: Yes');
        } else {
            $this->warning('- Application not initialized');
        }

        if ($this->container) {
            $this->line('- Container initialized: Yes');
            $this->line('- Container has ' . count($this->container->getBindings()) . ' bindings');
        } else {
            $this->warning('- Container not initialized');
        }

        return self::SUCCESS;
    }
}