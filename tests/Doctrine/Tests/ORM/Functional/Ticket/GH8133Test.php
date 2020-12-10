<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group GH8133
 */
final class GH8133Test extends OrmFunctionalTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp() : void
    {
        parent::setUp();

        $this->setUpEntitySchema([GH8133ClassOne::class, GH8133ClassMany::class]);
    }

    public function testReplacingElementsInOneToManyCollectionAfterPersistDoesNotCauseInsertionOfReplacedObject() : void
    {
        $gh8133ClassMany1 = new GH8133ClassMany(1);
        $gh8133ClassOne = new GH8133ClassOne([$gh8133ClassMany1]);
        $this->_em->persist($gh8133ClassOne);

        $gh8133ClassMany2 = new GH8133ClassMany(2);
        $gh8133ClassOne->replaceGh8133ClassManys([$gh8133ClassMany2]);
        $this->_em->flush();
        $this->_em->clear();

        $entity1 = $this->_em->getRepository(GH8133ClassMany::class)->find(1);
        self::assertNull($entity1);
        $entity2 = $this->_em->getRepository(GH8133ClassMany::class)->find(2);
        self::assertNotNull($entity2);
    }
}

/**
 * @Entity
 * @Table(name="gh8133_class_one")
 */
class GH8133ClassOne
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     * @var int
     */
    private $id;

    /**
     * @OneToMany(
     *     targetEntity="GH8133ClassMany",
     *     mappedBy="gh8133ClassOne",
     *     cascade={"persist"},
     *     orphanRemoval=true
     * )
     *
     * @var GH8133ClassMany[]|ArrayCollection
     */
    private $gh8133ClassManys;

    public function __construct(array $gh8133ClassManys)
    {
        $this->gh8133ClassManys = new ArrayCollection();
        $this->replaceGh8133ClassManys($gh8133ClassManys);
    }

    /**
     * @param GH8133ClassMany[] $gh8133ClassManys
     */
    public function replaceGh8133ClassManys(array $gh8133ClassManys): void
    {
        // Clear all the old objects replacing with the new ones
        $this->gh8133ClassManys->clear();
        foreach ($gh8133ClassManys as $gh8133ClassMany) {
            $this->gh8133ClassManys->add($gh8133ClassMany);
            // This should not be needed for persistence, only to keep it in sync objects.
            $gh8133ClassMany->gh8133ClassOne = $this;
        }
    }
}

/**
 * @Entity
 * @Table(name="gh8133_class_many")
 */
class GH8133ClassMany
{
    /**
     * @Id
     * @Column(type="integer")
     */
    private $id;

    /**
     * @ManyToOne(targetEntity="GH8133ClassOne", inversedBy="gh8133ClassManys")
     * @JoinColumn(name="gh_8133_class_one_id", nullable=false, onDelete="CASCADE")
     * @var GH8133ClassOne
     */
    public $gh8133ClassOne;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}
