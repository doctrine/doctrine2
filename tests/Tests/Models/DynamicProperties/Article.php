<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DynamicProperties;

use Doctrine\ORM\Mapping as ORM;
use SortDirection;

#[ORM\Entity]
#[ORM\Table(name: 'dynamic_properties_article')]
class Article
{
    use HasAttributes;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    /** @var array<string, mixed> */
    #[ORM\DynamicProperties]
    #[ORM\DynamicAssociation(
        'tags',
        new ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'articles'),
        joinTable: new ORM\JoinTable(name: 'dynamic_properties_article_tag'),
        orderBy: ['name' => SortDirection::Ascending],
    )]
    #[ORM\DynamicAssociation(
        'comments',
        new ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'article', cascade: ['persist'], orphanRemoval: true),
        orderBy: ['id' => SortDirection::Ascending],
    )]
    private array $attributes = [];

    public function __construct(
        #[ORM\Column(length: 50)]
        public string $title,
    ) {
    }
}
