<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('GH7829')]
final class GH7829Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();

        $article = new CmsArticle();

        $article->topic = 'Skip Limit Subquery';
        $article->text  = 'Skip Limit Subquery if not required.';

        $this->_em->persist($article);
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testPaginatorWithLimitSubquery(): void
    {
        $this->getQueryLog()->reset()->enable();

        $query = $this->_em->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsArticle a');

        (new OffsetPaginator(true, false))->paginate($query, new Window(0, 1))->getTotalCount();

        $this->assertQueryCount(3);
    }

    public function testPaginatorWithLimitSubquerySkipped(): void
    {
        $this->getQueryLog()->reset()->enable();

        $query = $this->_em->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsArticle a');

        (new OffsetPaginator(false, false))->paginate($query, new Window(0, 1))->getTotalCount();

        $this->assertQueryCount(2);
    }
}
