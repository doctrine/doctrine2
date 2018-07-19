<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Types\Type as DBALType;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;
use function count;

/**
 * ResultCacheTest
 */
class ResultCacheTest extends OrmFunctionalTestCase
{
    /** @var \ReflectionProperty */
    private $cacheDataReflection;

    protected function setUp() : void
    {
        $this->cacheDataReflection = new \ReflectionProperty(ArrayCache::class, 'data');
        $this->cacheDataReflection->setAccessible(true);

        $this->useModelSet('cms');

        parent::setUp();
    }

    /**
     * @return  int
     */
    private function getCacheSize(ArrayCache $cache)
    {
        return count($this->cacheDataReflection->getValue($cache));
    }

    public function testResultCache() : void
    {
        $user = new CmsUser();

        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'dev';

        $this->em->persist($user);
        $this->em->flush();

        $query = $this->em->createQuery('select ux from Doctrine\Tests\Models\CMS\CmsUser ux');
        $cache = new ArrayCache();

        $query->setResultCacheDriver($cache)->setResultCacheId('my_cache_id');

        self::assertFalse($cache->contains('my_cache_id'));

        $users = $query->getResult();

        self::assertTrue($cache->contains('my_cache_id'));
        self::assertCount(1, $users);
        self::assertEquals('Roman', $users[0]->name);

        $this->em->clear();

        $query2 = $this->em->createQuery('select ux from Doctrine\Tests\Models\CMS\CmsUser ux');
        $query2->setResultCacheDriver($cache)->setResultCacheId('my_cache_id');

        $users = $query2->getResult();

        self::assertTrue($cache->contains('my_cache_id'));
        self::assertCount(1, $users);
        self::assertEquals('Roman', $users[0]->name);
    }

    public function testSetResultCacheId() : void
    {
        $cache = new ArrayCache();
        $query = $this->em->createQuery('select ux from Doctrine\Tests\Models\CMS\CmsUser ux');

        $query->setResultCacheDriver($cache);
        $query->setResultCacheId('testing_result_cache_id');

        self::assertFalse($cache->contains('testing_result_cache_id'));

        $users = $query->getResult();

        self::assertTrue($cache->contains('testing_result_cache_id'));
    }

    public function testUseResultCache() : void
    {
        $cache = new ArrayCache();
        $query = $this->em->createQuery('select ux from Doctrine\Tests\Models\CMS\CmsUser ux');

        $query->useResultCache(true);
        $query->setResultCacheDriver($cache);
        $query->setResultCacheId('testing_result_cache_id');

        $users = $query->getResult();

        self::assertTrue($cache->contains('testing_result_cache_id'));

        $this->em->getConfiguration()->setResultCacheImpl(new ArrayCache());
    }

    /**
     * @group DDC-1026
     */
    public function testUseResultCacheParams() : void
    {
        $cache    = new ArrayCache();
        $sqlCount = count($this->sqlLoggerStack->queries);
        $query    = $this->em->createQuery('select ux from Doctrine\Tests\Models\CMS\CmsUser ux WHERE ux.id = ?1');

        $query->setParameter(1, 1);
        $query->setResultCacheDriver($cache);
        $query->useResultCache(true);
        $query->getResult();

        $query->setParameter(1, 2);
        $query->getResult();

        self::assertCount($sqlCount + 2, $this->sqlLoggerStack->queries, 'Two non-cached queries.');

        $query->setParameter(1, 1);
        $query->useResultCache(true);
        $query->getResult();

        $query->setParameter(1, 2);
        $query->getResult();

        self::assertCount($sqlCount + 2, $this->sqlLoggerStack->queries, 'The next two sql should have been cached, but were not.');
    }

    /**
     * @throws ORMException
     */
    public function testNativeQueryResultCaching() : NativeQuery
    {
        $cache = new ArrayCache();
        $rsm   = new ResultSetMapping();

        $rsm->addScalarResult('id', 'u', DBALType::getType('integer'));

        $query = $this->em->createNativeQuery('select u.id FROM cms_users u WHERE u.id = ?', $rsm);

        $query->setParameter(1, 10);
        $query->setResultCacheDriver($cache)->useResultCache(true);

        self::assertEquals(0, $this->getCacheSize($cache));

        $query->getResult();

        self::assertEquals(1, $this->getCacheSize($cache));

        return $query;
    }

    /**
     * @param string $query
     * @depends testNativeQueryResultCaching
     */
    public function testResultCacheNotDependsOnQueryHints($query) : void
    {
        $cache      = $query->getResultCacheDriver();
        $cacheCount = $this->getCacheSize($cache);

        $query->setHint('foo', 'bar');
        $query->getResult();

        self::assertEquals($cacheCount, $this->getCacheSize($cache));
    }

    /**
     * @param <type> $query
     * @depends testNativeQueryResultCaching
     */
    public function testResultCacheDependsOnParameters($query) : void
    {
        $cache      = $query->getResultCacheDriver();
        $cacheCount = $this->getCacheSize($cache);

        $query->setParameter(1, 50);
        $query->getResult();

        self::assertEquals($cacheCount + 1, $this->getCacheSize($cache));
    }

    /**
     * @param <type> $query
     * @depends testNativeQueryResultCaching
     */
    public function testResultCacheNotDependsOnHydrationMode($query) : void
    {
        $cache      = $query->getResultCacheDriver();
        $cacheCount = $this->getCacheSize($cache);

        self::assertNotEquals(Query::HYDRATE_ARRAY, $query->getHydrationMode());
        $query->getArrayResult();

        self::assertEquals($cacheCount, $this->getCacheSize($cache));
    }

    /**
     * @group DDC-909
     */
    public function testResultCacheWithObjectParameter() : void
    {
        $user1           = new CmsUser();
        $user1->name     = 'Roman';
        $user1->username = 'romanb';
        $user1->status   = 'dev';

        $user2           = new CmsUser();
        $user2->name     = 'Benjamin';
        $user2->username = 'beberlei';
        $user2->status   = 'dev';

        $article        = new CmsArticle();
        $article->text  = 'foo';
        $article->topic = 'baz';
        $article->user  = $user1;

        $this->em->persist($article);
        $this->em->persist($user1);
        $this->em->persist($user2);
        $this->em->flush();

        $query = $this->em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a WHERE a.user = ?1');
        $query->setParameter(1, $user1);

        $cache = new ArrayCache();

        $query->setResultCacheDriver($cache)->useResultCache(true);

        $articles = $query->getResult();

        self::assertCount(1, $articles);
        self::assertEquals('baz', $articles[0]->topic);

        $this->em->clear();

        $query2 = $this->em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a WHERE a.user = ?1');
        $query2->setParameter(1, $user1);

        $query2->setResultCacheDriver($cache)->useResultCache(true);

        $articles = $query2->getResult();

        self::assertCount(1, $articles);
        self::assertEquals('baz', $articles[0]->topic);

        $query3 = $this->em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a WHERE a.user = ?1');
        $query3->setParameter(1, $user2);

        $query3->setResultCacheDriver($cache)->useResultCache(true);

        $articles = $query3->getResult();

        self::assertCount(0, $articles);
    }
}
