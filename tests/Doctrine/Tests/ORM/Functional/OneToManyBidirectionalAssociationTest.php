<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\Tests\Models\ECommerce\ECommerceFeature;
use Doctrine\Tests\Models\ECommerce\ECommerceProduct;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Tests a bidirectional one-to-one association mapping (without inheritance).
 */
class OneToManyBidirectionalAssociationTest extends OrmFunctionalTestCase
{
    private $product;
    private $firstFeature;
    private $secondFeature;

    protected function setUp() : void
    {
        $this->useModelSet('ecommerce');
        parent::setUp();
        $this->product = new ECommerceProduct();
        $this->product->setName('Doctrine Cookbook');
        $this->firstFeature = new ECommerceFeature();
        $this->firstFeature->setDescription('Model writing tutorial');
        $this->secondFeature = new ECommerceFeature();
        $this->secondFeature->setDescription('Annotations examples');
    }

    public function testSavesAOneToManyAssociationWithCascadeSaveSet() {
        $this->product->addFeature($this->firstFeature);
        $this->product->addFeature($this->secondFeature);
        $this->_em->persist($this->product);
        $this->_em->flush();

        $this->assertFeatureForeignKeyIs($this->product->getId(), $this->firstFeature);
        $this->assertFeatureForeignKeyIs($this->product->getId(), $this->secondFeature);
    }

    public function testSavesAnEmptyCollection()
    {
        $this->_em->persist($this->product);
        $this->_em->flush();

        $this->assertEquals(0, count($this->product->getFeatures()));
    }

    public function testDoesNotSaveAnInverseSideSet() {
        $this->product->brokenAddFeature($this->firstFeature);
        $this->_em->persist($this->product);
        $this->_em->flush();

        $this->assertFeatureForeignKeyIs(null, $this->firstFeature);
    }

    public function testRemovesOneToOneAssociation()
    {
        $this->product->addFeature($this->firstFeature);
        $this->product->addFeature($this->secondFeature);
        $this->_em->persist($this->product);

        $this->product->removeFeature($this->firstFeature);
        $this->_em->flush();

        $this->assertFeatureForeignKeyIs(null, $this->firstFeature);
        $this->assertFeatureForeignKeyIs($this->product->getId(), $this->secondFeature);
    }

    public function testEagerLoadsOneToManyAssociation()
    {
        $this->_createFixture();
        $query = $this->_em->createQuery('select p, f from Doctrine\Tests\Models\ECommerce\ECommerceProduct p join p.features f');
        $result = $query->getResult();
        $product = $result[0];

        $features = $product->getFeatures();

        $this->assertInstanceOf(ECommerceFeature::class, $features[0]);
        $this->assertNotInstanceOf(Proxy::class, $features[0]->getProduct());
        $this->assertSame($product, $features[0]->getProduct());
        $this->assertEquals('Model writing tutorial', $features[0]->getDescription());
        $this->assertInstanceOf(ECommerceFeature::class, $features[1]);
        $this->assertSame($product, $features[1]->getProduct());
        $this->assertNotInstanceOf(Proxy::class, $features[1]->getProduct());
        $this->assertEquals('Annotations examples', $features[1]->getDescription());
    }

    public function testLazyLoadsObjectsOnTheOwningSide()
    {
        $this->_createFixture();

        $query = $this->_em->createQuery('select p from Doctrine\Tests\Models\ECommerce\ECommerceProduct p');
        $result = $query->getResult();
        $product = $result[0];
        $features = $product->getFeatures();

        $this->assertFalse($features->isInitialized());
        $this->assertInstanceOf(ECommerceFeature::class, $features[0]);
        $this->assertTrue($features->isInitialized());
        $this->assertSame($product, $features[0]->getProduct());
        $this->assertEquals('Model writing tutorial', $features[0]->getDescription());
        $this->assertInstanceOf(ECommerceFeature::class, $features[1]);
        $this->assertSame($product, $features[1]->getProduct());
        $this->assertEquals('Annotations examples', $features[1]->getDescription());
    }

