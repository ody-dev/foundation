<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Exceptions;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Handler
{
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var bool
     */
    protected bool $debug;

    /**
     * @var array Custom exception renderers
     */
    protected array $renderers = [];

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param bool $debug
     */
    public function __construct(LoggerInterface $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;

        // Register default renderers
        $this->registerDefaultRenderers();
    }

    /**
     * Report an exception
     *
     * @param Throwable $e
     * @return void
     */
    public function report(Throwable $e): void
    {
        $context = [
            'exception' => $e,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];

        $this->logger->error($e->getMessage(), $context);
    }

    /**
     * Render an exception into a response
     *
     * @param ServerRequestInterface $request
     * @param Throwable $e
     * @return ResponseInterface
     */
    public function render(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        // Report the exception
        $this->report($e);

        // Check for custom renderer based on exception class
        foreach ($this->renderers as $exceptionClass => $renderer) {
            if ($e instanceof $exceptionClass) {
                return $renderer($request, $e, $this->debug);
            }
        }

        // Default rendering based on request format
        $format = $this->getRequestFormat($request);

        return $this->renderJsonResponse($request, $e);
    }

    /**
     * Register a custom renderer for an exception type
     *
     * @param string $exceptionClass
     * @param callable $renderer
     * @return self
     */
    public function registerRenderer(string $exceptionClass, callable $renderer): self
    {
        $this->renderers[$exceptionClass] = $renderer;
        return $this;
    }

    /**
     * Register default renderers for common exceptions
     *
     * @return void
     */
    protected function registerDefaultRenderers(): void
    {
        // HttpException renderer
        $this->registerRenderer(HttpException::class, function (ServerRequestInterface $request, HttpException $e, bool $debug) {
            $factory = new Psr17Factory();
            $response = $factory->createResponse($e->getStatusCode());

            $data = [
                'error' => [
                    'status' => $e->getStatusCode(),
                    'title' => $e->getTitle(),
                    'message' => $e->getMessage()
                ]
            ];

            if ($debug) {
                $data['error']['file'] = $e->getFile();
                $data['error']['line'] = $e->getLine();
            }

            $response = $response->withHeader('Content-Type', 'application/json');
            $body = $factory->createStream(json_encode($data));
            return $response->withBody($body);
        });

        // ValidationException renderer
        $this->registerRenderer(ValidationException::class, function (ServerRequestInterface $request, ValidationException $e, bool $debug) {
            $factory = new Psr17Factory();
            $response = $factory->createResponse(422);

            $data = [
                'error' => [
                    'status' => 422,
                    'title' => 'Validation Error',
                    'message' => $e->getMessage(),
                    'errors' => $e->getErrors()
                ]
            ];

            $response = $response->withHeader('Content-Type', 'application/json');
            $body = $factory->createStream(json_encode($data));
            return $response->withBody($body);
        });
    }

    /**
     * Render a JSON response for an exception
     *
     * @param ServerRequestInterface $request
     * @param Throwable $e
     * @return ResponseInterface
     */
    protected function renderJsonResponse(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        $factory = new Psr17Factory();
        $statusCode = $this->getStatusCode($e);
        $response = $factory->createResponse($statusCode);

        // Example using RFC 7807 structure
        $problem = [
//            'type'   => $this->getTypeUri($e), // A URI identifying the problem type (optional)
            'title' => $this->getTitleForException($e), // Short, human-readable summary
            'status' => $statusCode,
            'detail' => $e->getMessage(), // Human-readable explanation
            'instance' => (string)$request->getUri(), // URI that identifies the specific occurrence (optional)
        ];

        // Add more details in debug mode
        if ($this->debug) {
            $problem['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }

        // Add specific error details (e.g., for validation)
        if ($e instanceof ValidationException) {
            $problem['errors'] = $e->getErrors(); // Add validation-specific errors
        }
        // Add more specific details for other custom exception types here...

        $response = $response->withHeader('Content-Type', 'application/problem+json'); // Use standard content type
        $body = $factory->createStream(json_encode($problem, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $response->withBody($body);
    }

    /**
     * Helper to get a URI for the problem type
     *
     * @param Throwable $e
     * @return string
     */
    protected function getTypeUri(Throwable $e): string
    {
        // You might map exception classes to specific documentation URLs
        return 'about:blank'; // Default
    }

    /**
     * Helper to get a concise title
     *
     * @param Throwable $e
     * @return string
     */
    protected function getTitleForException(Throwable $e): string
    {
        if (method_exists($e, 'getTitle')) {
            return $e->getTitle();
        }
        // Fallback based on status code or exception type
        return $this->getStatusText($this->getStatusCode($e));
    }

    /**
     * Get the appropriate status code for an exception
     *
     * @param Throwable $e
     * @return int
     */
    protected function getStatusCode(Throwable $e): int
    {
        if (method_exists($e, 'getStatusCode')) {
            return $e->getStatusCode();
        }

        return 500;
    }

    /**
     * Get the status text for a status code
     *
     * @param int $code
     * @return string
     */
    protected function getStatusText(int $code): string
    {
        $texts = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $texts[$code] ?? 'Unknown Error';
    }

    /**
     * Get the request format based on Accept header
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    protected function getRequestFormat(ServerRequestInterface $request): string
    {
        $accept = $request->getHeaderLine('Accept');

        if (str_contains($accept, 'application/json')) {
            return 'json';
        }

        if (str_contains($accept, 'text/html')) {
            return 'html';
        }

        // Check if it's an XMLHttpRequest
        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return 'json';
        }

        // Default to HTML
        return 'html';
    }
}