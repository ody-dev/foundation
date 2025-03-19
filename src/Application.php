<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation;

use Ody\Container\Container;
use Ody\Foundation\Http\ControllerDispatcher;
use Ody\Foundation\Http\ControllerResolver;
use Ody\Foundation\Http\Request;
use Ody\Foundation\Http\Response;
use Ody\Foundation\Http\ResponseEmitter;
use Ody\Foundation\Middleware\AttributeResolver;
use Ody\Foundation\Providers\ApplicationServiceProvider;
use Ody\Foundation\Providers\ConfigServiceProvider;
use Ody\Foundation\Providers\EnvServiceProvider;
use Ody\Foundation\Providers\LoggingServiceProvider;
use Ody\Foundation\Providers\ServiceProviderManager;
use Ody\Swoole\Coroutine\ContextManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Main application class with integrated bootstrapping
 */
class Application implements \Psr\Http\Server\RequestHandlerInterface
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

    // Add these properties to the class
    /**
     * @var bool Indicates if the application is running in console
     */
    private bool $runningInConsole = false;

    /**
     * @var bool Indicates if console detection has been performed
     */
    private bool $consoleDetected = false;

    /**
     * @var bool Whether the application has been bootstrapped
     */
    private bool $bootstrapped = false;

    /**
     * @var ControllerDispatcher|null
     */
    protected ?ControllerDispatcher $controllerDispatcher = null;

    /**
     * Core providers that must be registered in a specific order
     *
     * @var array|string[]
     */
    private array $providers = [
        EnvServiceProvider::class,
        ConfigServiceProvider::class,
        LoggingServiceProvider::class,
        ApplicationServiceProvider::class
    ];

    /**
     * Application constructor with reduced dependencies
     *
     * @param Container $container
     * @param ServiceProviderManager $providerManager
     */
    public function __construct(
        private readonly Container              $container,
        private readonly ServiceProviderManager $providerManager
    )
    {
        // Use a NullLogger temporarily until a real logger is registered
        $this->logger = new NullLogger();

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

// And modify the bootstrap method:

    /**
     * Bootstrap the application by loading providers
     *
     * @return self
     * @throws Throwable
     */
    public function bootstrap(): self
    {
        if ($this->bootstrapped) {
            logger()->debug("Application::bootstrap() already bootstrapped, skipping");
            return $this;
        }

        // Load core service providers
        $this->registerCoreProviders();

        // Register providers from configuration
        $this->providerManager->registerConfigProviders('app.providers');

        // Boot all registered providers
        $this->providerManager->boot();

        // Initialize core components lazily (only created when first accessed)
        $this->initializeCoreComponents();

        $this->bootstrapped = true;
        logger()->debug("Application::bootstrap() completed");
        return $this;
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
            // Only register if class exists (allows for optional components)
            if (class_exists($provider)) {
                $this->providerManager->register($provider);
            }
        }
    }

    /**
     * @deprecated Is not used anymore
     * Initialize core components lazily using container callbacks
     *
     * @return void
     */
    protected function initializeCoreComponents(): void
    {
        // Deprecated?
    }

    /**
     * Handle a request
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // Add the request to the container
            $this->container->instance(ServerRequestInterface::class, $request);

            // Match the route
            $router = $this->container->make(Router::class);
            $routeInfo = $router->match($request->getMethod(), $request->getUri()->getPath());

            // Handle route not found
            if ($routeInfo['status'] === 'not_found') {
                return $this->handleNotFound($request);
            }

            // Handle method not allowed
            if ($routeInfo['status'] === 'method_not_allowed') {
                return $this->handleMethodNotAllowed($request, $routeInfo['allowed_methods'] ?? []);
            }

            // If we found a route, get the handler
            $handler = $routeInfo['handler'];
            $routeParams = $routeInfo['vars'] ?? [];

            // Add route parameters to the request
            foreach ($routeParams as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }

            // Check if this is a controller route with attribute support
            if (isset($routeInfo['controller']) && isset($routeInfo['action'])) {
                ContextManager::set('_controller', $routeInfo['controller']);
                ContextManager::set('_action', $routeInfo['action']);
                return $this->dispatchToController($request, $routeInfo['controller'], $routeInfo['action'], $routeParams);
            }

            // Otherwise, handle as a regular route with regular middleware
            return $this->dispatchWithMiddleware($request, $handler, $routeParams);

        } catch (\Throwable $e) {
            return $this->handleException($request, $e);
        }
    }

    /**
     * Dispatch a request to a controller
     *
     * @param ServerRequestInterface $request
     * @param string $controller
     * @param string $action
     * @param array $routeParams
     * @return ResponseInterface
     */
    protected function dispatchToController(
        ServerRequestInterface $request,
        string $controller,
        string $action,
        array $routeParams
    ): ResponseInterface {
        // Get or create the controller dispatcher
        $dispatcher = $this->getControllerDispatcher();

        // Dispatch the request to the controller
        return $dispatcher->dispatch($request, $controller, $action, $routeParams);
    }

    /**
     * Dispatch a request with middleware
     *
     * @param ServerRequestInterface $request
     * @param callable $handler
     * @param array $routeParams
     * @return ResponseInterface
     */
    protected function dispatchWithMiddleware(
        ServerRequestInterface $request,
        callable $handler,
        array $routeParams
    ): ResponseInterface {
        // Get middleware stack for the route
        $middlewareManager = $this->getMiddlewareManager();
        $middlewareStack = $middlewareManager->getStackForRoute(
            $request->getMethod(),
            $request->getUri()->getPath()
        );

        // Create a response object for the handler
        $response = new Response();

        // Create a middleware pipeline
        $finalHandler = function (ServerRequestInterface $request) use ($handler, $response, $routeParams) {
            // Pass both response and route params
            return call_user_func($handler, $request, $response, $routeParams);
        };

        $registry = $middlewareManager->getRegistry();
        $pipeline = new Middleware\MiddlewarePipeline(
            $registry,
            $middlewareStack,
            $finalHandler,
            $this->container->make('logger')
        );

        // Process the request through the middleware pipeline
        return $pipeline->handle($request);
    }

    /**
     * Get the controller dispatcher
     *
     * @return ControllerDispatcher
     */
    protected function getControllerDispatcher(): ControllerDispatcher
    {
        if (!$this->controllerDispatcher) {
            $this->controllerDispatcher = new ControllerDispatcher(
                $this->container,
                new ControllerResolver($this->container, $this->container->make('logger')),
                new AttributeResolver($this->container->make('logger')),
                $this->getMiddlewareManager(),
                $this->container->make('logger')
            );
        }

        return $this->controllerDispatcher;
    }

    /**
     * Get the middleware manager
     *
     * @return MiddlewareManager
     */
