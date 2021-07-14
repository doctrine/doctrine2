<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @group GH-2947
 */
class GH2947Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->resultCacheImpl = DoctrineProvider::wrap(new ArrayAdapter());

        parent::setUp();

        $this->_schemaTool->createSchema([$this->_em->getClassMetadata(GH2947Car::class)]);
    }

    public function testIssue(): void
    {
        $this->createData();
        $initialQueryCount = $this->getCurrentQueryCount();

        $query = $this->createQuery();
        self::assertEquals('BMW', (string) $query->getSingleResult());
        self::assertEquals($initialQueryCount + 1, $this->getCurrentQueryCount());

        $this->updateData();
        self::assertEquals('BMW', (string) $query->getSingleResult());
        self::assertEquals($initialQueryCount + 2, $this->getCurrentQueryCount());

        $query->expireResultCache(true);
        self::assertEquals('Dacia', (string) $query->getSingleResult());
        self::assertEquals($initialQueryCount + 3, $this->getCurrentQueryCount());

        $query->expireResultCache(false);
        self::assertEquals('Dacia', (string) $query->getSingleResult());
        self::assertEquals($initialQueryCount + 3, $this->getCurrentQueryCount());
    }

    private function createQuery(): Query
    {
        return $this->_em->createQueryBuilder()
                         ->select('car')
                         ->from(GH2947Car::class, 'car')
                         ->getQuery()
                         ->useResultCache(true, 3600, 'foo-cache-id');
    }

    private function createData(): void
    {
        $this->_em->persist(new GH2947Car('BMW'));
        $this->_em->flush();
        $this->_em->clear();
    }

    private function updateData(): void
    {
        $this->_em->createQueryBuilder()
                  ->update(GH2947Car::class, 'car')
                  ->set('car.brand', ':newBrand')
                  ->where('car.brand = :oldBrand')
                  ->setParameter('newBrand', 'Dacia')
                  ->setParameter('oldBrand', 'BMW')
                  ->getQuery()
                  ->execute();
    }
}

/**
 * @Entity
 * @Table(name="GH2947_car")
 */
class GH2947Car
{
    /**
     * @var string
     * @Id
     * @Column(type="string", length=25)
     * @GeneratedValue(strategy="NONE")
     */
    public $brand;

    public function __construct(string $brand)
    {
        $this->brand = $brand;
    }

    public function __toString(): string
    {
        return $this->brand;
    }
}
