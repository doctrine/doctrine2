<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DynamicProperties;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dynamic_properties_product')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    /** @var array<string, mixed> */
    #[ORM\DynamicProperties]
    #[ORM\DynamicField('color', new ORM\Column(length: 20, nullable: true))]
    #[ORM\DynamicField('size', new ORM\Column(name: 'size_eu', type: Types::INTEGER, nullable: true))]
    #[ORM\DynamicField('status', new ORM\Column(type: Types::STRING, length: 10, nullable: true, enumType: ProductStatus::class))]
    #[ORM\DynamicAssociation(
        'vendor',
        new ORM\ManyToOne(targetEntity: Vendor::class),
        joinColumns: [new ORM\JoinColumn(name: 'vendor_id', nullable: true, onDelete: 'SET NULL')],
    )]
    private array $attributes = [];

    public function __construct(
        #[ORM\Column(length: 50)]
        public string $name,
    ) {
    }

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