//    public function getMiddlewareManager(): MiddlewareManager
//    {
//        return $this->container->make(MiddlewareManager::class);
//    }

    /**
     * Handle a route not found error
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
//    protected function handleNotFound(ServerRequestInterface $request): ResponseInterface
//    {
//        return (new Response())
//            ->status(404)
//            ->json()
//            ->withJson([
//                'error' => 'Not Found',
//                'message' => 'The requested resource was not found',
//                'path' => $request->getUri()->getPath()
//            ]);
//    }

    /**
     * Handle a method not allowed error
     *
     * @param ServerRequestInterface $request
     * @param array $allowedMethods
     * @return ResponseInterface
     */
//    protected function handleMethodNotAllowed(ServerRequestInterface $request, array $allowedMethods): ResponseInterface
//    {
//        return (new Response())
//            ->status(405)
//            ->withHeader('Allow', implode(', ', $allowedMethods))
//            ->json()
//            ->withJson([
//                'error' => 'Method Not Allowed',
//                'message' => 'The requested method is not allowed for this resource',
//                'allowed_methods' => $allowedMethods
//            ]);
//    }

    /**
     * Handle an exception
     *
     * @param ServerRequestInterface $request
     * @param \Throwable $e
     * @return ResponseInterface
     */
    protected function handleException(ServerRequestInterface $request, \Throwable $e): ResponseInterface
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

        return (new Response())
            ->status(500)
            ->json()
            ->withJson($errorData);
    }

