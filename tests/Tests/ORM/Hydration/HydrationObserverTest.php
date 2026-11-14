<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Hydration;

use Doctrine\ORM\Events;
use Doctrine\ORM\HydrationObserver;
use Doctrine\ORM\Internal\Hydration\ArrayHydrator;
use Doctrine\ORM\Internal\Hydration\SingleScalarHydrator;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\Models\CMS\CmsUser;
use PHPUnit\Framework\Attributes\TestWith;

class HydrationObserverTest extends HydrationTestCase
{
    public function testObservesBulkHydrationWithoutChangingResults(): void
    {
        $observer = $this->createMock(HydrationObserver::class);
        $observer->expects(self::once())->method('start')->with(ArrayHydrator::class, ['user' => CmsUser::class]);
        $observer->expects(self::once())->method('end')->with(2);

        $configuration = $this->entityManager->getConfiguration();
        $configuration->setHydrationObserver($observer);

        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(CmsUser::class, 'user');
        $mapping->addFieldResult('user', 'id', 'id');

        $hydrator = $this->entityManager->newHydrator(Query::HYDRATE_ARRAY);
        $result   = $this->createResultMock([['id' => 1], ['id' => 2]]);

        self::assertSame([['id' => 1], ['id' => 2]], $hydrator->hydrateAll($result, $mapping));
    }

    public function testEndsObservationWhenHydrationFails(): void
    {
        $observer = $this->createMock(HydrationObserver::class);
        $observer->expects(self::once())->method('start')->with(SingleScalarHydrator::class, []);
        $observer->expects(self::once())->method('end')->with(null);

        $configuration = $this->entityManager->getConfiguration();
        $configuration->setHydrationObserver($observer);

        $mapping = new ResultSetMapping();
        $mapping->addScalarResult('id', 'id', 'integer');

        $hydrator     = $this->entityManager->newHydrator(Query::HYDRATE_SINGLE_SCALAR);
        $result       = $this->createResultMock([['id' => 1], ['id' => 2]]);
        $eventManager = $this->entityManager->getEventManager();

        $this->expectException(NonUniqueResultException::class);

        try {
            $hydrator->hydrateAll($result, $mapping);
        } finally {
            self::assertFalse($eventManager->hasListeners(Events::onClear));
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testObservesStreamOnlyWhileAdvancingIt(bool $exhaustStream): void
    {
        $counts   = [];
        $observer = $this->createMock(HydrationObserver::class);
        $observer->expects(self::exactly($exhaustStream ? 3 : 2))
            ->method('start')
            ->with(ArrayHydrator::class, ['user' => CmsUser::class]);
        $observer->method('end')->willReturnCallback(static function (int|null $count) use (&$counts): void {
            $counts[] = $count;
        });

        $configuration = $this->entityManager->getConfiguration();
        $configuration->setHydrationObserver($observer);

        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(CmsUser::class, 'user');
        $mapping->addFieldResult('user', 'id', 'id');
        $mapping->addIndexBy('user', 'id');

        $hydrator     = $this->entityManager->newHydrator(Query::HYDRATE_ARRAY);
        $result       = $this->createResultMock([['id' => 10], ['id' => 20]]);
        $rows         = $hydrator->toIterable($result, $mapping);
        $eventManager = $this->entityManager->getEventManager();

        self::assertSame([], $counts);
        $rows->rewind();
        self::assertSame(10, $rows->key());
        self::assertSame(['id' => 10], $rows->current());
        self::assertSame([1], $counts);

        $rows->next();
        self::assertSame(20, $rows->key());
        self::assertSame(['id' => 20], $rows->current());
        self::assertSame([1, 1], $counts);

        if ($exhaustStream) {
            $rows->next();
            self::assertFalse($rows->valid());
        }

        unset($rows);
        self::assertSame($exhaustStream ? [1, 1, 0] : [1, 1], $counts);
        self::assertFalse($eventManager->hasListeners(Events::onClear));
    }
}
