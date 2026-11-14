<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\ORM\Mapping as ORM;

/**
 * Two associations on the same entity share one custom collection instance.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customcollection_shared_tag_lists')]
class SharedTagLists
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\ManyToMany(targetEntity: SharedTag::class)]
    #[ORM\JoinTable(name: 'customcollection_shared_first_tags')]
    public SharedTagCollection $firstTags;

    #[ORM\ManyToMany(targetEntity: SharedTag::class)]
    #[ORM\JoinTable(name: 'customcollection_shared_second_tags')]
    public SharedTagCollection $secondTags;

    public function __construct()
    {
        $this->firstTags = $this->secondTags = new SharedTagCollection();
    }
}
