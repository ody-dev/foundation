<?php

namespace Ody\Foundation\Cache\PSR6;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

class CacheItem implements CacheItemInterface
{
    /**
     * @var string
     */
    protected string $key;

    /**
     * @var mixed
     */
    protected mixed $value;

    /**
     * @var bool
     */
    protected bool $hit;

    /**
     * @var int|null
     */
    protected ?int $expiration = null;

    /**
     * @param string $key
     * @param mixed $value
     * @param bool $hit
     */
    public function __construct(string $key, mixed $value = null, bool $hit = false)
    {
        $this->key = $key;
        $this->value = $value;
        $this->hit = $hit;
    }

    /**
     * {@inheritdoc}
     */
    public function get(): mixed
    {
        return $this->isHit() ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function isHit(): bool
    {
        if ($this->expiration !== null && $this->expiration < time()) {
            return false;
        }

        return $this->hit;
    }

    public function has(): bool
    {
        return !is_null($this->getKey());
    }

    /**
     * {@inheritdoc}
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * {@inheritdoc}
     */
    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit = true;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        if ($expiration === null) {
            $this->expiration = null;
            return $this;
        }

        $this->expiration = $expiration->getTimestamp();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function expiresAfter(int|DateInterval|null $time): static
    {
        if ($time === null) {
            $this->expiration = null;
            return $this;
        }

        if ($time instanceof DateInterval) {
            $expiration = (new DateTime())->add($time);
            $this->expiration = $expiration->getTimestamp();
            return $this;
        }

        $this->expiration = time() + $time;

        return $this;
    }

    /**
     * Get the expiration timestamp
     *
     * @return int|null
     */
    public function getExpiration(): ?int
    {
        if ($this->expiration === null) {
            return null;
        }

        $now = time();
        return $this->expiration > $now ? $this->expiration - $now : null;
    }
}