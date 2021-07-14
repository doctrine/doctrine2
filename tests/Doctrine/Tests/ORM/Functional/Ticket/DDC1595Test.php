<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;

/**
 * @group DDC-1595
 * @group DDC-1596
 * @group non-cacheable
 */
class DDC1595Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_em->getConnection()->getConfiguration()->setSQLLogger(new DebugStack());

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1595BaseInheritance::class),
                $this->_em->getClassMetadata(DDC1595InheritedEntity1::class),
                $this->_em->getClassMetadata(DDC1595InheritedEntity2::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $e1 = new DDC1595InheritedEntity1();

        $this->_em->persist($e1);
        $this->_em->flush();
        $this->_em->clear();

        $sqlLogger  = $this->_em->getConnection()->getConfiguration()->getSQLLogger();
        $repository = $this->_em->getRepository(DDC1595InheritedEntity1::class);

        $entity1 = $repository->find($e1->id);

        // DDC-1596
        $this->assertSQLEquals(
            "SELECT t0.id AS id_1, t0.type FROM base t0 WHERE t0.id = ? AND t0.type IN ('Entity1')",
            $sqlLogger->queries[count($sqlLogger->queries)]['sql']
        );

        $entities = $entity1->getEntities()->getValues();

        $this->assertEquals(
            "SELECT t0.id AS id_1, t0.type FROM base t0 INNER JOIN entity1_entity2 ON t0.id = entity1_entity2.item WHERE entity1_entity2.parent = ? AND t0.type IN ('Entity2')",
            $sqlLogger->queries[count($sqlLogger->queries)]['sql']
        );

        $this->_em->clear();

        $entity1  = $repository->find($e1->id);
        $entities = $entity1->getEntities()->count();

        $this->assertSQLEquals(
            'SELECT COUNT(*) FROM entity1_entity2 t WHERE t.parent = ?',
            $sqlLogger->queries[count($sqlLogger->queries)]['sql']
        );
    }
}

/**
 * @Entity
 * @Table(name="base")
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="type", type="string")
 * @DiscriminatorMap({
 *     "Entity1" = "DDC1595InheritedEntity1",
 *     "Entity2" = "DDC1595InheritedEntity2"
 * })
 */
abstract class DDC1595BaseInheritance
{
    /**
     * @Id @GeneratedValue
     * @Column(type="integer")
     * @var int
     */
    public $id;
}

/**
 * @Entity
 * @Table(name="entity1")
 */
class DDC1595InheritedEntity1 extends DDC1595BaseInheritance
{
    /**
     * @psalm-var Collection<int, DDC1595InheritedEntity2>
     * @ManyToMany(targetEntity="DDC1595InheritedEntity2", fetch="EXTRA_LAZY")
     * @JoinTable(name="entity1_entity2",
     *     joinColumns={@JoinColumn(name="parent", referencedColumnName="id")},
     *     inverseJoinColumns={@JoinColumn(name="item", referencedColumnName="id")}
     * )
     */
    protected $entities;

    /**
     * @psalm-return Collection<int, DDC1595InheritedEntity2>
     */
    public function getEntities(): Collection
    {
        return $this->entities;
    }
}

/**
 * @Entity
 * @Table(name="entity2")
 */
class DDC1595InheritedEntity2 extends DDC1595BaseInheritance
{
}
