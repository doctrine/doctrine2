<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;

class QueryBuilderParenthesisTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(QueryBuilderParenthesisEntity::class),
            ]
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->_schemaTool->dropSchema(
            [
                $this->_em->getClassMetadata(QueryBuilderParenthesisEntity::class),
            ]
        );
    }

    public function testParenthesisOnSingleLine(): void
    {
        $queryBuilder = $this->_em->createQueryBuilder();
        $queryBuilder->select('o')->from(QueryBuilderParenthesisEntity::class, 'o');
        $queryBuilder->andWhere('o.property3 = :value3')->setParameter('value3', 'x');
        $queryBuilder->andWhere('o.property1 = :value1 OR o.property2 = :value2');
        $queryBuilder->andWhere('o.property1 = :value1 or o.property2 = :value2');
        $queryBuilder->setParameter('value1', 'x');
        $queryBuilder->setParameter('value2', 'x');

        $query   = $queryBuilder->getQuery();
        $results = $query->getResult();
        $this->assertCount(0, $results);

        $dql = $query->getDQL();

        $this->assertSame(
            'SELECT o FROM ' . QueryBuilderParenthesisEntity::class . ' o WHERE o.property3 = :value3 AND (o.property1 = :value1 OR o.property2 = :value2) AND (o.property1 = :value1 or o.property2 = :value2)',
            $dql
        );
    }

    public function testParenthesisOnMultiLine(): void
    {
        $queryBuilder = $this->_em->createQueryBuilder();
        $queryBuilder->select('o')->from(QueryBuilderParenthesisEntity::class, 'o');
        $queryBuilder->andWhere('o.property3 = :value3')->setParameter('value3', 'x');

        $queryBuilder->andWhere(
            'o.property1 = :value1
OR o.property2 = :value2'
        );
        $queryBuilder->setParameter('value1', 'x');
        $queryBuilder->setParameter('value2', 'x');

        $query   = $queryBuilder->getQuery();
        $results = $query->getResult();
        $this->assertCount(0, $results);

        $dql = $query->getDQL();

        $this->assertSame(
            'SELECT o FROM ' . QueryBuilderParenthesisEntity::class . ' o WHERE o.property3 = :value3 AND (o.property1 = :value1
OR o.property2 = :value2)',
            $dql
        );
    }
}


/**
 * @Entity
 */
class QueryBuilderParenthesisEntity
{
    /**
     * @var int|null
     * @Column(type="integer")
     * @Id
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string|null
     * @Column()
     */
    public $property1;

    /**
     * @var string|null
     * @Column()
     */
    public $property2;

    /**
     * @var string|null
     * @Column()
     */
    public $property3;
}
