<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * ResponseEmitter
 *
 * Handles emitting PSR-7 responses to the client.
 * This centralizes the logic for sending HTTP responses, supporting
 * both regular PHP responses and Swoole/coroutine environments.
 */
class ResponseEmitter
{
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var bool Whether to remove the Content-Length header when sending chunked responses
     */
    protected bool $removeContentLength;

    /**
     * @var int Buffer size for chunk sending
     */
    protected int $bufferSize;

    /**
     * @var array HTTP headers that shouldn't be emitted multiple times
     */
    protected array $singleUseHeaders = [
        'content-type',
        'content-length',
        'transfer-encoding',
    ];

    /**
     * Constructor
     *
     * @param LoggerInterface|null $logger
     * @param bool $removeContentLength
     * @param int $bufferSize
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        bool $removeContentLength = true,
        int $bufferSize = 8192
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->removeContentLength = $removeContentLength;
        $this->bufferSize = $bufferSize;
    }

    /**
     * Emits a response to the client
     *
     * @param ResponseInterface $response
     * @param bool $isSwoole Whether the request is in a Swoole context
     * @return bool True on success, false on failure
     */
    public function emit(ResponseInterface $response, bool $isSwoole = false): bool
    {
        try {
            // Check if headers have already been sent
            if (headers_sent()) {
                $this->logger->warning('Headers already sent, cannot emit response headers');
                return false;
            }

            // Send the response in the appropriate way
            if ($isSwoole && extension_loaded('swoole')) {
                return $this->emitSwoole($response);
            } else {
                return $this->emitStandard($response);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to emit response', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Emit a standard PHP response
     *
     * @param ResponseInterface $response
     * @return bool
     */
    protected function emitStandard(ResponseInterface $response): bool
    {
        // Send HTTP status code
        $this->emitStatusLine($response);

        // Send headers
        $this->emitHeaders($response);

        // Send body
        $this->emitBody($response);

        // Flush output buffer if needed
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (PHP_SAPI !== 'cli') {
            $this->flushBuffers();
        }

        return true;
    }

    /**
     * Emit a response in a Swoole context
     *
     * @param ResponseInterface $response
     * @return bool
     */
    protected function emitSwoole(ResponseInterface $response): bool
    {
        // This method would need to be implemented based on your specific Swoole setup
        // For example, if you have access to a Swoole\Http\Response object:

        // Get the current Swoole response from the coroutine context
        try {
            if (class_exists('\Swoole\Coroutine') && method_exists('\Swoole\Coroutine', 'getContext')) {
                $context = \Swoole\Coroutine::getContext();
                if (isset($context['swoole_response']) && $context['swoole_response'] instanceof \Swoole\Http\Response) {
                    $swooleResponse = $context['swoole_response'];

                    // Set the status code
                    $swooleResponse->status($response->getStatusCode());

                    // Set headers
                    foreach ($response->getHeaders() as $name => $values) {
                        foreach ($values as $value) {
                            $swooleResponse->header($name, $value);
                        }
                    }

                    // Send body
                    $body = (string) $response->getBody();
                    $swooleResponse->end($body);

                    return true;
                }
            }

            // Fallback to standard emission if we can't get the Swoole response
            $this->logger->warning('Swoole context not found, falling back to standard emission');
            return $this->emitStandard($response);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to emit Swoole response', [
                'error' => $e->getMessage()
            ]);

            // Fallback to standard emission
            return $this->emitStandard($response);
        }
    }

    /**
     * Emit the status line
     *
     * @param ResponseInterface $response
     * @return void
     */
    protected function emitStatusLine(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();

        // If the reason phrase is empty but the status code is standard, try to provide a default phrase
        if (empty($reasonPhrase) && isset(self::STATUS_PHRASES[$statusCode])) {
            $reasonPhrase = self::STATUS_PHRASES[$statusCode];
        }

        $statusHeader = sprintf(
            'HTTP/%s %d%s',
            $response->getProtocolVersion(),
            $statusCode,
            ($reasonPhrase ? ' ' . $reasonPhrase : '')
        );

        header($statusHeader, true, $statusCode);
    }

    /**
     * Emit response headers
     *
     * @param ResponseInterface $response
     * @return void
     */
    protected function emitHeaders(ResponseInterface $response): void
    {
        $headers = $response->getHeaders();

        // If we're planning to emit the body in chunks, remove the Content-Length header
        if ($this->removeContentLength && $response->getBody()->getSize() > $this->bufferSize) {
            unset($headers['Content-Length']);
        }

        // Set each header
        foreach ($headers as $name => $values) {
            $name = $this->normalizeHeaderName($name);
            $first = in_array(strtolower($name), $this->singleUseHeaders, true);

            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), !$first);
                $first = false;
            }
        }
    }

    /**
     * Emit response body
     *
     * @param ResponseInterface $response
     * @return void
     */
    protected function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        // If the body is small, just echo it directly
        if (!$body->isReadable() || ($body->getSize() !== null && $body->getSize() <= $this->bufferSize)) {
            echo $body;
            return;
        }

        // For larger bodies, send in chunks to save memory
        while (!$body->eof()) {
            echo $body->read($this->bufferSize);

            // Flush output buffers to avoid memory buildup
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }

    /**
     * Flush all output buffers
     *
     * @return void
     */
    protected function flushBuffers(): void
    {
        // Flush all output buffers
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    /**
     * Normalize header name
     *
     * @param string $name
     * @return string
     */
    protected function normalizeHeaderName(string $name): string
    {
        // Convert to title case (e.g., 'content-type' becomes 'Content-Type')
        return str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
    }

    /**
     * Common HTTP status phrases for common status codes
     *
     * @var array
     */
    const STATUS_PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',

        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',

        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',

        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',

        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];
}