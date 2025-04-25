<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Stratigility\MiddlewarePipe;
use Mockery\Exception;
use Ody\Container\Container;
use Ody\Container\Contracts\BindingResolutionException;
use Ody\Foundation\Http\HandlerPool;
use Ody\Foundation\Http\HandlerResolver;
use Ody\Foundation\Http\Request;
use Ody\Foundation\Http\ResponseEmitter;
use Ody\Foundation\Middleware\MiddlewareManager;
use Ody\Foundation\Middleware\MiddlewareResolver;
use Ody\Foundation\Providers\ApplicationServiceProvider;
use Ody\Foundation\Providers\ConfigServiceProvider;
use Ody\Foundation\Providers\EnvServiceProvider;
use Ody\Foundation\Providers\LoggingServiceProvider;
use Ody\Foundation\Providers\ServiceProviderManager;
use Ody\Foundation\Router\Router;
use Ody\Logger\StreamLogger;
use Ody\Swoole\Coroutine\ContextManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Main application class with integrated bootstrapping
 */
class Application implements RequestHandlerInterface
{
    /**
     * @var Router|null
     */
    private ?Router $router = null;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ResponseEmitter|null
     */
    private ?ResponseEmitter $responseEmitter = null;

    /**
     * @var MiddlewareManager|null
     */
    private ?MiddlewareManager $middlewareManager = null;

    /**
     * @var bool Whether the application has been bootstrapped
     */
    private bool $bootstrapped = false;

    /**
     * Core providers that must be registered in a specific order
     *
     * @var array|string[]
     */
    private array $providers = [
        EnvServiceProvider::class,
        ConfigServiceProvider::class,
        LoggingServiceProvider::class,
        ApplicationServiceProvider::class,
    ];

    /**
     * Application constructor with reduced dependencies
     *
     * @param Container $container
     * @param ServiceProviderManager $providerManager
     *
     * TODO: Use DI for logger & config
     */
    public function __construct(
        private readonly Container              $container,
        private readonly ServiceProviderManager $providerManager
    )
    {
        $this->logger = new StreamLogger('php://stdout');

        // Register self in container
        $this->container->instance(Application::class, $this);
        $this->container->alias(Application::class, 'app');
    }

    /**
     * Get logger
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        if ($this->container->has(LoggerInterface::class)) {
            return $this->container->get(LoggerInterface::class);
        }

        return $this->logger;
    }

    /**
     * Get container
     *
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Run the application
     *
     * @return void
     * @throws BindingResolutionException|Throwable
     */
    public function run(): void
    {
        // Ensure application is bootstrapped
        if (!$this->bootstrapped) {
            $this->bootstrap();
        }

        $request = Request::createFromGlobals();
        $response = $this->handle($request);
        $this->getResponseEmitter()->emit($response);

        // Run terminating middleware after the response has been sent
        $this->getMiddlewareManager()->terminate($request, $response);
    }

    /**
     * Check if the application has been bootstrapped
     *
     * @return bool
     */
    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    /**
     * Bootstrap the application by loading providers
     *
     * @return self
     * @throws Throwable
     */
    public function bootstrap(): self
    {
        if ($this->bootstrapped) {
            $this->logger->debug("Application::bootstrap() already bootstrapped, skipping");
            return $this;
        }

        $this->registerCoreProviders();

        $this->providerManager->registerConfigProviders('app.providers.http');

        $this->providerManager->boot();

        if ($this->container->has(LoggerInterface::class)) {
            $this->logger = $this->container->get(LoggerInterface::class);
        }

        $this->configureHandlerCaching();

        $this->precacheHandlers();

        $this->bootstrapped = true;
        return $this;
    }

    /**
     * Configure handler caching based on application configuration
     */
    protected function configureHandlerCaching(): void
    {
        $config = $this->container->make('config');
        $enableCaching = $config->get('app.handler_cache.enabled', true);
        $excludedHandlers = $config->get('app.handler_cache.excluded', []);
        $handlerPool = $this->container->get(HandlerPool::class);

        ($enableCaching) ?
            $handlerPool->enableCaching() :
            $handlerPool->disableCaching();

        // Register excluded handlers
        if (!empty($excludedHandlers)) {
            $handlerPool->excludeHandlers($excludedHandlers);
        }
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws BindingResolutionException
     */
    public function precacheHandlers(): void
    {
        // Get configuration directly if needed, or assume pool is configured
        $config = $this->container->get('config');
        $enableCaching = $config->get('app.handler_cache.enabled', true);

        if (!$enableCaching) {
            $this->logger->debug("Handler precaching skipped (caching disabled via config)");
            return;
        }

        /** @var HandlerPool $handlerPool */
        $handlerPool = $this->container->get(HandlerPool::class); // Use get() or make()

        /** @var Router $router */
        $router = $this->container->make(Router::class);
        $routes = $router->getRoutes();

        foreach ($routes as $route) {
            $handler = $route[2]; // The handler

            if (is_string($handler)) {
                try {
                    if ($handlerPool->handlerIsCached($handler)) {
                        continue;
                    }

                    $handlerPool->get($handler);
                    $workerId = getmypid();
                    $this->logger->debug("[Worker {$workerId}] Precaching handler: {$handler}");
                } catch (Throwable $e) {
                    $this->logger->error("Failed to precache handler {$handler}", ['error' => $e->getMessage()]);
                }
            } else {
                throw new Exception('Application::precacheControllers: error TODO: we should not get here');
            }
        }
    }

    /**
     * Register core framework service providers
     *
     * @return void
     * @throws Throwable
     */
    protected function registerCoreProviders(): void
    {
        foreach ($this->providers as $provider) {
            if (class_exists($provider)) {
                $this->providerManager->register($provider);
            }
        }
    }

    /**
     * Handle a request
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws BindingResolutionException
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->container->instance(ServerRequestInterface::class, $request);

            $router = $this->getRouter();
            $routeInfo = $router->match($request->getMethod(), $request->getUri()->getPath());

            if ($routeInfo['status'] === 'not_found') {
                return $this->handleNotFound($request);
            }

            if ($routeInfo['status'] === 'method_not_allowed') {
                return $this->handleMethodNotAllowed($routeInfo['allowed_methods'] ?? []);
            }

            $routeParams = $routeInfo['vars'] ?? [];
            $handlerClass = $routeInfo['handler'] ?? null;

            // Add route parameters to the request
            foreach ($routeParams as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }

            ContextManager::set('_handler', $routeInfo['handler']);

            if ($handlerClass) {
                $handlerInstance = $this->getHandlerResolver()->createHandler($handlerClass);

                return $this->dispatch($request, $handlerInstance);
            }

            $this->logger->error("Application::handle: Invalid route handler configuration detected for path.", ['routeInfo' => $routeInfo]);
            return $this->handleNotFound($request); // Or a 500 error
        } catch (Throwable $e) {
            return $this->handleException($request, $e);
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $finalHandler
     * @return ResponseInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function dispatch(
        ServerRequestInterface $request,
        RequestHandlerInterface $finalHandler
    ): ResponseInterface {
        /** @var MiddlewareResolver $middlewareResolver */
        $middlewareResolver = $this->container->get(MiddlewareResolver::class);

        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);

