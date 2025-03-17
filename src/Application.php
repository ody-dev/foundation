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
use Ody\Foundation\Http\Request;
use Ody\Foundation\Http\Response;
use Ody\Foundation\Http\ResponseEmitter;
use Ody\Foundation\Providers\ApplicationServiceProvider;
use Ody\Foundation\Providers\ConfigServiceProvider;
use Ody\Foundation\Providers\EnvServiceProvider;
use Ody\Foundation\Providers\FacadeServiceProvider;
use Ody\Foundation\Providers\LoggingServiceProvider;
use Ody\Foundation\Providers\MiddlewareServiceProvider;
use Ody\Foundation\Providers\RouteServiceProvider;
use Ody\Foundation\Providers\ServiceProviderManager;
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
     * Core providers that must be registered in a specific order
     *
     * @var array|string[]
     */
    private array $providers = [
        EnvServiceProvider::class,
        ConfigServiceProvider::class,
        LoggingServiceProvider::class,
        ApplicationServiceProvider::class,
        FacadeServiceProvider::class,
        MiddlewareServiceProvider::class,
        RouteServiceProvider::class,
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

        $response = $this->handleRequest();
        $this->getResponseEmitter()->emit($response);
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
     */
    public function bootstrap(): self
    {
        if ($this->bootstrapped) {
            error_log("Application::bootstrap() already bootstrapped, skipping");
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
        error_log("Application::bootstrap() completed");
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

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->handleRequest($request);

        /**
         * This is to be in compliance with RFC 2616, Section 9.
         * If the incoming request method is HEAD, we need to ensure that the response body
         * is empty as the request may fall back on a GET route handler due to FastRoute's
         * routing logic which could potentially append content to the response body
         * https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html#sec9.4
         */
//        $method = strtoupper($request->getMethod());
//        if ($method === 'HEAD') {
//            $emptyBody = $this->responseFactory->createResponse()->getBody();
//            return $response->withBody($emptyBody);
//        }

        return $response;
    }

    /**
     * Handle HTTP request
     *
     * @param ServerRequestInterface|null $request
     * @return ResponseInterface
     */
    public function handleRequest(?ServerRequestInterface $request = null): ResponseInterface
    {
        // Make sure application is bootstrapped
        if (!$this->bootstrapped) {
            $this->bootstrap();
        }

        // Create request from globals if not provided
        $request = $request ?? Request::createFromGlobals();

        // Log incoming request
        $this->logger->info('Request received', [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'
        ]);

        try {
            // Find matching route
            $routeInfo = $this->getRouter()->match(
                $request->getMethod(),
                $request->getUri()->getPath()
            );

            // Create final handler for the route
            $finalHandler = $this->createRouteHandler($routeInfo);

            // Process the request through middleware
            return $this->getMiddlewareManager()->process(
                $request,
                $request->getMethod(),
                $request->getUri()->getPath(),
                $finalHandler
            );
        } catch (\Throwable $e) {
            $this->logger->error('Error handling request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->handleException($e);
        }
    }

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
     * Create a handler function for the matched route
     *
     * @param array $routeInfo
     * @return callable
     */
    private function createRouteHandler(array $routeInfo): callable
    {
        return function (ServerRequestInterface $request) use ($routeInfo) {
            $response = new Response();

            return match ($routeInfo['status']) {
                'found' => $this->handleFoundRoute($request, $response, $routeInfo),
                'method_not_allowed' => $this->handleMethodNotAllowed($response, $request, $routeInfo),
                default => $this->handleNotFound($response, $request) // not found
            };
        };
    }

    /**
     * Handle a found route
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $routeInfo
     * @return ResponseInterface
     */
    private function handleFoundRoute(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $routeInfo
    ): ResponseInterface
    {
        try {
            // Add route parameters to request attributes
            foreach ($routeInfo['vars'] as $key => $value) {
                $request = $request->withAttribute($key, $value);
            }

            // Call the route handler with the request, response and parameters
            $result = call_user_func(
                $routeInfo['handler'],
                $request,
                $response,
                $routeInfo['vars']
            );

            // If a response was returned, use that
            if ($result instanceof ResponseInterface) {
                return $result;
            }

            // If nothing was returned, return the response
            return $response;
        } catch (Throwable $e) {
            $this->logException($e, 'Error handling request');
            return $this->createErrorResponse($response, 500, 'Internal Server Error');
        }
    }

    /**
     * Handle method not allowed response
     *
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @param array $routeInfo
     * @return ResponseInterface
     */
    private function handleMethodNotAllowed(
        ResponseInterface      $response,
        ServerRequestInterface $request,
        array                  $routeInfo
    ): ResponseInterface
    {
        $allowedMethods = implode(', ', $routeInfo['allowed_methods']);

        $this->logger->warning('Method not allowed', [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'allowed_methods' => $allowedMethods
        ]);

        return $response->withStatus(405)
            ->withHeader('Allow', $allowedMethods)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->createJsonBody([
                'error' => 'Method Not Allowed'
            ]));
    }

    /**
     * Handle not found response
     *
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    private function handleNotFound(
        ResponseInterface      $response,
        ServerRequestInterface $request
    ): ResponseInterface
    {
        return $response->withStatus(404)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->createJsonBody([
                'error' => 'Not Found'
            ]));
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
     * Handle unhandled exceptions
     *
     * @param Throwable $e
     * @return ResponseInterface
     */
    private function handleException(Throwable $e): ResponseInterface
    {
        $this->logException($e, 'Unhandled exception', true);

        $response = new Response();
        return $this->createErrorResponse($response, 500, 'Internal Server Error');
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