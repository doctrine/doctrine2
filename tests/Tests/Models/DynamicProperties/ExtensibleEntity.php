<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DynamicProperties;

use Doctrine\ORM\Mapping as ORM;

/**
 * A mapped superclass that declares an empty dynamic properties container,
 * which is filled by a loadClassMetadata listener ({@see LoyaltyMetadataListener}).
 */
#[ORM\MappedSuperclass]
abstract class ExtensibleEntity
{
    /** @var array<string, mixed> */
    #[ORM\DynamicProperties]
    private array $extra = [];

    public function get(string $key): mixed
    {
        return $this->extra[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->extra[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->extra;
    }
}
