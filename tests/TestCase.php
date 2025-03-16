<?php

namespace Tests;

use Ody\Container\Container;
use Ody\Foundation\Application;
use Ody\Foundation\Http\Request;
use Ody\Foundation\Providers\ServiceProviderManager;
use Ody\Support\Config;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

abstract class TestCase extends BaseTestCase
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * @var Application|null
     */
    protected $app;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->createContainer();
    }

    /**
     * Create a fresh container for each test.
     */
    protected function createContainer(): void
    {
        // Reset singleton instance to ensure test isolation
        $this->container = new Container();
        Container::setInstance($this->container);

        // Register a NullLogger for tests
        $this->container->singleton('Psr\Log\LoggerInterface', function () {
            return new NullLogger();
        });
    }

    /**
     * Create an application instance.
     */
    protected function createApplication(bool $bootstrap = true): Application
    {
        if (!isset($this->container)) {
            $this->createContainer();
        }

        // Create minimal config
        $config = new Config([
            'app' => [
                'env' => 'testing',
                'debug' => true,
                'providers' => [],
            ],
        ]);
        $this->container->instance('config', $config);
        $this->container->instance(Config::class, $config);

        // Create the service provider manager
        $providerManager = new ServiceProviderManager($this->container, $config);
        $this->container->instance(ServiceProviderManager::class, $providerManager);

        // Create the application
        $this->app = new Application($this->container, $providerManager);
        $this->container->instance(Application::class, $this->app);

        // Bootstrap the application if requested
        if ($bootstrap) {
            $this->app->bootstrap();
        }

        return $this->app;
    }

    /**
     * Create a request for testing.
     */
    protected function createRequest(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
               $body = null,
        array $cookies = [],
        array $server = []
    ): ServerRequestInterface {
        $request = new Request(
            (new \Nyholm\Psr7\ServerRequest($method, $uri))
                ->withCookieParams($cookies)
        );

        // Add headers
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // Set body if provided
        if ($body !== null) {
            $bodyStream = new \Nyholm\Psr7\Stream(fopen('php://temp', 'r+'));
            if (is_array($body) || is_object($body)) {
                $bodyStream->write(json_encode($body));
                $request = $request->withHeader('Content-Type', 'application/json');
            } else {
                $bodyStream->write((string)$body);
            }
            $bodyStream->rewind();
            $request = $request->withBody($bodyStream);
        }

        // Add server params
        if (!empty($server)) {
            $request = $this->addServerParams($request, $server);
        }

        return $request;
    }

    /**
     * Add server parameters to a request.
     */
    protected function addServerParams(ServerRequestInterface $request, array $server): ServerRequestInterface
    {
        // PSR-7 requests don't provide direct server params modification,
        // so we'll need to create a new request with merged parameters
        $params = array_merge($request->getServerParams(), $server);

        // Use reflection to set the serverParams property
        $requestClass = new \ReflectionClass($request);
        if ($requestClass->hasProperty('serverParams')) {
            $property = $requestClass->getProperty('serverParams');
            $property->setAccessible(true);
            $property->setValue($request, $params);
        }

        return $request;
    }

    /**
     * Get response body as string.
     */
    protected function getResponseBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        return $body->getContents();
    }

    /**
     * Get response body as decoded JSON array.
     */
    protected function getResponseJson(ResponseInterface $response): array
    {
        $body = $this->getResponseBody($response);
        return json_decode($body, true);
    }

    /**
     * Clean up after test.
     */
    protected function tearDown(): void
    {
        $this->container = null;
        $this->app = null;
        Container::setInstance(null);

        parent::tearDown();
    }
}