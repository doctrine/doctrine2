<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-2306
 */
class DDC3170Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC3170AbstractEntityJoined::class),
                $this->_em->getClassMetadata(DDC3170ProductJoined::class),
                $this->_em->getClassMetadata(DDC3170AbstractEntitySingleTable::class),
                $this->_em->getClassMetadata(DDC3170ProductSingleTable::class),
            ]
        );
    }

    /**
     * Tests that the discriminator column is correctly read from the meta mappings when fetching a
     * child from an inheritance mapped class.
     *
     * The simple object hydration maps the type field to a field alias like type2. This mapping needs
     * to be considered when loading the discriminator column's value from the SQL result.
     *
     * {@see \Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator::hydrateRowData()}
     */
    public function testIssue(): void
    {
        $productJoined      = new DDC3170ProductJoined();
        $productSingleTable = new DDC3170ProductSingleTable();

        $this->_em->persist($productJoined);
        $this->_em->persist($productSingleTable);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
                  ->select('p')
                  ->from(DDC3170ProductJoined::class, 'p')
                  ->getQuery()
                  ->getResult(AbstractQuery::HYDRATE_SIMPLEOBJECT);

        self::assertCount(1, $result);
        self::assertContainsOnly(DDC3170ProductJoined::class, $result);

        $result = $this->_em->createQueryBuilder()
                  ->select('p')
                  ->from(DDC3170ProductSingleTable::class, 'p')
                  ->getQuery()
                  ->getResult(AbstractQuery::HYDRATE_SIMPLEOBJECT);

        self::assertCount(1, $result);
        self::assertContainsOnly(DDC3170ProductSingleTable::class, $result);
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="type", type="string")
 * @DiscriminatorMap({"product" = "DDC3170ProductJoined"})
 */
abstract class DDC3170AbstractEntityJoined
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/**
 * @Entity
 */
class DDC3170ProductJoined extends DDC3170AbstractEntityJoined
{
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="type", type="string")
 * @DiscriminatorMap({"product" = "DDC3170ProductSingleTable"})
 */
abstract class DDC3170AbstractEntitySingleTable
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/**
 * @Entity
 */
class DDC3170ProductSingleTable extends DDC3170AbstractEntitySingleTable
{
}
