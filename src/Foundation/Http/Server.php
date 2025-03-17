<?php

namespace Ody\Foundation\Http;

use DI\Container;
use Ody\Core\Foundation\App;
use Ody\Core\Foundation\Bootstrap;
use Ody\Core\Server\Concerns\ServerCallbacks;
use Ody\Swoole\Coroutine\ContextManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swoole\Coroutine;
use Swoole\Http\Request as SwRequest;
use Swoole\Http\Response as SwResponse;
use Swoole\Http\Server as SwServer;

class Server
{
    use ServerCallbacks;

    private static App $app;

    /**
     * @param SwServer $server
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function start(SwServer $server): void
    {
        static::$app = Bootstrap::init(
            App::create(new Container())
        );

        static::$app->bind(SwServer::class, $server);

        $server->start();
    }

    /**
     * @param SwRequest $request
     * @param SwResponse $response
     * @return void
     */
    public static function onRequest(SwRequest $request, SwResponse $response): void
    {
        Coroutine::create(function() use ($request, $response) {
            static::setContext($request);

            (new RequestCallback(
                self::$app
            ))->handle($request, $response);

            // Clean up after request
            self::cleanupAfterRequest();
        });
    }

    private static function cleanupAfterRequest(): void
    {
        // Release any resources that shouldn't persist between requests
        gc_collect_cycles(); // Force garbage collection if memory usage is high
    }

    /**
     * @param SwRequest $request
     * @return void
     */
    private static function setContext(SwRequest $request): void
    {
        ContextManager::set('_GET', (array)$request->get);
        ContextManager::set('_GET', (array)$request->get);
        ContextManager::set('_POST', (array)$request->post);
        ContextManager::set('_FILES', (array)$request->files);
        ContextManager::set('_COOKIE', (array)$request->cookie);
        ContextManager::set('_SERVER', (array)$request->server);
//        ContextManager::set('request', Request::getInstance());
    }
}