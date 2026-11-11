<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\ORM\Mapping as ORM;

/**
 * A mapped superclass declaring a custom collection association in a private
 * property, so that the ORM accesses it through the declaring class.
 */
#[ORM\MappedSuperclass]
abstract class AbstractTaggedItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\ManyToMany(targetEntity: Tag::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'customcollection_article_tag')]
    private TagCollection $tags;

    public function __construct()
    {
        $this->tags = new TagCollection();
    }

    public function getTags(): TagCollection
    {
        return $this->tags;
    }
}
