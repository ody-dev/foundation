<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Http;

use Ody\Foundation\Http\ResponseEmitter;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Swoole\Http\Response as SwooleResponse;

/**
 * SwooleResponseEmitter
 *
 * Specialized response emitter for Swoole environments.
 * This class handles the specifics of sending PSR-7 responses through Swoole's HTTP server.
 */
class SwooleResponseEmitter extends ResponseEmitter
{
    /**
     * Emit a response directly to a Swoole response object
     *
     * @param ResponseInterface $response PSR-7 response
     * @param SwooleResponse $swooleResponse Swoole response object
     * @return bool True on success
     */
    public function emitToSwoole(ResponseInterface $response, SwooleResponse $swooleResponse): bool
    {
        try {
            // Set status code
            $swooleResponse->status($response->getStatusCode());

            // Set headers
            foreach ($response->getHeaders() as $name => $values) {
                // Most headers support multiple values, but some (like Content-Type) should only use the last value
                if (in_array(strtolower($name), $this->singleUseHeaders, true)) {
                    $swooleResponse->header($name, end($values));
                } else {
                    // For headers that can have multiple values
                    foreach ($values as $value) {
                        $swooleResponse->header($name, $value);
                    }
                }
            }

            // Get the response body
            $body = $response->getBody();

            // Handle streaming responses
            if ($body->getSize() > $this->bufferSize && $body->isReadable()) {
                // Start a chunked response
                $swooleResponse->header('Transfer-Encoding', 'chunked');

                // Remove Content-Length for chunked transfers
                if ($this->removeContentLength) {
                    $swooleResponse->header('Content-Length', '');
                }

                // Rewind the body if it's seekable
                if ($body->isSeekable()) {
                    $body->rewind();
                }

                // Initial response without ending
                $swooleResponse->write('');

                // Stream the body in chunks
                while (!$body->eof()) {
                    $chunk = $body->read($this->bufferSize);
                    if ($chunk !== '') {
                        $swooleResponse->write($chunk);
                    }
                }

                // End the response
                $swooleResponse->end();
            } else {
                // For small bodies, send the entire contents at once
                $content = (string)$body;
                $swooleResponse->end($content);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to emit Swoole response', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Try to send an error response
            try {
                if (!$swooleResponse->isWritable()) {
                    return false;
                }

                $swooleResponse->status(500);
                $swooleResponse->header('Content-Type', 'text/plain');
                $swooleResponse->end('Internal Server Error');
            } catch (\Throwable $e2) {
                // If we can't even send an error response, log it
                $this->logger->critical('Failed to send error response via Swoole', [
                    'error' => $e2->getMessage()
                ]);
            }

            return false;
        }
    }

    /**
     * Find Swoole response object from the current coroutine context
     *
     * @return SwooleResponse|null
     */
    public function findSwooleResponse(): ?SwooleResponse
    {
        if (!extension_loaded('swoole')) {
            return null;
        }

        try {
            if (class_exists('\Swoole\Coroutine') && method_exists('\Swoole\Coroutine', 'getContext')) {
                $context = \Swoole\Coroutine::getContext();
                if (isset($context['swoole_response']) && $context['swoole_response'] instanceof SwooleResponse) {
                    return $context['swoole_response'];
                }
            }

            // For non-coroutine environments or if not found in context
            if (class_exists('\Swoole\Http\Response') && isset($GLOBALS['swoole_response'])) {
                return $GLOBALS['swoole_response'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to find Swoole response object', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Override of the standard emit method to auto-detect Swoole environment
     *
     * @param ResponseInterface $response
     * @param bool|null $isSwoole Autodetect Swoole if null
     * @return bool
     */
    public function emit(ResponseInterface $response, ?bool $isSwoole = null): bool
    {
        // Auto-detect Swoole if not specified
        if ($isSwoole === null) {
            $isSwoole = extension_loaded('swoole');
        }

        if ($isSwoole) {
            // Try to find the Swoole response object
            $swooleResponse = $this->findSwooleResponse();

            if ($swooleResponse instanceof SwooleResponse) {
                return $this->emitToSwoole($response, $swooleResponse);
            }
        }

        // Fall back to standard PHP response emission
        return parent::emit($response, false);
    }
}