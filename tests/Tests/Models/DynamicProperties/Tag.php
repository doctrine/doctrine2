<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DynamicProperties;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dynamic_properties_tag')]
class Tag
{
    use HasAttributes;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    /** @var array<string, mixed> */
    #[ORM\DynamicProperties]
    #[ORM\DynamicAssociation('articles', new ORM\ManyToMany(targetEntity: Article::class, mappedBy: 'tags'))]
    private array $attributes = [];

    public function __construct(
        #[ORM\Column(length: 50)]
        public string $name,
    ) {
    }
}
