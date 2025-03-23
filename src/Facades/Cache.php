<?php

namespace Ody\Foundation\Facades;

/**
 * Cache Facade
 *
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string $key, mixed $value, null|int|\DateInterval $ttl = null)
 * @method static bool delete(string $key)
 * @method static bool clear()
 * @method static iterable getMultiple(iterable $keys, mixed $default = null)
 * @method static bool setMultiple(iterable $values, null|int|\DateInterval $ttl = null)
 * @method static bool deleteMultiple(iterable $keys)
 * @method static bool has(string $key)
 * @method static \Psr\SimpleCache\CacheInterface driver(string $name = null)
 * @method static \Psr\Cache\CacheItemPoolInterface pool(string $name = null)
 * @method static \Psr\Cache\CacheItemInterface getItem(string $key)
 * @method static array getItems(array $keys = [])
 * @method static bool save(\Psr\Cache\CacheItemInterface $item)
 * @method static bool saveDeferred(\Psr\Cache\CacheItemInterface $item)
 * @method static bool commit()
 * @method static \Ody\Foundation\Cache\TaggedCache tags(array|string $tags)
 */
class Cache extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'cache';
    }
}