<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\RemoveUselessLeftJoinsWalker;

/**
 * @group DDC-1613
 */
class RemoveUselessLeftJoinWalkerTest extends PaginationTestCase
{
    public function testUselessLeftJoinsAreRemoved()
    {
        $dql        = <<<DQL
SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p 
LEFT JOIN p.category c 
LEFT JOIN p.author a
WHERE p.id IN (SELECT DISTINCT(p2.id) FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p2 
LEFT JOIN p2.category c2 
LEFT JOIN p2.author a2)
DQL;
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [RemoveUselessLeftJoinsWalker::class]);

        $this->assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, c1_.id AS id_2, a2_.id AS id_3, a2_.name AS name_4, m0_.author_id AS author_id_5, m0_.category_id AS category_id_6 FROM MyBlogPost m0_ WHERE m0_.id IN (SELECT DISTINCT (m3_.id) FROM MyBlogPost m3_)',
            $limitQuery->getSQL()
        );
    }

    public function testOrderByLeftJoinsAreKept()
    {
        $dql        = <<<DQL
SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p 
LEFT JOIN p.category c 
LEFT JOIN p.author a
WHERE p.id IN (SELECT DISTINCT(p2.id) FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p2 
LEFT JOIN p2.category c2 
LEFT JOIN p2.author a2 ORDER BY a2.id DESC)
DQL;
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [RemoveUselessLeftJoinsWalker::class]);

        $this->assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, c1_.id AS id_2, a2_.id AS id_3, a2_.name AS name_4, m0_.author_id AS author_id_5, m0_.category_id AS category_id_6 FROM MyBlogPost m0_ WHERE m0_.id IN (SELECT DISTINCT (m3_.id) FROM MyBlogPost m3_ LEFT JOIN Author a4_ ON m3_.author_id = a4_.id ORDER BY a4_.id DESC)',
            $limitQuery->getSQL()
        );
    }

    public function testWhereLeftJoinsAreKept()
    {
        $dql        = <<<DQL
SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p 
LEFT JOIN p.category c 
LEFT JOIN p.author a
WHERE p.id IN (SELECT DISTINCT(p2.id) FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p2 
LEFT JOIN p2.category c2 
LEFT JOIN p2.author a2 WHERE 2 = a2.id)
DQL;
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [RemoveUselessLeftJoinsWalker::class]);

        $this->assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, c1_.id AS id_2, a2_.id AS id_3, a2_.name AS name_4, m0_.author_id AS author_id_5, m0_.category_id AS category_id_6 FROM MyBlogPost m0_ WHERE m0_.id IN (SELECT DISTINCT (m3_.id) FROM MyBlogPost m3_ LEFT JOIN Author a4_ ON m3_.author_id = a4_.id WHERE 2 = a4_.id)',
            $limitQuery->getSQL()
        );
    }

    public function testMultipleWhereLeftJoinsAreKept()
    {
        $dql        = <<<DQL
SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p 
LEFT JOIN p.category c 
LEFT JOIN p.author a
WHERE p.id IN (SELECT DISTINCT(p2.id) FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p2 
LEFT JOIN p2.category c2 
LEFT JOIN p2.author a2 WHERE 2 = a2.id AND c2.id = 1)
DQL;
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [RemoveUselessLeftJoinsWalker::class]);

        $this->assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, c1_.id AS id_2, a2_.id AS id_3, a2_.name AS name_4, m0_.author_id AS author_id_5, m0_.category_id AS category_id_6 FROM MyBlogPost m0_ WHERE m0_.id IN (SELECT DISTINCT (m3_.id) FROM MyBlogPost m3_ LEFT JOIN Category c4_ ON m3_.category_id = c4_.id LEFT JOIN Author a5_ ON m3_.author_id = a5_.id WHERE 2 = a5_.id AND c4_.id = 1)',
            $limitQuery->getSQL()
        );
    }

    public function testHavingLeftJoinsAreKept()
    {
        $dql        = <<<DQL
SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p 
LEFT JOIN p.category c 
LEFT JOIN p.author a
WHERE p.id IN (SELECT DISTINCT(p2.id) FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p2 
LEFT JOIN p2.category c2 
LEFT JOIN p2.author a2 HAVING a2.id > 2)
DQL;
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [RemoveUselessLeftJoinsWalker::class]);

        $this->assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, c1_.id AS id_2, a2_.id AS id_3, a2_.name AS name_4, m0_.author_id AS author_id_5, m0_.category_id AS category_id_6 FROM MyBlogPost m0_ WHERE m0_.id IN (SELECT DISTINCT (m3_.id) FROM MyBlogPost m3_ LEFT JOIN Author a4_ ON m3_.author_id = a4_.id HAVING a4_.id > 2)',
            $limitQuery->getSQL()
        );
    }
}
