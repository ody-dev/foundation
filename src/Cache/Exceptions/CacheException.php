<?php

namespace Ody\Foundation\Cache\Exceptions;

use Psr\Cache\CacheException as PsrCacheException;
use Psr\SimpleCache\CacheException as PsrSimpleCacheException;

class CacheException extends \Exception implements PsrCacheException, PsrSimpleCacheException
{
}