    public function testLazyLoadsObjectsOnTheInverseSide()
    {
        $this->_createFixture();

        $query = $this->_em->createQuery('select f from Doctrine\Tests\Models\ECommerce\ECommerceFeature f');
        $features = $query->getResult();

        $product = $features[0]->getProduct();
        $this->assertInstanceOf(Proxy::class, $product);
        $this->assertInstanceOf(ECommerceProduct::class, $product);
        $this->assertFalse($product->__isInitialized__);
        $this->assertSame('Doctrine Cookbook', $product->getName());
        $this->assertTrue($product->__isInitialized__);
    }

    public function testLazyLoadsObjectsOnTheInverseSide2()
    {
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);
        $this->_createFixture();

        $query = $this->_em->createQuery('select f,p from Doctrine\Tests\Models\ECommerce\ECommerceFeature f join f.product p');
        $features = $query->getResult();

        $product = $features[0]->getProduct();
        $this->assertNotInstanceOf(Proxy::class, $product);
        $this->assertInstanceOf(ECommerceProduct::class, $product);
        $this->assertSame('Doctrine Cookbook', $product->getName());

        $this->assertFalse($product->getFeatures()->isInitialized());

        // This would trigger lazy-load
        //$this->assertEquals(2, $product->getFeatures()->count());
        //$this->assertTrue($product->getFeatures()->contains($features[0]));
        //$this->assertTrue($product->getFeatures()->contains($features[1]));

        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(null);
    }

    public function testJoinFromOwningSide()
    {
        $query = $this->_em->createQuery('select f,p from Doctrine\Tests\Models\ECommerce\ECommerceFeature f join f.product p');
        $features = $query->getResult();
        $this->assertEquals(0, count($features));
    }

    /**
     * @group DDC-1637
     */
    public function testMatching()
    {
        $this->_createFixture();

        $product  = $this->_em->find(ECommerceProduct::class, $this->product->getId());
        $features = $product->getFeatures();

        $results = $features->matching(new Criteria(
            Criteria::expr()->eq('description', 'Model writing tutorial')
        ));

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertEquals(1, count($results));

        $results = $features->matching(new Criteria());

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertEquals(2, count($results));
    }

    /**
     * @group DDC-2340
     */
    public function testMatchingOnDirtyCollection()
    {
        $this->_createFixture();

        $product  = $this->_em->find(ECommerceProduct::class, $this->product->getId());

        $thirdFeature = new ECommerceFeature();
        $thirdFeature->setDescription('Model writing tutorial');

        $features = $product->getFeatures();
        $features->add($thirdFeature);

        $results = $features->matching(new Criteria(
            Criteria::expr()->eq('description', 'Model writing tutorial')
        ));

        $this->assertEquals(2, count($results));
    }

    public function testMatchingBis()
    {
        $this->_createFixture();

        $product  = $this->_em->find(ECommerceProduct::class, $this->product->getId());
        $features = $product->getFeatures();

        $thirdFeature = new ECommerceFeature();
        $thirdFeature->setDescription('Third feature');
        $product->addFeature($thirdFeature);

        $results = $features->matching(new Criteria(
            Criteria::expr()->eq('description', 'Third feature')
        ));

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(1, $results);

        $results = $features->matching(new Criteria());

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(3, $results);
    }

    private function _createFixture()
    {
        $this->product->addFeature($this->firstFeature);
        $this->product->addFeature($this->secondFeature);
        $this->_em->persist($this->product);

        $this->_em->flush();
        $this->_em->clear();
    }

    public function assertFeatureForeignKeyIs($value, ECommerceFeature $feature) {
        $foreignKey = $this->_em->getConnection()->executeQuery(
            'SELECT product_id FROM ecommerce_features WHERE id=?',
            [$feature->getId()]
        )->fetchOne();
        $this->assertEquals($value, $foreignKey);
    }
}
