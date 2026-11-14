<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('DDC-2106')]
class DDC2106Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(DDC2106Entity::class);
    }

    public function testDetachedEntityAsId(): void
    {
        // We want an uninitialized PersistentCollection $entity->children
        $entity = new DDC2106Entity();
        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();
        $entity = $this->_em->getRepository(DDC2106Entity::class)->findOneBy([]);

        // ... and a managed entity without id
        $entityWithoutId = new DDC2106Entity();
        $this->_em->persist($entityWithoutId);

        $criteria = Criteria::create()->where(Criteria::expr()->eq('parent', $entityWithoutId));

        self::assertCount(0, $entity->children->matching($criteria));
    }
}

#[Entity]
class DDC2106Entity
{
    /** @var int */
    #[Id]
    #[GeneratedValue(strategy: 'IDENTITY')]
    #[Column(type: 'integer')]
    public $id;

    /** @var DDC2106Entity */
    #[ManyToOne(targetEntity: 'DDC2106Entity', inversedBy: 'children')]
    public $parent;

    /** @phpstan-var Collection<int, DDC2106Entity> */
    #[OneToMany(targetEntity: 'DDC2106Entity', mappedBy: 'parent', cascade: ['persist'])]
    public $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
}
