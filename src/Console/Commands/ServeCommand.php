<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Console\Commands;

use Ody\Foundation\Bootstrap;
use Ody\Foundation\Console\Command;
use Ody\Foundation\HttpServer;
use Ody\Foundation\Router;
use Ody\Server\ServerManager;
use Ody\Server\ServerType;
use Ody\Server\State\HttpServerState;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

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
            ->addOption('watch', 'w', InputOption::VALUE_OPTIONAL, 'Enable hot reloading on code changes', '127.0.0.1');
    }

    /**
     * Handle the command.
     *
     * @return int
     */
    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        // Get server configuration
        $config = config('server');

        $serverState = HttpServerState::getInstance();
        if ($serverState->httpServerIsRunning()) {
            $this->handleRunningServer($input, $output);
        }

        // Initialize the application
        Bootstrap::init();

        // Make sure routes are marked as registered
        $router = $this->container->make(Router::class);
        if (method_exists($router, 'markRoutesAsRegistered')) {
            $router->markRoutesAsRegistered();
        }

        // Start the server
        HttpServer::start(
            ServerManager::init(ServerType::HTTP_SERVER) // ServerType::WS_SERVER to start a websocket server
                ->createServer($config)
                ->setServerConfig($config['additional'])
                ->registerCallbacks($config['callbacks'])
                ->setWatcher(true, $config['watch'], $serverState)
                ->getServerInstance()
        );

        return 0;
    }

    private function handleRunningServer(InputInterface $input, OutputInterface $output): void
    {
        logger()->error(
            'failed to listen server port[' . config('server.host') . ':' . config('server.port') . '], Error: Address already in use'
        );

        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion(
            'Do you want the server to terminate? (defaults to no)',
            ['no', 'yes'],
            0
        );
        $question->setErrorMessage('Your selection is invalid.');

        if ($helper->ask($input, $output, $question) !== 'yes') {
            return;
        }

        $serverState = HttpServerState::getInstance();
        $serverState->killProcesses([
            $serverState->getMasterProcessId(),
            $serverState->getManagerProcessId(),
            $serverState->getWatcherProcessId(),
            ...$serverState->getWorkerProcessIds()
        ]);

        $serverState->clearProcessIds();

        sleep(2);
    }
}