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

class MethodNotAllowedException extends HttpException
{
    public function __construct(array $allowedMethods = [], string $message = 'Method Not Allowed', ?string $title = 'Method Not Allowed')
    {
        $headers = ['Allow' => implode(', ', $allowedMethods)];
        parent::__construct($message, 405, $headers, $title);
    }
}