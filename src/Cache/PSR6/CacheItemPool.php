<?php

namespace Ody\Foundation\Cache\PSR6;

use Ody\Foundation\Cache\Exceptions\InvalidArgumentException as PackageInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

class CacheItemPool implements CacheItemPoolInterface
{
    /**
     * @var CacheInterface
     */
    protected CacheInterface $cache;

    /**
     * @var array
     */
    protected array $deferred = [];

    /**
     * @param CacheInterface $cache
     */
    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = []): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->getItem($key);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);

        if (isset($this->deferred[$key])) {
            return clone $this->deferred[$key];
        }

        $value = $this->cache->get($key, null);
        $item = new CacheItem($key, $value, $value !== null);

        return $item;
    }

    /**
     * Validates a cache key according to PSR-6 requirements
     *
     * @param string $key
     * @throws InvalidArgumentException
     */
    protected function validateKey(string $key): void
    {
        if (!is_string($key) || $key === '') {
            throw new PackageInvalidArgumentException('Cache key must be a non-empty string');
        }

        if (preg_match('/[{}()\/@:]+/', $key)) {
            throw new PackageInvalidArgumentException(
                'Cache key contains invalid characters: {, }, (, ), /, @, :'
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem(string $key): bool
    {
        $this->validateKey($key);

        if (isset($this->deferred[$key])) {
            return $this->deferred[$key]->isHit();
        }

        return $this->cache->has($key);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $this->deferred = [];
        return $this->cache->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);

        unset($this->deferred[$key]);

        return $this->cache->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
            unset($this->deferred[$key]);
        }

        return $this->cache->deleteMultiple($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }

        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        $success = true;

        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                $success = false;
            }
        }

        $this->deferred = [];

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }

        $key = $item->getKey();
        $value = $item->get();
        $ttl = $item->getExpiration();

        return $this->cache->set($key, $value, $ttl);
    }
}