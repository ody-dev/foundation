<?php

namespace Ody\Foundation\Cache\PSR16;

use Ody\Foundation\Cache\Adapters\AdapterInterface;
use Ody\Foundation\Cache\Exceptions\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

class SimpleCache implements CacheInterface
{
    /**
     * @var AdapterInterface
     */
    protected AdapterInterface $adapter;

    /**
     * @param AdapterInterface $adapter
     */
    public function __construct(AdapterInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        return $this->adapter->get($key, $default);
    }

    /**
     * Validates a cache key according to PSR-16 requirements
     *
     * @param string $key
     * @throws InvalidArgumentException
     */
    protected function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty');
        }

        if (preg_match('/[{}()\/@:]+/', $key)) {
            throw new InvalidArgumentException(
                'Cache key contains invalid characters: {, }, (, ), /, @, :'
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, mixed $value, $ttl = null): bool
    {
        $this->validateKey($key);
        return $this->adapter->set($key, $value, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        $this->validateKey($key);
        return $this->adapter->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        return $this->adapter->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple($keys, mixed $default = null): iterable
    {
        if (!is_array($keys) && !($keys instanceof \Traversable)) {
            throw new InvalidArgumentException('Keys must be an array or Traversable');
        }

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Keys must be strings');
            }
            $this->validateKey($key);
        }

        return $this->adapter->getMultiple($keys, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple($values, $ttl = null): bool
    {
        if (!is_array($values) && !($values instanceof \Traversable)) {
            throw new InvalidArgumentException('Values must be an array or Traversable');
        }

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Keys must be strings');
            }
            $this->validateKey($key);
        }

        return $this->adapter->setMultiple($values, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple($keys): bool
    {
        if (!is_array($keys) && !($keys instanceof \Traversable)) {
            throw new InvalidArgumentException('Keys must be an array or Traversable');
        }

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Keys must be strings');
            }
            $this->validateKey($key);
        }

        return $this->adapter->deleteMultiple($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function has($key): bool
    {
        $this->validateKey($key);
        return $this->adapter->has($key);
    }
}