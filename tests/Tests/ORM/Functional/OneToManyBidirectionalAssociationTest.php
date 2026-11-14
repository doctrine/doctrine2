<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Tests\Models\ECommerce\ECommerceFeature;
use Doctrine\Tests\Models\ECommerce\ECommerceProduct;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests a bidirectional one-to-one association mapping (without inheritance).
 */
class OneToManyBidirectionalAssociationTest extends OrmFunctionalTestCase
{
    private ECommerceProduct $product;

    private ECommerceFeature $firstFeature;

    private ECommerceFeature $secondFeature;

    protected function setUp(): void
    {
        $this->useModelSet('ecommerce');

        parent::setUp();

        $this->product = new ECommerceProduct();
        $this->product->setName('Doctrine Cookbook');
        $this->firstFeature = new ECommerceFeature();
        $this->firstFeature->setDescription('Model writing tutorial');
        $this->secondFeature = new ECommerceFeature();
        $this->secondFeature->setDescription('Attributes examples');
    }

    public function testSavesAOneToManyAssociationWithCascadeSaveSet(): void
    {
        $this->product->addFeature($this->firstFeature);
        $this->product->addFeature($this->secondFeature);
        $this->_em->persist($this->product);
        $this->_em->flush();

        $this->assertFeatureForeignKeyIs($this->product->getId(), $this->firstFeature);
        $this->assertFeatureForeignKeyIs($this->product->getId(), $this->secondFeature);
    }

    public function testSavesAnEmptyCollection(): void
    {
        $this->_em->persist($this->product);
        $this->_em->flush();

        self::assertCount(0, $this->product->getFeatures());
    }

    public function testDoesNotSaveAnInverseSideSet(): void
    {
        $this->product->brokenAddFeature($this->firstFeature);
        $this->_em->persist($this->product);
        $this->_em->flush();

        $this->assertFeatureForeignKeyIs(null, $this->firstFeature);
    }

    public function testRemovesOneToOneAssociation(): void
    {
        $this->product->addFeature($this->firstFeature);
        $this->product->addFeature($this->secondFeature);
        $this->_em->persist($this->product);

        $this->product->removeFeature($this->firstFeature);
        $this->_em->flush();

        $this->assertFeatureForeignKeyIs(null, $this->firstFeature);
        $this->assertFeatureForeignKeyIs($this->product->getId(), $this->secondFeature);
    }

    public function testEagerLoadsOneToManyAssociation(): void
    {
        $this->createFixture();
        $query   = $this->_em->createQuery('select p, f from Doctrine\Tests\Models\ECommerce\ECommerceProduct p join p.features f');
        $result  = $query->getResult();
        $product = $result[0];

        $features = $product->getFeatures();

        self::assertInstanceOf(ECommerceFeature::class, $features[0]);
        self::assertFalse($this->isUninitializedObject($features[0]->getProduct()));
        self::assertSame($product, $features[0]->getProduct());
        self::assertEquals('Model writing tutorial', $features[0]->getDescription());
        self::assertInstanceOf(ECommerceFeature::class, $features[1]);
        self::assertSame($product, $features[1]->getProduct());
        self::assertFalse($this->isUninitializedObject($features[1]->getProduct()));
        self::assertEquals('Attributes examples', $features[1]->getDescription());
    }

    public function testLazyLoadsObjectsOnTheOwningSide(): void
    {
        $this->createFixture();

        $query    = $this->_em->createQuery('select p from Doctrine\Tests\Models\ECommerce\ECommerceProduct p');
        $result   = $query->getResult();
        $product  = $result[0];
        $features = $product->getFeatures();

        self::assertFalse($features->isInitialized());
        self::assertInstanceOf(ECommerceFeature::class, $features[0]);
        self::assertTrue($features->isInitialized());
        self::assertSame($product, $features[0]->getProduct());
        self::assertEquals('Model writing tutorial', $features[0]->getDescription());
        self::assertInstanceOf(ECommerceFeature::class, $features[1]);
        self::assertSame($product, $features[1]->getProduct());
        self::assertEquals('Attributes examples', $features[1]->getDescription());
    }

