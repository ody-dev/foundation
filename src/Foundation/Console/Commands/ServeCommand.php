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
use Ody\Foundation\Bootstrap;
use Ody\Foundation\Router;
use Ody\Foundation\HttpServer;
use Ody\Server\ServerManager;
use Ody\Server\ServerType;
use Symfony\Component\Console\Input\InputOption;

/**
 * ServeCommand
 *
 * Start a development server for the application
 */
class ServeCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'serve';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the development server';

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'The host address to serve on', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'The port to serve on', 8000)
            ->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Number of Swoole workers', 4)
            ->addOption('max-requests', 'm', InputOption::VALUE_OPTIONAL, 'Maximum requests per worker', 1000)
            ->addOption('swoole', 's', InputOption::VALUE_NONE, 'Use Swoole server instead of PHP built-in server');
    }

    /**
     * Handle the command.
     *
     * @return int
     */
    protected function handle(): int
    {
        // Get server configuration
        $config = config('server');

        // Initialize the application
        $app = Bootstrap::init();

        // Don't bootstrap until needed - it will happen on first request
        // $app->bootstrap();

        // Make sure routes are marked as registered
        $router = $this->container->make(Router::class);
        if (method_exists($router, 'markRoutesAsRegistered')) {
            $router->markRoutesAsRegistered();
        }

        error_log("ServeCommand handle(): bootstrapping & starting swoole http");

        // Start the server
        HttpServer::start(
            ServerManager::init(ServerType::HTTP_SERVER) // ServerType::WS_SERVER to start a websocket server
                ->createServer($config)
                ->setServerConfig($config['additional'])
                ->registerCallbacks($config['callbacks'])
                ->getServerInstance()
        );

        return 0;
    }
}