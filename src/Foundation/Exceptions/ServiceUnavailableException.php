<?php
/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Exceptions;

class ServiceUnavailableException extends HttpException
{
    public function __construct(int $retryAfter = 60, string $message = 'Service Unavailable', ?string $title = 'Service Unavailable')
    {
        $headers = ['Retry-After' => (string)$retryAfter];
        parent::__construct($message, 503, $headers, $title);
    }
}