    public function testLazyLoadsObjectsOnTheInverseSide(): void
    {
        $this->createFixture();

        $query    = $this->_em->createQuery('select f from Doctrine\Tests\Models\ECommerce\ECommerceFeature f');
        $features = $query->getResult();

        $product = $features[0]->getProduct();
        self::assertInstanceOf(ECommerceProduct::class, $product);
        self::assertTrue($this->isUninitializedObject($product));
        self::assertSame('Doctrine Cookbook', $product->getName());
        self::assertFalse($this->isUninitializedObject($product));
    }

    public function testLazyLoadsObjectsOnTheInverseSide2(): void
    {
        $this->createFixture();

        $query    = $this->_em->createQuery('select f,p from Doctrine\Tests\Models\ECommerce\ECommerceFeature f join f.product p');
        $features = $query->getResult();

        $product = $features[0]->getProduct();
        self::assertFalse($this->isUninitializedObject($product));
        self::assertInstanceOf(ECommerceProduct::class, $product);
        self::assertSame('Doctrine Cookbook', $product->getName());

        self::assertFalse($product->getFeatures()->isInitialized());

        // This would trigger lazy-load
        //$this->assertEquals(2, $product->getFeatures()->count());
        //$this->assertTrue($product->getFeatures()->contains($features[0]));
        //$this->assertTrue($product->getFeatures()->contains($features[1]));
    }

    public function testJoinFromOwningSide(): void
    {
        $query    = $this->_em->createQuery('select f,p from Doctrine\Tests\Models\ECommerce\ECommerceFeature f join f.product p');
        $features = $query->getResult();
        self::assertCount(0, $features);
    }

    #[Group('DDC-1637')]
    public function testMatching(): void
    {
        $this->createFixture();

        $product  = $this->_em->find(ECommerceProduct::class, $this->product->getId());
        $features = $product->getFeatures();

        $results = $features->matching(Criteria::create()->where(
            Criteria::expr()->eq('description', 'Model writing tutorial'),
        ));

        self::assertInstanceOf(Collection::class, $results);
        self::assertCount(1, $results);

        $results = $features->matching(Criteria::create());

        self::assertInstanceOf(Collection::class, $results);
        self::assertCount(2, $results);
    }

    #[Group('DDC-2340')]
    public function testMatchingOnDirtyCollection(): void
    {
        $this->createFixture();

        $product = $this->_em->find(ECommerceProduct::class, $this->product->getId());

        $thirdFeature = new ECommerceFeature();
        $thirdFeature->setDescription('Model writing tutorial');

        $features = $product->getFeatures();
        $features->add($thirdFeature);

        $results = $features->matching(Criteria::create()->where(
            Criteria::expr()->eq('description', 'Model writing tutorial'),
        ));

        self::assertCount(2, $results);
    }

    public function testMatchingBis(): void
    {
        $this->createFixture();

        $product  = $this->_em->find(ECommerceProduct::class, $this->product->getId());
        $features = $product->getFeatures();

        $thirdFeature = new ECommerceFeature();
        $thirdFeature->setDescription('Third feature');
        $product->addFeature($thirdFeature);

        $results = $features->matching(Criteria::create()->where(
            Criteria::expr()->eq('description', 'Third feature'),
        ));

        self::assertInstanceOf(Collection::class, $results);
        self::assertCount(1, $results);

        $results = $features->matching(Criteria::create());

        self::assertInstanceOf(Collection::class, $results);
        self::assertCount(3, $results);
    }

    private function createFixture(): void
    {
        $this->product->addFeature($this->firstFeature);
        $this->product->addFeature($this->secondFeature);
        $this->_em->persist($this->product);

        $this->_em->flush();
        $this->_em->clear();
    }

    public function assertFeatureForeignKeyIs($value, ECommerceFeature $feature): void
    {
        $foreignKey = $this->_em->getConnection()->executeQuery(
            'SELECT product_id FROM ecommerce_features WHERE id=?',
            [$feature->getId()],
        )->fetchOne();
        self::assertEquals($value, $foreignKey);
    }
}
