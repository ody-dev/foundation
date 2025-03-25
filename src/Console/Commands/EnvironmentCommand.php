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

namespace Ody\Foundation\Console\Commands;

use Ody\Foundation\Console\Command;
use Ody\Support\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * EnvironmentCommand
 *
 * Display information about the current environment
 */
class EnvironmentCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'env';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display environment information';

    /**
     * Handle the command.
     *
     * @return int
     */
    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->displayEnvironmentInfo();
        $this->displayPhpInfo();
        $this->displayFrameworkInfo();
        $this->displayExtensionInfo();

        return self::SUCCESS;
    }

    /**
     * Display environment information.
     *
     * @return void
     */
    protected function displayEnvironmentInfo(): void
    {
        $this->info('Environment Information:');

        $table = $this->table(['Setting', 'Value']);
        $table->addRows([
            ['Environment', env('APP_ENV', 'local')],
            ['Debug Mode', env('APP_DEBUG', false) ? 'Enabled' : 'Disabled'],
            ['Base Path', base_path()],
            ['Storage Path', storage_path()],
            ['Config Path', config_path()],
            ['PHP Version', PHP_VERSION],
            ['PHP SAPI', PHP_SAPI],
            ['OS', PHP_OS],
            ['Server Software', $_SERVER['SERVER_SOFTWARE'] ?? 'N/A']
        ]);
        $table->render();

        // Replace newLine() call with a direct output writeln
        $this->output->writeln('');
    }

    /**
     * Display PHP information.
     *
     * @return void
     */
    protected function displayPhpInfo(): void
    {
        $this->info('PHP Settings:');

        $table = $this->table(['Setting', 'Value']);
        $table->addRows([
            ['memory_limit', ini_get('memory_limit')],
            ['max_execution_time', ini_get('max_execution_time')],
            ['upload_max_filesize', ini_get('upload_max_filesize')],
            ['post_max_size', ini_get('post_max_size')],
            ['display_errors', ini_get('display_errors')],
            ['error_reporting', $this->formatErrorReporting(ini_get('error_reporting'))],
            ['date.timezone', ini_get('date.timezone')],
        ]);
        $table->render();

        // Replace newLine() call with a direct output writeln
        $this->output->writeln('');
    }

    /**
     * Display framework information.
     *
     * @return void
     */
    protected function displayFrameworkInfo(): void
    {
        $this->info('Framework Information:');

        // Get application config
        $config = $this->container->has(Config::class)
            ? $this->container->make(Config::class)
            : null;

        $version = $config ? $config->get('app.version', '1.0.0') : '1.0.0';
        $name = $config ? $config->get('app.name', 'ODY Framework') : 'ODY Framework';

        $table = $this->table(['Setting', 'Value']);
        $table->addRows([
            ['Framework', 'ODY Framework'],
            ['Version', $version],
            ['Application Name', $name],
            ['Running In Console', 'Yes'],
            ['Swoole Support', extension_loaded('swoole') ? 'Available' : 'Not Available'],
            ['Configured Providers', $this->countProviders($config)],
        ]);
        $table->render();

        // Replace newLine() call with a direct output writeln
        $this->output->writeln('');
    }

    /**
     * Display extension information.
     *
     * @return void
     */
    protected function displayExtensionInfo(): void
    {
        $this->info('Key Extensions:');

        $requiredExtensions = [
            'swoole' => 'Swoole (Async Server)',
            'pdo' => 'PDO (Database)',
            'json' => 'JSON',
            'openssl' => 'OpenSSL',
            'mbstring' => 'Multibyte String',
            'tokenizer' => 'Tokenizer',
            'xml' => 'XML',
            'ctype' => 'Ctype',
            'fileinfo' => 'Fileinfo',
            'curl' => 'cURL',
        ];

        $rows = [];
        foreach ($requiredExtensions as $extension => $description) {
            $rows[] = [
                $extension,
                $description,
                extension_loaded($extension) ? 'Installed' : 'Not Installed',
            ];
        }

        $table = $this->table(['Extension', 'Description', 'Status']);
        $table->addRows($rows);
        $table->render();
    }

    /**
     * Format error reporting level to be more human-readable.
     *
     * @param string|int $level
     * @return string
     */
    protected function formatErrorReporting($level): string
    {
        $level = (int)$level;

        if ($level === E_ALL) {
            return 'E_ALL';
        }

        if ($level === 0) {
            return 'None';
        }

        if ($level === -1) {
            return 'All errors';
        }

        return "Level {$level}";
    }

    /**
     * Count registered service providers.
     *
     * @param Config|null $config
     * @return int
     */
    protected function countProviders(?Config $config): int
    {
        if (!$config) {
            return 0;
        }

        $providers = $config->get('app.providers', []);
        return count($providers);
    }
}