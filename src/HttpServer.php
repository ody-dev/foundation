<?php
/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation;

use Ody\Foundation\Http\RequestCallback;
use Ody\Server\State\HttpServerState;
use Ody\Swoole\Coroutine\ContextManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swoole\Coroutine;
use Swoole\Http\Request as SwRequest;
use Swoole\Http\Response as SwResponse;
use Swoole\Http\Server as SwServer;

class HttpServer
{
    private static ?Application $workerApp;

    private static array $workerApplicationMap = [];

    /**
     * @param SwServer $server
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function start(SwServer $server): void
    {
        $server->start();
    }

    /**
     * @param SwRequest $request
     * @param SwResponse $response
     * @return void
     */
    public static function onRequest(SwRequest $request, SwResponse $response): void
    {
        $app = self::$workerApplicationMap[getmypid()];

        Coroutine::create(function () use ($request, $response, $app) {
            static::setContext($request);

            $callback = new RequestCallback($app);
            $callback->handle($request, $response);
        });
    }

    /**
     * @param SwServer $server
     * @param int $workerId
     * @return void
     */
    public static function onWorkerStart(SwServer $server, int $workerId): void
    {
        $workerPid = getmypid();
        logger()->debug('worker start: ' . $workerId);
        $app = Bootstrap::init();

        static::$workerApplicationMap[$workerPid] = $app;

        logger()->debug("[Worker {$workerPid}] initialized and bootstrapped its own Application instance.");

        // Save worker ids to serverState.json
        if ($workerPid == config('server.additional.worker_num') - 1) {
            $workerIds = [];
            for ($i = 0; $i < config('server.additional.worker_num'); $i++) {
                $workerIds[$i] = $server->getWorkerPid($i);
            }

            $serveState = HttpServerState::getInstance();
            $serveState->setMasterProcessId($server->getMasterPid());
            $serveState->setManagerProcessId($server->getManagerPid());
            $serveState->setWorkerProcessIds($workerIds);
        }
    }

    public static function onWorkerError(SwServer $server, int $workerId)
    {
        $workerPid = getmypid();
        logger()->debug('Worker error: ' . $workerPid);
    }

    /**
     * Cleanup when a worker stops.
     *
     * @param SwServer $server
     * @param int $workerId
     * @return void
     */
    public static function onWorkerStop(SwServer $server, int $workerId): void
    {
        $workerPid = getmypid();
        logger()->debug("Worker {$workerId} (PID: {$workerPid}) stopping. Cleaning up container map entry.");

        // Remove the container entry for this specific worker from the map
        if (isset(self::$workerApplicationMap[$workerPid])) {
            // Explicitly unset properties or call destructors if necessary
            // $container = self::$workerContainerMap[$workerPid];
            // $container->callDestructorsOrCleanup();

            unset(self::$workerApplicationMap[$workerPid]);
            logger()->debug("Removed container map entry for PID: {$workerPid}");
        } else {
            logger()->warning("Could not find container map entry for PID {$workerPid} during stop.");
        }
    }

    /**
     * @param SwRequest $request
     * @return void
     */
    public static function setContext(SwRequest $request): void
    {
        ContextManager::set('_GET', (array)$request->get);
        ContextManager::set('_GET', (array)$request->get);
        ContextManager::set('_POST', (array)$request->post);
        ContextManager::set('_FILES', (array)$request->files);
        ContextManager::set('_COOKIE', (array)$request->cookie);
        ContextManager::set('_SERVER', (array)$request->server);
        ContextManager::set('_REQUEST_ID', 'cid:' . uniqid(Coroutine::getCid() . ':', true));
    }
}