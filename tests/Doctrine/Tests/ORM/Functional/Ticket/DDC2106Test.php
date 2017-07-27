<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Annotation as ORM;

/**
 * @group DDC-2106
 */
class DDC2106Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->schemaTool->createSchema(
            [
                $this->em->getClassMetadata(DDC2106Entity::class),
            ]
        );
    }

    public function testDetachedEntityAsId()
    {
        // We want an uninitialized PersistentCollection $entity->children
        $entity = new DDC2106Entity();
        $this->em->persist($entity);
        $this->em->flush();
        $this->em->detach($entity);
        $entity = $this->em->getRepository(DDC2106Entity::class)->findOneBy([]);

        // ... and a managed entity without id
        $entityWithoutId = new DDC2106Entity();
        $this->em->persist($entityWithoutId);

        $criteria = Criteria::create()->where(Criteria::expr()->eq('parent', $entityWithoutId));

        self::assertCount(0, $entity->children->matching($criteria));
    }
}

/**
 * @ORM\Entity
 */
class DDC2106Entity
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer")
     */
    public $id;

    /** @ORM\ManyToOne(targetEntity="DDC2106Entity", inversedBy="children") */
    public $parent;

    /**
     * @ORM\OneToMany(targetEntity="DDC2106Entity", mappedBy="parent", cascade={"persist"})
     */
    public $children;

    public function __construct()
    {
        $this->children = new \Doctrine\Common\Collections\ArrayCollection;
    }
}

