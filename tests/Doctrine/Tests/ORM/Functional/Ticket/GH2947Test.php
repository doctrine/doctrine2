<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group 2947
 */
class GH2947Test extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        $this->resultCacheImpl = new ArrayCache();

        parent::setUp();

        $this->schemaTool->createSchema(
            [$this->em->getClassMetadata(GH2947Car::class)]
        );
    }

    public function testIssue() : void
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

    private function createQuery()
    {
        return $this->em->createQueryBuilder()
                         ->select('car')
                         ->from(GH2947Car::class, 'car')
                         ->getQuery()
                         ->enableResultCache(3600, 'foo-cache-id');
    }

    private function createData()
    {
        $this->em->persist(new GH2947Car('BMW'));
        $this->em->flush();
        $this->em->clear();
    }

    private function updateData()
    {
        $this->em->createQueryBuilder()
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
 * @ORM\Entity
 * @ORM\Table(name="GH2947_car")
 */
class GH2947Car
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=25)
     * @ORM\GeneratedValue(strategy="NONE")
     */
    public $brand;

    public function __construct(string $brand)
    {
        $this->brand = $brand;
    }

    public function __toString() : string
    {
        return $this->brand;
    }
}
