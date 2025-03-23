<?php

namespace Ody\Foundation\Cache\Adapters;

use DateInterval;
use DateTime;

class ArrayAdapter implements AdapterInterface
{
    /**
     * @var array
     */
    protected array $storage = [];

    /**
     * @var array
     */
    protected array $expiration = [];

    /**
     * @var int
     */
    protected int $defaultTtl;

    /**
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->defaultTtl = $config['ttl'] ?? 3600;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $this->storage = [];
        $this->expiration = [];

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->storage[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        if (!isset($this->storage[$key])) {
            return false;
        }

        if (isset($this->expiration[$key]) && $this->expiration[$key] < time()) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        unset($this->storage[$key], $this->expiration[$key]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $ttl = $this->normalizeTtl($ttl);

        $this->storage[$key] = $value;

        if ($ttl > 0) {
            $this->expiration[$key] = time() + $ttl;
        } else {
            unset($this->expiration[$key]);
        }

        return true;
    }

    /**
     * Normalize TTL value to seconds
     *
     * @param null|int|\DateInterval $ttl
     * @return int
     */
    protected function normalizeTtl(null|int|\DateInterval $ttl = null): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        if ($ttl instanceof DateInterval) {
            return (new DateTime())->add($ttl)->getTimestamp() - time();
        }

        return (int)$ttl;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }
}