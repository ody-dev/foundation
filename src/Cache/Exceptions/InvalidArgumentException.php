<?php

namespace Ody\Foundation\Cache\Exceptions;

use Psr\Cache\InvalidArgumentException as PsrCacheInvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrSimpleCacheInvalidArgumentException;

class InvalidArgumentException extends \InvalidArgumentException implements PsrCacheInvalidArgumentException, PsrSimpleCacheInvalidArgumentException
{
}