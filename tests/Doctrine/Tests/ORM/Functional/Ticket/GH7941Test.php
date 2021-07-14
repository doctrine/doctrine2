<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function ltrim;
use function strlen;

/** @group GH7941 */
final class GH7941Test extends OrmFunctionalTestCase
{
    private const PRODUCTS = [
        ['name' => 'Test 1', 'price' => '100', 'square_root' => 10],
        ['name' => 'Test 2', 'price' => '100', 'square_root' => 10],
        ['name' => 'Test 3', 'price' => '100', 'square_root' => 10],
        ['name' => 'Test 4', 'price' => '25', 'square_root' => 5],
        ['name' => 'Test 5', 'price' => '25', 'square_root' => 5],
        ['name' => 'Test 6', 'price' => '-25', 'square_root' => 5],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([GH7941Product::class]);

        foreach (self::PRODUCTS as $product) {
            $this->_em->persist(new GH7941Product($product['name'], $product['price']));
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    /** @test */
    public function typesShouldBeConvertedForDQLFunctions(): void
    {
        $query = $this->_em->createQuery(
            'SELECT
                 COUNT(product.id) as count,
                 SUM(product.price) as sales,
                 AVG(product.price) as average
             FROM ' . GH7941Product::class . ' product'
        );

        $result = $query->getSingleResult();

        self::assertSame(6, $result['count']);
        self::assertSame('325', $result['sales']);

        // Driver return type and precision is determined by the underlying php extension, most seem to return a string.
        // pdo_mysql and mysqli both currently return '54.1667' so this is the maximum precision we can assert.
        // See https://github.com/doctrine/orm/pull/8532#pullrequestreview-610037209
        self::assertEqualsWithDelta(54.1667, $result['average'], 0.0001);

        $query = $this->_em->createQuery(
            'SELECT
                 ABS(product.price) as absolute,
                 SQRT(ABS(product.price)) as square_root,
                 LENGTH(product.name) as length
             FROM ' . GH7941Product::class . ' product'
        );

        foreach ($query->getResult() as $i => $item) {
            $product = self::PRODUCTS[$i];

            self::assertSame(ltrim($product['price'], '-'), $item['absolute']);
            self::assertSame(strlen($product['name']), $item['length']);

            // Driver return types for the `square_root` column are inconsistent depending on the underlying
            // database driver. Most return string (though some '10' and some '10.000000000000000') but at least mysqli
            // returns a float.
            self::assertEqualsWithDelta($product['square_root'], $item['square_root'], 0.00000001);
        }
    }
}

/**
 * @Entity
 * @Table()
 */
class GH7941Product
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @var string
     * @Column(type="decimal")
     */
    public $price;

    /**
     * @var DateTimeImmutable
     * @Column(type="datetime_immutable")
     */
    public $createdAt;

    public function __construct(string $name, string $price)
    {
        $this->name      = $name;
        $this->price     = $price;
        $this->createdAt = new DateTimeImmutable();
    }
}