//    public function handle(ServerRequestInterface $request): ResponseInterface
//    {
//        $response = $this->handleRequest($request);
//
//        /**
//         * This is to be in compliance with RFC 2616, Section 9.
//         * If the incoming request method is HEAD, we need to ensure that the response body
//         * is empty as the request may fall back on a GET route handler due to FastRoute's
//         * routing logic which could potentially append content to the response body
//         * https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html#sec9.4
//         */
////        $method = strtoupper($request->getMethod());
////        if ($method === 'HEAD') {
////            $emptyBody = $this->responseFactory->createResponse()->getBody();
////            return $response->withBody($emptyBody);
////        }
//
//        return $response;
//    }
//
//    /**
//     * Handle HTTP request
//     *
//     * @param ServerRequestInterface|null $request
//     * @return ResponseInterface
//     */
//    public function handleRequest(?ServerRequestInterface $request = null): ResponseInterface
//    {
//        // Make sure application is bootstrapped
//        if (!$this->bootstrapped) {
//            $this->bootstrap();
//        }
//
//        // Create request from globals if not provided
//        $request = $request ?? Request::createFromGlobals();
//
//        // Log incoming request
//        $this->logRequest($request);
//
//        try {
//            // Find matching route
//            $routeInfo = $this->getRouter()->match(
//                $request->getMethod(),
//                $request->getUri()->getPath()
//            );
//
//            // Create final handler for the route
//            $finalHandler = $this->createRouteHandler($routeInfo);
//
//            // Process the request through middleware
//            $response = $this->getMiddlewareManager()->process(
//                $request,
//                $request->getMethod(),
//                $request->getUri()->getPath(),
//                $finalHandler
//            );
//
//            return $response;
//        } catch (\Throwable $e) {
//            $this->logger->error('Error handling request', [
//                'error' => $e->getMessage(),
//                'trace' => $e->getTraceAsString()
//            ]);
//
//            return $this->handleException($e);
//        }
//    }

    /**
     * Log the incoming request details
     *
     * @param ServerRequestInterface $request
     * @return void
     */
    private function logRequest(ServerRequestInterface $request): void
    {
        $this->logger->info('Request received', [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }

    /**
     * Get middleware manager instance
     *
     * @return MiddlewareManager
     */
    public function getMiddlewareManager(): MiddlewareManager
    {
        if ($this->middlewareManager === null) {
            $this->middlewareManager = $this->container->make(MiddlewareManager::class);
        }

        return $this->middlewareManager;
    }

    /**
     * Get router (lazy-loaded)
     *
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->container->make(Router::class);
    }

    /**
     * Handle method not allowed response
     *
     * @param ServerRequestInterface $request
     * @param array $allowedMethods
     * @return ResponseInterface
     */
    protected function handleMethodNotAllowed(ServerRequestInterface $request, array $allowedMethods): ResponseInterface
    {
        return (new Response())
            ->status(405)
            ->withHeader('Allow', implode(', ', $allowedMethods))
            ->json()
            ->withJson([
                'error' => 'Method Not Allowed',
                'message' => 'The requested method is not allowed for this resource',
                'allowed_methods' => $allowedMethods
            ]);
    }

    /**
     * Handle not found response
     *
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function handleNotFound(ServerRequestInterface $request): ResponseInterface
    {
        return (new Response())
            ->status(404)
            ->json()
            ->withJson([
                'error' => 'Not Found',
                'message' => 'The requested resource was not found',
                'path' => $request->getUri()->getPath()
            ]);
    }

    /**
     * Get response emitter (lazy-loaded)
     *
     * @return ResponseEmitter
     */
    public function getResponseEmitter(): ResponseEmitter
    {
        return new ResponseEmitter(
            $this->container->make(LoggerInterface::class),
            true,
            8192
        );
    }

    /**
     * Determine if the application is running in the console.
     *
     * @return bool
     */
    public function runningInConsole(): bool
    {
        return $this->container->get('runningInConsole');
    }

    /**
     * Log exception details
     *
     * @param Throwable $e
     * @param string $message
     * @param bool $includeTrace
     * @return void
     */
    private function logException(Throwable $e, string $message, bool $includeTrace = false): void
    {
        $logData = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];

        if ($includeTrace) {
            $logData['trace'] = $e->getTraceAsString();
        }

        logger()->error($message, $logData);
    }

    /**
     * Create a JSON error response
     *
     * @param ResponseInterface $response
     * @param int $status
     * @param string $message
     * @return ResponseInterface
     */
    private function createErrorResponse(
        ResponseInterface $response,
        int               $status,
        string            $message
    ): ResponseInterface
    {
        return $response->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->createJsonBody([
                'error' => $message
            ]));
    }

    /**
     * Create a JSON response body
     *
     * @param array $data
     * @return StreamInterface
     */
    private function createJsonBody(array $data): StreamInterface
    {
        $factory = $this->container->make('Psr\Http\Message\StreamFactoryInterface');
        return $factory->createStream(json_encode($data));
    }
}