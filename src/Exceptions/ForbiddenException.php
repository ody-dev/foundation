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

class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden', array $headers = [], ?string $title = 'Forbidden')
    {
        parent::__construct($message, 403, $headers, $title);
    }
}