        $middlewareStack = $middlewareResolver->getMiddlewareForRoute(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $finalHandler
        );

        $pipeline = new MiddlewarePipe();

        foreach ($middlewareStack as $middlewareDefinition) {
            try {
                $middlewareInstance = $middlewareResolver->resolve($middlewareDefinition);
                $pipeline->pipe($middlewareInstance);
            } catch (\Throwable $e) {
                $logger->error('dispatch: Failed to resolve/pipe middleware', [
                    'definition' => $middlewareDefinition,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        return $pipeline->process($request, $finalHandler);
    }

    /**
     * Get the middleware manager
     *
     * @return MiddlewareManager
     * @throws BindingResolutionException
     */
    public function getMiddlewareManager(): MiddlewareManager
    {
        if ($this->middlewareManager === null && $this->container->has(MiddlewareManager::class)) {
            $this->middlewareManager = $this->container->make(MiddlewareManager::class);
        }

        return $this->middlewareManager;
    }

    /**
     * Handle a route not found error
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function handleNotFound(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'error' => 'Not Found',
            'message' => 'The requested resource was not found',
            'path' => $request->getUri()->getPath()
        ], 404);
    }

    /**
     * Handle a method not allowed error
     *
     * @param array<string> $allowedMethods
     * @return ResponseInterface
     */
    protected function handleMethodNotAllowed(array $allowedMethods): ResponseInterface
    {
        return new JsonResponse([
            'error' => 'Method Not Allowed',
            'message' => 'The requested method is not allowed for this resource',
            'allowed_methods' => $allowedMethods
        ], 405, [
            'Allow' => implode(', ', $allowedMethods)
        ]);
    }

    /**
     * Handle an exception
     *
     * @param ServerRequestInterface $request
     * @param Throwable $e
     * @return ResponseInterface
     * @throws BindingResolutionException
     */
    protected function handleException(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        // Log the exception
        $this->container->make('logger')->error('Application Exception: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        // Use the exception handler if available
        if ($this->container->has('error.handler')) {
            $handler = $this->container->make('error.handler');
            return $handler->render($request, $e);
        }

        // Default error response
        $debug = env('APP_DEBUG', false);

        $errorData = [
            'error' => 'Server Error',
            'message' => $debug ? $e->getMessage() : 'An error occurred while processing your request'
        ];

        if ($debug) {
            $errorData['exception'] = get_class($e);
            $errorData['file'] = $e->getFile();
            $errorData['line'] = $e->getLine();
            $errorData['trace'] = explode("\n", $e->getTraceAsString());
        }

        return new JsonResponse($errorData, 500);
    }

    /**
     * Get router instance from the container
     *
     * @return Router
     * @throws BindingResolutionException
     */
    public function getRouter(): Router
    {
        if ($this->router === null) {
            $this->router = $this->container->make(Router::class);
        }

        return $this->router;
    }

    /**
     * Get response emitter (lazy-loaded)
     *
     * @return ResponseEmitter
     * @throws BindingResolutionException
     */
    public function getResponseEmitter(): ResponseEmitter
    {
        if ($this->responseEmitter === null) {
            $this->responseEmitter = new ResponseEmitter(
                $this->container->make(LoggerInterface::class),
                true,
                8192
            );
        }

        return $this->responseEmitter;
    }

    /**
     * Get the handler resolver
     * @throws BindingResolutionException
     */
    protected function getHandlerResolver(): HandlerResolver
    {
        if ($this->container->has(HandlerResolver::class)) {
            return $this->container->make(HandlerResolver::class);
        }

        // Fallback creation (ensure dependencies like LoggerInterface, HandlerPool are available)
        return new HandlerResolver(
            $this->container->make(LoggerInterface::class),
            $this->container->make(HandlerPool::class)
        );
    }
}