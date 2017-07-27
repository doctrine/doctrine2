<?php
declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\Models\DDC117\DDC117ArticleDetails;
use Doctrine\Tests\Models\DDC117\DDC117Article;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * @group DDC-1685
 */
class DDC1685Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    private $paginator;

    protected function setUp()
    {
        $this->useModelSet('ddc117');

        parent::setUp();

        $this->em->createQuery('DELETE FROM Doctrine\Tests\Models\DDC117\DDC117ArticleDetails ad')->execute();

        $article = new DDC117Article("Foo");
        $this->em->persist($article);
        $this->em->flush();

        $articleDetails = new DDC117ArticleDetails($article, "Very long text");
        $this->em->persist($articleDetails);
        $this->em->flush();

        $dql   = "SELECT ad FROM Doctrine\Tests\Models\DDC117\DDC117ArticleDetails ad";
        $query = $this->em->createQuery($dql);

        $this->paginator = new Paginator($query);
    }

    public function testPaginateCount()
    {
        self::assertEquals(1, count($this->paginator));
    }

    public function testPaginateIterate()
    {
        foreach ($this->paginator as $ad) {
            self::assertInstanceOf(DDC117ArticleDetails::class, $ad);
        }
    }

    public function testPaginateCountNoOutputWalkers()
    {
        $this->paginator->setUseOutputWalkers(false);

        self::assertEquals(1, count($this->paginator));
    }

    public function testPaginateIterateNoOutputWalkers()
    {
        $this->paginator->setUseOutputWalkers(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Paginating an entity with foreign key as identifier only works when using the Output Walkers. Call Paginator#setUseOutputWalkers(true) before iterating the paginator.');

        foreach ($this->paginator as $ad) {
            self::assertInstanceOf(DDC117ArticleDetails::class, $ad);
        }
    }
}

