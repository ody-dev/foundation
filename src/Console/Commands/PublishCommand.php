<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Console\Commands;

use Ody\Foundation\Console\Command;
use Ody\Foundation\Publishing\Publisher;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PublishCommand extends Command
{
    protected $name = 'publish';
    protected $description = 'Publish assets from packages to the application';

    protected function configure(): void
    {
        // Add arguments after parent::configure
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force publish, overwriting existing files')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Specific type to publish (config, migrations)');

        // Add the argument last
        $this->addArgument(
            'package',
            InputArgument::OPTIONAL,
            'Package name to publish'
        );
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $package = $input->getArgument('package');
        $force = $input->getOption('force');

        $publisher = $this->container->make(Publisher::class);
        $published = $publisher->publish($package, $force);

        if (empty($published)) {
            $this->info('No assets were published.');
            if ($package) {
                $this->comment("Package '{$package}' might not exist or have publishable assets.");
            }
            return self::SUCCESS;
        }

        $this->success('Assets published successfully!');

        return self::SUCCESS;
    }
}