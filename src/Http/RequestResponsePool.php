<?php

namespace Ody\Foundation\Http;

class RequestResponsePool
{
    private static array $streamPool = [];
    private static int $maxPoolSize = 100;

    public static function getStream(): Stream
    {
        if (empty(self::$streamPool)) {
            // Create new stream if pool is empty
            return new Stream('php://temp', 'r+');
        }

        // Get stream from pool
        return array_pop(self::$streamPool);
    }

    public static function releaseStream(Stream $stream): void
    {
        // Only keep streams in the pool up to the max size
        if (count(self::$streamPool) < self::$maxPoolSize) {
            // Reset the stream for reuse
            try {
                if ($stream->isSeekable()) {
                    $stream->rewind();
                    $stream->write('');
                    $stream->rewind();
                    self::$streamPool[] = $stream;
                }
            } catch (\Throwable $e) {
                // If reset fails, don't add to pool
            }
        }
    }
}