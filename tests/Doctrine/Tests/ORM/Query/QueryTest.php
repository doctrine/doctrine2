<?php

namespace Doctrine\Tests\ORM\Query;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Collections\ArrayCollection;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\Parameter;
use Doctrine\Tests\Mocks\DriverConnectionMock;
use Doctrine\Tests\Mocks\StatementArrayMock;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\DDC3597\DDC3597Image;
use Doctrine\Tests\Models\DDC3597\Embeddable\DDC3597Dimension;
use Doctrine\Tests\OrmTestCase;

class QueryTest extends OrmTestCase
{
    /** @var EntityManager */
    protected $_em = null;

    protected function setUp()
    {
        $this->_em = $this->_getTestEntityManager();
    }

    public function testGetParameters()
    {
        $query = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1");

        $parameters = new ArrayCollection();

        $this->assertEquals($parameters, $query->getParameters());
    }

    public function testGetParameters_HasSomeAlready()
    {
        $query = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1");
        $query->setParameter(2, 84);

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(2, 84));

        $this->assertEquals($parameters, $query->getParameters());
    }

    public function testSetParameters()
    {
        $query = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1");

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(1, 'foo'));
        $parameters->add(new Parameter(2, 'bar'));

        $query->setParameters($parameters);

        $this->assertEquals($parameters, $query->getParameters());
    }

    public function testFree()
    {
        $query = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1");
        $query->setParameter(2, 84, \PDO::PARAM_INT);

        $query->free();

        $this->assertEquals(0, count($query->getParameters()));
    }

    public function testClone()
    {
        $dql = "select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1";

        $query = $this->_em->createQuery($dql);
        $query->setParameter(2, 84, \PDO::PARAM_INT);
        $query->setHint('foo', 'bar');

        $cloned = clone $query;

        $this->assertEquals($dql, $cloned->getDQL());
        $this->assertEquals(0, count($cloned->getParameters()));
        $this->assertFalse($cloned->getHint('foo'));
    }

    public function testFluentQueryInterface()
    {
        $q = $this->_em->createQuery("select a from Doctrine\Tests\Models\CMS\CmsArticle a");
        $q2 = $q->expireQueryCache(true)
          ->setQueryCacheLifetime(3600)
          ->setQueryCacheDriver(null)
          ->expireResultCache(true)
          ->setHint('foo', 'bar')
          ->setHint('bar', 'baz')
          ->setParameter(1, 'bar')
          ->setParameters(new ArrayCollection(array(new Parameter(2, 'baz'))))
          ->setResultCacheDriver(null)
          ->setResultCacheId('foo')
          ->setDQL('foo')
          ->setFirstResult(10)
          ->setMaxResults(10);

        $this->assertSame($q2, $q);
    }

    /**
     * @group DDC-968
     */
    public function testHints()
    {
        $q = $this->_em->createQuery("select a from Doctrine\Tests\Models\CMS\CmsArticle a");
        $q->setHint('foo', 'bar')->setHint('bar', 'baz');

        $this->assertEquals('bar', $q->getHint('foo'));
        $this->assertEquals('baz', $q->getHint('bar'));
        $this->assertEquals(array('foo' => 'bar', 'bar' => 'baz'), $q->getHints());
        $this->assertTrue($q->hasHint('foo'));
        $this->assertFalse($q->hasHint('barFooBaz'));
    }

    /**
     * @group DDC-1588
     */
    public function testQueryDefaultResultCache()
    {
        $this->_em->getConfiguration()->setResultCacheImpl(new ArrayCache());
        $q = $this->_em->createQuery("select a from Doctrine\Tests\Models\CMS\CmsArticle a");
        $q->useResultCache(true);
        $this->assertSame($this->_em->getConfiguration()->getResultCacheImpl(), $q->getQueryCacheProfile()->getResultCacheDriver());
    }

    /**
     * @expectedException Doctrine\ORM\Query\QueryException
     **/
    public function testIterateWithNoDistinctAndWrongSelectClause()
    {
        $q = $this->_em->createQuery("select u, a from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a");
        $q->iterate();
    }

    /**
     * @expectedException Doctrine\ORM\Query\QueryException
     **/
    public function testIterateWithNoDistinctAndWithValidSelectClause()
    {
        $q = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a");
        $q->iterate();
    }

    public function testIterateWithDistinct()
    {
        $q = $this->_em->createQuery("SELECT DISTINCT u from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a");
        $q->iterate();
    }

    /**
     * @group DDC-1697
     */
    public function testCollectionParameters()
    {
        $cities = array(
            0 => "Paris",
            3 => "Canne",
            9 => "St Julien"
        );

        $query  = $this->_em
                ->createQuery("SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)")
                ->setParameter('cities', $cities);

        $parameters = $query->getParameters();
        $parameter  = $parameters->first();

        $this->assertEquals('cities', $parameter->getName());
        $this->assertEquals($cities, $parameter->getValue());
    }

    /**
     * @group DDC-2224
     */
    public function testProcessParameterValueClassMetadata()
    {
        $query  = $this->_em->createQuery("SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)");
        $this->assertEquals(
            'Doctrine\Tests\Models\CMS\CmsAddress',
            $query->processParameterValue($this->_em->getClassMetadata('Doctrine\Tests\Models\CMS\CmsAddress'))
        );
    }

    /**
     * @group 5936
     */
    public function testProcessParameterValueEmbeddable()
    {
        $query  = $this->_em->createQuery("SELECT a FROM " . DDC3597Image::class . " a");

        // load class meta data
        $this->_em->getClassMetadata(DDC3597Image::class);
        $this->assertTrue($this->_em->getMetadataFactory()->hasMetadataFor(DDC3597Image::class));
        $this->assertTrue($this->_em->getMetadataFactory()->hasMetadataFor(DDC3597Dimension::class));

        $this->assertInstanceOf(
            DDC3597Dimension::class,
            $query->processParameterValue(new DDC3597Dimension())
        );
    }

    public function testDefaultQueryHints()
    {
        $config = $this->_em->getConfiguration();
        $defaultHints = array(
            'hint_name_1' => 'hint_value_1',
            'hint_name_2' => 'hint_value_2',
            'hint_name_3' => 'hint_value_3',
        );

        $config->setDefaultQueryHints($defaultHints);
        $query = $this->_em->createQuery();
        $this->assertSame($config->getDefaultQueryHints(), $query->getHints());
        $this->_em->getConfiguration()->setDefaultQueryHint('hint_name_1', 'hint_another_value_1');
        $this->assertNotSame($config->getDefaultQueryHints(), $query->getHints());
        $q2 = clone $query;
        $this->assertSame($config->getDefaultQueryHints(), $q2->getHints());
    }

    /**
     * @group DDC-3714
     */
    public function testResultCacheCaching()
    {
        $this->_em->getConfiguration()->setResultCacheImpl(new ArrayCache());
        $this->_em->getConfiguration()->setQueryCacheImpl(new ArrayCache());
        /** @var DriverConnectionMock $driverConnectionMock */
        $driverConnectionMock = $this->_em->getConnection()->getWrappedConnection();
        $stmt = new StatementArrayMock([
            [
                'id_0' => 1,
            ]
        ]);
        $driverConnectionMock->setStatementMock($stmt);
        $res = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u")
            ->useQueryCache(true)
            ->useResultCache(true, 60)
            //let it cache
            ->getResult();

        $this->assertCount(1, $res);

        $driverConnectionMock->setStatementMock(null);

        $res = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u")
            ->useQueryCache(true)
            ->useResultCache(false)
            ->getResult();
        $this->assertCount(0, $res);
    }

    /**
     * @group DDC-3741
     */
    public function testSetHydrationCacheProfileNull()
    {
        $query = $this->_em->createQuery();
        $query->setHydrationCacheProfile(null);
        $this->assertNull($query->getHydrationCacheProfile());
    }
}
