<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Events;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\Tests\Models\DynamicProperties\Customer;
use Doctrine\Tests\Models\DynamicProperties\LoyaltyMetadataListener;
use Doctrine\Tests\Models\DynamicProperties\LoyaltyTier;
use Doctrine\Tests\Models\DynamicProperties\Product;
use Doctrine\Tests\Models\DynamicProperties\ProductStatus;
use Doctrine\Tests\Models\DynamicProperties\Vendor;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_column;
use function array_keys;
use function array_map;
use function sort;
use function strtolower;

class DynamicPropertiesTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Simulates a plugin, it must be registered before the metadata of Customer is loaded.
        $this->_em->getEventManager()->addEventListener(Events::loadClassMetadata, new LoyaltyMetadataListener());

        $this->setUpEntitySchema([
            Vendor::class,
            Product::class,
            LoyaltyTier::class,
            Customer::class,
        ]);
    }

    protected function tearDown(): void
    {
        $conn = $this->_em->getConnection();

        $conn->executeStatement('DELETE FROM dynamic_properties_product');
        $conn->executeStatement('DELETE FROM dynamic_properties_vendor');
        $conn->executeStatement('DELETE FROM dynamic_properties_customer');
        $conn->executeStatement('DELETE FROM dynamic_properties_loyalty_tier');

        parent::tearDown();
    }

    public function testSchemaContainsDynamicColumns(): void
    {
        $columns = array_map(
            strtolower(...),
            array_keys($this->createSchemaManager()->listTableColumns('dynamic_properties_product')),
        );
        sort($columns);

        self::assertSame(['color', 'id', 'name', 'size_eu', 'status', 'vendor_id'], $columns);
    }

    public function testPersistAndLoad(): void
    {
        $vendor  = new Vendor('ACME');
        $product = new Product('Shirt');
        $product->set('color', 'red');
        $product->set('size', 42);
        $product->set('status', ProductStatus::Active);
        $product->set('vendor', $vendor);

        $this->_em->persist($vendor);
        $this->_em->persist($product);
        $this->_em->flush();
        $this->_em->clear();

        // Not taken from the query log, the second level cache test run serves find() from the cache.
        $sql = $this->_em->getUnitOfWork()->getEntityPersister(Product::class)->getSelectSQL([]);
        self::assertStringContainsString('color', $sql);
        self::assertStringContainsString('size_eu', $sql);
        self::assertStringContainsString('vendor_id', $sql);

        $product = $this->_em->find(Product::class, $product->id);

        self::assertSame('Shirt', $product->name);
        self::assertSame('red', $product->get('color'));
        self::assertSame(42, $product->get('size'));
        self::assertSame(ProductStatus::Active, $product->get('status'));
        self::assertInstanceOf(Vendor::class, $product->get('vendor'));
        self::assertSame('ACME', $product->get('vendor')->name);
    }

    public function testChangeSetContainsOnlyModifiedKeys(): void
    {
        $product = $this->createProduct(['color' => 'red', 'size' => 42]);

        $product = $this->_em->find(Product::class, $product->id);
        $product->set('color', 'blue');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertSame(['color' => ['red', 'blue']], $uow->getEntityChangeSet($product));

        $this->_em->flush();
        $this->_em->clear();

        self::assertSame('blue', $this->_em->find(Product::class, $product->id)->get('color'));
    }

    public function testLoadedEntityWithMissingKeysIsNotDirty(): void
    {
        $product = $this->createProduct(['color' => 'red']);

        $product = $this->_em->find(Product::class, $product->id);

        self::assertSame(['color' => 'red'], $product->all(), 'NULL columns are not added to the array');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertSame([], $uow->getEntityChangeSet($product));
    }

    public function testRemovingKeyWritesNull(): void
    {
        $product = $this->createProduct(['color' => 'red', 'size' => 42]);

        $product = $this->_em->find(Product::class, $product->id);
        $product->remove('color');
        $this->_em->flush();
        $this->_em->clear();

        $row = $this->_em->getConnection()->fetchAssociative(
            'SELECT color, size_eu FROM dynamic_properties_product WHERE id = ?',
            [$product->id],
        );

        self::assertNull($row['color']);
        self::assertEquals(42, $row['size_eu']);
        self::assertSame(['size' => 42], $this->_em->find(Product::class, $product->id)->all());
    }

    public function testUnmappedKeyThrowsOnInsert(): void
    {
        $product = new Product('Shirt');
        $product->set('color', 'red');
        $product->set('unmapped', 'value');
        $product->set('other', null);

        $this->_em->persist($product);

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage('The dynamic properties container "attributes" of entity ' . Product::class);
        $this->expectExceptionMessage('contains the unmapped key(s) "unmapped", "other".');

        $this->_em->flush();
    }

    public function testUnmappedKeyThrowsOnUpdateWithoutWritingChanges(): void
    {
        $product = $this->createProduct(['color' => 'red']);

        $product = $this->_em->find(Product::class, $product->id);
        $product->set('color', 'blue');
        $product->set('unmapped', 'value');

        try {
            $this->_em->flush();
            self::fail('Expected an exception for the unmapped key.');
        } catch (ORMInvalidArgumentException $e) {
            self::assertStringContainsString('contains the unmapped key(s) "unmapped".', $e->getMessage());
        }

        self::assertTrue($this->_em->isOpen());
        self::assertSame(
            'red',
            $this->_em->getConnection()->fetchOne('SELECT color FROM dynamic_properties_product WHERE id = ?', [$product->id]),
        );
    }

    public function testUnmappedKeyThrowsForContainerFilledByListener(): void
    {
        $customer = new Customer('Alice');
        $customer->set('loyalty_points', 10);
        $customer->set('loyalty_level', 'gold');

        $this->_em->persist($customer);

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage('The dynamic properties container "extra" of entity ' . Customer::class);

        $this->_em->flush();
    }

    public function testQueryingDynamicProperties(): void
    {
        $vendor = new Vendor('ACME');
        $this->_em->persist($vendor);

        $this->createProduct(['color' => 'red', 'size' => 42, 'vendor' => $vendor], 'Shirt');
        $this->createProduct(['color' => 'blue', 'size' => 38], 'Pants');

        $result = $this->_em->createQuery(
            'SELECT p.name FROM ' . Product::class . ' p WHERE p.color = :color',
        )->setParameter('color', 'blue')->getScalarResult();

        self::assertSame(['Pants'], array_column($result, 'name'));

        $result = $this->_em->createQuery(
            'SELECT p.name, v.name AS vendorName FROM ' . Product::class . ' p JOIN p.vendor v',
        )->getScalarResult();

        self::assertSame([['name' => 'Shirt', 'vendorName' => 'ACME']], $result);

        $products = $this->_em->getRepository(Product::class)->findBy(['size' => 42]);

        self::assertCount(1, $products);
        self::assertSame('Shirt', $products[0]->name);

        $result = $this->_em->createQuery(
            'SELECT p.name FROM ' . Product::class . ' p ORDER BY p.size ASC',
        )->getScalarResult();

        self::assertSame(['Pants', 'Shirt'], array_column($result, 'name'));
    }

    public function testFieldsAndAssociationsAddedByListener(): void
    {
        $metadata = $this->_em->getClassMetadata(Customer::class);

        self::assertTrue($metadata->isDynamicProperty('loyalty_points'));
        self::assertTrue($metadata->isDynamicProperty('loyalty_tier'));

        $tier     = new LoyaltyTier('Gold');
        $customer = new Customer('Alice');
        $customer->set('loyalty_points', 120);
        $customer->set('loyalty_tier', $tier);

        $this->_em->persist($tier);
        $this->_em->persist($customer);
        $this->_em->flush();
        $this->_em->clear();

        $customer = $this->_em->find(Customer::class, $customer->id);

        self::assertSame(120, $customer->get('loyalty_points'));
        self::assertSame('Gold', $customer->get('loyalty_tier')->name);

        $result = $this->_em->createQuery(
            'SELECT c.name FROM ' . Customer::class . ' c JOIN c.loyalty_tier t WHERE c.loyalty_points > 100 AND t.name = :tier',
        )->setParameter('tier', 'Gold')->getScalarResult();

        self::assertSame(['Alice'], array_column($result, 'name'));
    }

    /** @param array<string, mixed> $values */
    private function createProduct(array $values, string $name = 'Shirt'): Product
    {
        $product = new Product($name);

        foreach ($values as $key => $value) {
            $product->set($key, $value);
        }

        $this->_em->persist($product);
        $this->_em->flush();
        $this->_em->clear();

        return $product;
    }
}
