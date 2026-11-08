<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DynamicProperties;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dynamic_properties_comment')]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'comments')]
        #[ORM\JoinColumn(nullable: false)]
        public Article $article,
        #[ORM\Column(length: 50)]
        public string $body,
    ) {
    }
}
