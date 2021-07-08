<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\WhereInWalker;
use Doctrine\Tests\DbalTypes\Rot13Type;
use Doctrine\Tests\Models\ValueConversionType\AuxiliaryEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToOneIdForeignKeyEntity;

/**
 * @group DDC-1613
 */
class WhereInWalkerTest extends PaginationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Type::hasType('rot13')) {
            Type::addType('rot13', Rot13Type::class);
        }
    }

    public function testWhereInQueryNoWhere(): void
    {
        $query        = $this->entityManager->createQuery(
            'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g'
        );
        $whereInQuery = clone $query;
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT u0_.id AS id_0, g1_.id AS id_1 FROM User u0_ INNER JOIN user_group u2_ ON u0_.id = u2_.user_id INNER JOIN groups g1_ ON g1_.id = u2_.group_id WHERE u0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    public function testCountQueryMixedResultsWithName(): void
    {
        $query        = $this->entityManager->createQuery(
            'SELECT a, sum(a.name) as foo FROM Doctrine\Tests\ORM\Tools\Pagination\Author a'
        );
        $whereInQuery = clone $query;
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT a0_.id AS id_0, a0_.name AS name_1, sum(a0_.name) AS sclr_2 FROM Author a0_ WHERE a0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    public function testWhereInQuerySingleWhere(): void
    {
        $query        = $this->entityManager->createQuery(
            'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g WHERE 1 = 1'
        );
        $whereInQuery = clone $query;
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT u0_.id AS id_0, g1_.id AS id_1 FROM User u0_ INNER JOIN user_group u2_ ON u0_.id = u2_.user_id INNER JOIN groups g1_ ON g1_.id = u2_.group_id WHERE 1 = 1 AND u0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    public function testWhereInQueryMultipleWhereWithAnd(): void
    {
        $query        = $this->entityManager->createQuery(
            'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g WHERE 1 = 1 AND 2 = 2'
        );
        $whereInQuery = clone $query;
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT u0_.id AS id_0, g1_.id AS id_1 FROM User u0_ INNER JOIN user_group u2_ ON u0_.id = u2_.user_id INNER JOIN groups g1_ ON g1_.id = u2_.group_id WHERE 1 = 1 AND 2 = 2 AND u0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    public function testWhereInQueryMultipleWhereWithOr(): void
    {
        $query        = $this->entityManager->createQuery(
            'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g WHERE 1 = 1 OR 2 = 2'
        );
        $whereInQuery = clone $query;
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT u0_.id AS id_0, g1_.id AS id_1 FROM User u0_ INNER JOIN user_group u2_ ON u0_.id = u2_.user_id INNER JOIN groups g1_ ON g1_.id = u2_.group_id WHERE (1 = 1 OR 2 = 2) AND u0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    public function testWhereInQueryMultipleWhereWithMixed1(): void
    {
        $query        = $this->entityManager->createQuery(
            'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g WHERE (1 = 1 OR 2 = 2) AND 3 = 3'
        );
        $whereInQuery = clone $query;
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT u0_.id AS id_0, g1_.id AS id_1 FROM User u0_ INNER JOIN user_group u2_ ON u0_.id = u2_.user_id INNER JOIN groups g1_ ON g1_.id = u2_.group_id WHERE (1 = 1 OR 2 = 2) AND 3 = 3 AND u0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    public function testWhereInQueryMultipleWhereWithMixed2(): void
    {
        $query        = $this->entityManager->createQuery(
            'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g WHERE 1 = 1 AND 2 = 2 OR 3 = 3'
        );
        $whereInQuery = clone $query;
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT u0_.id AS id_0, g1_.id AS id_1 FROM User u0_ INNER JOIN user_group u2_ ON u0_.id = u2_.user_id INNER JOIN groups g1_ ON g1_.id = u2_.group_id WHERE (1 = 1 AND 2 = 2 OR 3 = 3) AND u0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    public function testWhereInQueryWhereNot(): void
    {
        $query        = $this->entityManager->createQuery(
            'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g WHERE NOT 1 = 2'
        );
        $whereInQuery = clone $query;
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT u0_.id AS id_0, g1_.id AS id_1 FROM User u0_ INNER JOIN user_group u2_ ON u0_.id = u2_.user_id INNER JOIN groups g1_ ON g1_.id = u2_.group_id WHERE (NOT 1 = 2) AND u0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    /**
     * Arbitrary Join
     */
    public function testWhereInQueryWithArbitraryJoinNoWhere(): void
    {
        $whereInQuery = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p JOIN Doctrine\Tests\ORM\Tools\Pagination\Category c WITH p.category = c'
        );
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT b0_.id AS id_0, b0_.author_id AS author_id_1, b0_.category_id AS category_id_2 FROM BlogPost b0_ INNER JOIN Category c1_ ON (b0_.category_id = c1_.id) WHERE b0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    public function testWhereInQueryWithArbitraryJoinSingleWhere(): void
    {
        $whereInQuery = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p JOIN Doctrine\Tests\ORM\Tools\Pagination\Category c WITH p.category = c WHERE 1 = 1'
        );
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $this->assertEquals(
            'SELECT b0_.id AS id_0, b0_.author_id AS author_id_1, b0_.category_id AS category_id_2 FROM BlogPost b0_ INNER JOIN Category c1_ ON (b0_.category_id = c1_.id) WHERE 1 = 1 AND b0_.id IN (?)',
            $whereInQuery->getSQL()
        );

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        );
    }

    public function testWillReplaceBoundQueryIdentifiersWithConvertedTypesAsPerIdentifierMapping(): void
    {
        $whereInQuery = $this->entityManager->createQuery(
            'SELECT e.id4 FROM ' . AuxiliaryEntity::class . ' e'
        );
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, ['foo', 'bar', 'baz']);

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            ['sbb', 'one', 'onm']
        );
    }

    public function testWillReplaceBoundQueryIdentifiersWithConvertedTypesAsPerAssociatedEntityIdentifierMapping(): void
    {
        $whereInQuery = $this->entityManager->createQuery(
            'SELECT e FROM ' . OwningManyToOneIdForeignKeyEntity::class . ' e'
        );
        $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [WhereInWalker::class]);
        $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
        $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, ['foo', 'bar', 'baz']);

        $this->assertPaginatorWhereInParameterToBe(
            $whereInQuery,
            ['sbb', 'one', 'onm']
        );
    }

    /** @param mixed $parameter */
    private function assertPaginatorWhereInParameterToBe(Query $query, $parameter): void
    {
        $query->getSQL(); // forces walker to process the query

        $boundParameter = $query->getParameter(WhereInWalker::PAGINATOR_ID_ALIAS);

        self::assertNotNull($boundParameter);
        self::assertSame($parameter, $boundParameter->getValue());
    }
}
