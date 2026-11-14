<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12505;

use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

// The second level cache does not preserve indexBy keys (#5889), which this test asserts.
#[Group('non-cacheable')]
final class GH12505Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            Vehicle::class,
            Car::class,
            Garage::class,
        ]);
    }

    public function testHydrateManyToManyIndexedByInheritedIdAfterFilterChange(): void
    {
        $this->_em->getConfiguration()->addFilter('soft_delete', SoftDeleteFilter::class);
        $this->_em->getFilters()->enable('soft_delete');

        $sedan  = new Car('sedan');
        $coupe  = new Car('coupe');
        $garage = new Garage();
        $garage->addCar($sedan);
        $garage->addCar($coupe);

        $this->_em->persist($sedan);
        $this->_em->persist($coupe);
        $this->_em->persist($garage);
        $this->_em->flush();
        $this->_em->clear();

        // Load some cars while the filter is suspended ...
        $this->_em->getFilters()->suspend('soft_delete');
        $this->_em->getRepository(Car::class)->findBy([]);
        $this->_em->getFilters()->restore('soft_delete');

        // ... then load the indexed collection with it enabled again.
        $garage = $this->_em->getRepository(Garage::class)->findOneBy([]);
        $cars   = $garage->getCars()->toArray();

        self::assertCount(2, $cars);

        // The keys must be the inherited ids.
        foreach ($cars as $key => $car) {
            self::assertSame($car->getId(), $key);
        }
    }
}
