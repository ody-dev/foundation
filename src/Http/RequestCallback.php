<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Ody\Foundation\Application;
use Ody\Swoole\Coroutine\ContextManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class RequestCallback
{
    private RequestHandlerInterface $handler;
    private RequestCallbackOptions $options;

    public function __construct(Application $handler, ?RequestCallbackOptions $options = null)
    {
        $this->handler = $handler;
        $this->options = $options ?? new RequestCallbackOptions();
    }

    public function handle(Request $request, Response $response): void
    {
        // Get the request ID from context if available
        $requestId = ContextManager::get('_REQUEST_ID') ?? 'unknown';

        try {
            // Convert Swoole request to PSR-7
            $serverRequest = $this->createServerRequest($request);

            // Log the request start with request ID
            logger()->debug("Processing request", [
                'request_id' => $requestId,
                'method' => $serverRequest->getMethod(),
                'path' => $serverRequest->getUri()->getPath()
            ]);

            // Directly handle the request without reinitializing
            $psrResponse = $this->handler->handle($serverRequest);

            // Add the request ID to the response header for tracing
            $psrResponse = $psrResponse->withHeader('X-Request-ID', $requestId);

            // Convert PSR-7 response to Swoole response
            $this->emit($psrResponse, $response);
        } catch (\Throwable $e) {
            $logger = $this->handler instanceof Application
                ? $this->handler->getLogger()
                : null;

            $errorContext = [
                'request_id' => $requestId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_method' => $request->server['request_method'] ?? 'UNKNOWN',
                'request_uri' => $request->server['request_uri'] ?? 'UNKNOWN',
                'client_ip' => $request->server['remote_addr'] ?? 'UNKNOWN'
            ];

            if ($logger) {
                $logger->error('Unhandled exception in request handling', $errorContext);
            } else {
                logger()->critical('Unhandled exception in request handling', $errorContext);
            }

            // Send error response with request ID
            $response->status(500);
            $response->header('Content-Type', 'application/json');
            $response->header('X-Request-ID', $requestId);
            $response->end(json_encode([
                'error' => 'Internal Server Error',
                'message' => env('APP_DEBUG', false) ? $e->getMessage() : 'Server Error',
                'request_id' => $requestId
            ]));
        } finally {
            // Ensure middleware is terminated
            if (isset($serverRequest) && isset($psrResponse)) {
                // Pass the request ID for targeted context cleanup
                $this->handler->getMiddlewareManager()->terminate($serverRequest, $psrResponse);
            }

            // Optional: clear just this specific context key if not handled by middleware manager
            // ContextManager::delete('request_id');
        }
    }

    private function createServerRequest(Request $swooleRequest): ServerRequestInterface
    {
        /** @var array<string, string> $server */
        $server = $swooleRequest->server;

        /** @var array<array> | array<empty> $files */
        $files = $swooleRequest->files ?? [];

        /** @var array<string, string> | array<empty> $headers */
        $headers = $swooleRequest->header ?? [];

        /** @var array<string, string> | array<empty> $cookies */
        $cookies = $swooleRequest->cookie ?? [];

        /** @var array<string, string> | array<empty> $query_params */
        $query_params = $swooleRequest->get ?? [];

        // Debug the path being processed
        $path = $server['request_uri'] ?? '/';
        $method = $server['request_method'] ?? 'GET';

        // Create the request body stream from rawContent
        $rawBody = $swooleRequest->rawContent();
        $bodyStream = $this->options->getStreamFactory()->createStream($rawBody);

        // Create the PSR-7 server request
        $serverRequest = new ServerRequest(
            $server,
            normalizeUploadedFiles($files),
            $path,
            $method,
            $bodyStream,
            $headers,
            $cookies,
            $query_params
        );

        // Parse the body based on content type if needed
        $parsedBody = null;
        $contentType = $swooleRequest->header['content-type'] ?? '';

        // For JSON requests
        if (strpos($contentType, 'application/json') !== false && !empty($rawBody)) {
            $parsedBody = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $serverRequest = $serverRequest->withParsedBody($parsedBody);
            } else {
                logger()->error("RequestCallback: Failed to parse JSON body: " . json_last_error_msg());
            }
        }
        // For form data requests
        else if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            $parsedBody = $swooleRequest->post ?? [];
            if (!empty($parsedBody)) {
                $serverRequest = $serverRequest->withParsedBody($parsedBody);
                error_log("RequestCallback: Using form data from swoole request");
            }
        }
        // For multipart form data
        // Inside the createServerRequest method where you handle content types:
        else if (strpos($contentType, 'multipart/form-data') !== false) {
            $parsedBody = $swooleRequest->post ?? [];

            // Properly normalize and process uploaded files
            $files = [];
            if (!empty($swooleRequest->files)) {
                // Normalize the files array to match PSR-7 structure
                $files = normalizeUploadedFiles($swooleRequest->files);

                // Add debug logging
                logger()->debug('Processed file uploads in multipart request', [
                    'count' => count($files),
                    'keys' => array_keys($swooleRequest->files)
                ]);
            }

            // Update server request with parsed body and files
            $serverRequest = $serverRequest
                ->withParsedBody($parsedBody)
                ->withUploadedFiles($files);
        }

        return $serverRequest;
    }


    private function emit(ResponseInterface $psrResponse, Response $swooleResponse): void
    {
        $swooleResponse->setStatusCode($psrResponse->getStatusCode(), $psrResponse->getReasonPhrase());

        foreach ($psrResponse->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->setHeader($name, $value);
            }
        }

        $body = $psrResponse->getBody();
        $body->rewind();

        if ($body->isReadable()) {
            if ($body->getSize() <= $this->options->getResponseChunkSize()) {
                if ($contents = $body->getContents()) {
                    $swooleResponse->write($contents);
                }
            } else {
                while (!$body->eof() && ($contents = $body->read($this->options->getResponseChunkSize()))) {
                    $swooleResponse->write($contents);
                }
            }

            $swooleResponse->end();
        } else {
            $swooleResponse->end((string) $body);
        }

        $body->close();
    }

    /**
     * Process uploaded files from Swoole request
     *
     * @param array $files Files array from Swoole request
     * @return array Normalized uploaded files array
     */
    private function processUploadedFiles(array $files): array
    {
        // Use your existing normalizeUploadedFiles function
        // but with additional validation and error handling
        try {
            $normalizedFiles = normalizeUploadedFiles($files);

            // Verify temp files exist and are readable
            $this->validateUploadedFiles($normalizedFiles);

            return $normalizedFiles;
        } catch (\Throwable $e) {
            logger()->error('Error processing uploaded files', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [];
        }
    }

    /**
     * Validate that temporary files exist and are readable
     *
     * @param array $files Normalized uploaded files array
     */
    private function validateUploadedFiles(array $files): void
    {
        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFileInterface) {
                // Skip files with upload errors (except UPLOAD_ERR_OK)
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    continue;
                }

                // Verify the temp file exists and is readable
                $stream = $file->getStream();
                $meta = $stream->getMetadata();
                $uri = $meta['uri'] ?? null;

                if ($uri && !is_readable($uri)) {
                    logger()->warning('Upload temp file not readable', [
                        'key' => $key,
                        'uri' => $uri
                    ]);
                }
            } elseif (is_array($file)) {
                $this->validateUploadedFiles($file);
            }
        }
    }
}