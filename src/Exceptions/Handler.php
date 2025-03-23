<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Exceptions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Nyholm\Psr7\Factory\Psr17Factory;

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
            'exception' => get_class($e),
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

        if ($format === 'json') {
            return $this->renderJsonResponse($request, $e);
        }

        return $this->renderHtmlResponse($request, $e);
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

            if ($this->getRequestFormat($request) === 'json') {
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
            }

            // Html response
            $content = $this->renderExceptionHtml($e, $debug);
            $response = $response->withHeader('Content-Type', 'text/html');
            $body = $factory->createStream($content);
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

        $data = [
            'error' => [
                'status' => $statusCode,
                'message' => $this->debug ? $e->getMessage() : 'Server Error'
            ]
        ];

        if ($this->debug) {
            $data['error']['exception'] = get_class($e);
            $data['error']['file'] = $e->getFile();
            $data['error']['line'] = $e->getLine();
            $data['error']['trace'] = explode("\n", $e->getTraceAsString());
        }

        $response = $response->withHeader('Content-Type', 'application/json');
        $body = $factory->createStream(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withBody($body);
    }

    /**
     * Render an HTML response for an exception
     *
     * @param ServerRequestInterface $request
     * @param Throwable $e
     * @return ResponseInterface
     */
    protected function renderHtmlResponse(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        $factory = new Psr17Factory();
        $statusCode = $this->getStatusCode($e);
        $response = $factory->createResponse($statusCode);

        $content = $this->renderExceptionHtml($e, $this->debug);

        $response = $response->withHeader('Content-Type', 'text/html');
        $body = $factory->createStream($content);
        return $response->withBody($body);
    }

    /**
     * Render an exception as HTML
     *
     * @param Throwable $e
     * @param bool $debug
     * @return string
     */
    protected function renderExceptionHtml(Throwable $e, bool $debug): string
    {
        $statusCode = $this->getStatusCode($e);
        $title = $statusCode . ' | ' . $this->getStatusText($statusCode);

        if (!$debug) {
            // Simple error page for production
            return '<!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>' . $title . '</title>
                    <style>
                        body { font-family: system-ui, -apple-system, sans-serif; line-height: 1.5; padding: 2rem; max-width: 40rem; margin: 0 auto; color: #333; }
                        h1 { margin-top: 0; font-size: 1.5rem; }
                        .container { padding: 2rem; border-radius: 4px; background: #f8f9fa; border: 1px solid #dee2e6; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <h1>' . $title . '</h1>
                        <p>The server encountered an error and could not complete your request.</p>
                    </div>
                </body>
                </html>';
        }

        // Detailed error page for development
        $html = '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>' . $title . '</title>
                <style>
                    body { font-family: system-ui, -apple-system, sans-serif; line-height: 1.5; padding: 1rem; color: #333; margin: 0; }
                    h1 { margin-top: 0; font-size: 1.5rem; }
                    .container { max-width: 90%; margin: 0 auto; }
                    .error-header { padding: 1rem; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 1rem; }
                    .stack-trace { background: #f8f9fa; padding: 1rem; border-radius: 4px; overflow-x: auto; border: 1px solid #dee2e6; font-family: monospace; font-size: 0.9rem; white-space: pre-wrap; }
                    .frame { padding: 0.5rem; border-bottom: 1px solid #dee2e6; }
                    .frame:last-child { border-bottom: none; }
                    .frame-file { color: #6c757d; }
                    .frame-line { color: #dc3545; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="error-header">
                        <h1>' . htmlspecialchars($title) . '</h1>
                        <p><strong>' . htmlspecialchars(get_class($e)) . '</strong>: ' . htmlspecialchars($e->getMessage()) . '</p>
                        <p><strong>File</strong>: ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>
                    </div>
                    <div class="stack-trace">';

        // Format stack trace
        $trace = $e->getTrace();
        foreach ($trace as $i => $frame) {
            $file = $frame['file'] ?? '[internal function]';
            $line = $frame['line'] ?? '';
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? '';

            $html .= '<div class="frame">';
            $html .= '<span class="frame-index">' . $i . '.</span> ';
            if ($class) {
                $html .= htmlspecialchars($class . $type . $function) . '()';
            } else {
                $html .= htmlspecialchars($function) . '()';
            }
            $html .= '<div class="frame-file">' . htmlspecialchars($file);
            if ($line) {
                $html .= ':<span class="frame-line">' . $line . '</span>';
            }
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>
                </div>
            </body>
            </html>';

        return $html;
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