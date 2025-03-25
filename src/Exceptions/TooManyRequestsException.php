<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Exceptions;

class TooManyRequestsException extends HttpException
{
    public function __construct(int $retryAfter = 60, string $message = 'Too Many Requests', ?string $title = 'Too Many Requests')
    {
        $headers = ['Retry-After' => (string)$retryAfter];
        parent::__construct($message, 429, $headers, $title);
    }
}