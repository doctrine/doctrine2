<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DynamicProperties;

/** Accessors for a dynamic properties container named $attributes. */
trait HasAttributes
{
    public function get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->attributes;
    }
}
