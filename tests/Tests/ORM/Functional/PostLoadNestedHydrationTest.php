<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Closure;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Internal\Hydration\ObjectHydrator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Tests\Models\Cache\Travel;
use Doctrine\Tests\Models\Cache\Traveler;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function iterator_to_array;
use function sprintf;

class PostLoadNestedHydrationTest extends OrmFunctionalTestCase
{
    private const HOOKED_HYDRATOR = 'postload-hooked';

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<object> */
    private array $postLoaded = [];

    private Closure|null $onPostLoad = null;

    protected function setUp(): void
    {
        $this->useModelSet('cms');
        $this->useModelSet('cache');

        parent::setUp();

        for ($i = 1; $i <= 3; $i++) {
            $user           = new CmsUser();
            $user->name     = 'User ' . $i;
            $user->username = 'user' . $i;
            $user->status   = 'active';

            $address          = new CmsAddress();
            $address->country = 'Germany';
            $address->city    = 'Bonn';
            $address->zip     = '5311' . $i;
            $user->setAddress($address);

            for ($k = 1; $k <= 2; $k++) {
                $phone              = new CmsPhonenumber();
                $phone->phonenumber = $i . '00' . $k;
                $user->addPhonenumber($phone);

                $group       = new CmsGroup();
                $group->name = 'Group ' . $i . '-' . $k;
                $user->addGroup($group);
                $this->_em->persist($group);
            }

            $this->_em->persist($user);
            $this->_em->flush();

            $this->userIds[] = $user->id;
        }

        $this->_em->clear();

        $this->_em->getConfiguration()->addCustomHydrationMode(self::HOOKED_HYDRATOR, HookedObjectHydrator::class);
        $this->_em->getEventManager()->addEventListener([Events::postLoad], $this);
    }

    protected function tearDown(): void
    {
        HookedObjectHydrator::$afterRow  = null;
        HookedObjectHydrator::$onCleanup = null;

        $this->_em->getEventManager()->removeEventListener([Events::postLoad], $this);

        parent::tearDown();
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity             = $args->getObject();
        $this->postLoaded[] = $entity;

        if ($this->onPostLoad !== null) {
            ($this->onPostLoad)($entity);
        }
    }

    public function testRemovalFromEagerBatchedOneToManyInPostLoadIsPersisted(): void
    {
        $this->loadTravelers();

        $this->onPostLoad = static function (object $entity): void {
            if ($entity instanceof Traveler) {
                $entity->travels->removeElement($entity->travels->first());
            }
        };

        $travelers = $this->eagerTravelsQuery()->getResult();

        self::assertCount(3, $travelers);

        foreach ($travelers as $traveler) {
            self::assertCount(1, $traveler->travels);
            self::assertTrue($traveler->travels->isDirty());
        }

        $this->_em->flush();

        self::assertSame(3, $this->countRows('cache_travel'));
    }

    public function testReadingEagerBatchedOneToManyInPostLoadDoesNotDuplicateItems(): void
    {
        $this->loadTravelers();

        $counts           = [];
        $this->onPostLoad = static function (object $entity) use (&$counts): void {
            if ($entity instanceof Traveler) {
                $counts[] = count($entity->travels);
            }
        };

        $travelers = $this->eagerTravelsQuery()->getResult();

        self::assertSame([2, 2, 2], $counts);
        self::assertSame([2, 2, 2], array_map(static fn (Traveler $traveler): int => count($traveler->travels), $travelers));
    }

    public function testEagerBatchedEntitiesReceivePostLoadAfterCollectionsAreBuilt(): void
    {
        $this->loadTravelers();

        $inCollection     = [];
        $this->onPostLoad = static function (object $entity) use (&$inCollection): void {
            if ($entity instanceof Travel) {
                $collection     = $entity->getTraveler()->travels;
                $inCollection[] = $collection->isInitialized() && ! $collection->isDirty() && $collection->contains($entity);
            }
        };

        $this->eagerTravelsQuery()->getResult();

        self::assertSame([true, true, true, true, true, true], $inCollection);
    }

    public function testRemovalFromFetchJoinedCollectionInPostLoadIsPersistedDespiteNestedLoadPerRow(): void
    {
        $this->onPostLoad = static function (object $entity): void {
            if ($entity instanceof CmsUser) {
                $entity->groups->removeElement($entity->groups->first());
            }
        };

        $users = $this->_em
            ->createQuery('SELECT u, g FROM ' . CmsUser::class . ' u JOIN u.groups g ORDER BY u.id, g.id')
            ->getResult();

        self::assertCount(3, $users);

        foreach ($users as $user) {
            self::assertCount(1, $user->groups);
            self::assertTrue($user->groups->isDirty());
        }

        $this->_em->flush();

        self::assertSame(3, $this->countRows('cms_users_groups'));
    }

    public function testEveryEntityOfNestedLoadsReceivesPostLoadOnce(): void
    {
        $this->eagerPhonenumbersQuery()->getResult();

        self::assertCount(3, $this->postLoadedOf(CmsUser::class));
        self::assertCount(3, $this->postLoadedOf(CmsAddress::class));
        self::assertCount(6, $this->postLoadedOf(CmsPhonenumber::class));
    }

    public function testPostLoadOrderOfNestedLoadsIsKept(): void
    {
        $this->eagerPhonenumbersQuery()->getResult();

        self::assertSame(
            [
                CmsAddress::class,
                CmsPhonenumber::class,
                CmsPhonenumber::class,
                CmsUser::class,
                CmsAddress::class,
                CmsPhonenumber::class,
                CmsPhonenumber::class,
                CmsUser::class,
                CmsAddress::class,
                CmsPhonenumber::class,
                CmsPhonenumber::class,
                CmsUser::class,
            ],
            array_map(static fn (object $entity): string => $entity::class, $this->postLoaded),
        );
    }

    public function testEntityLoadedInPostLoadListenerReceivesPostLoadBeforeTheLoadReturns(): void
    {
        $firedBeforeReturn = [];
        $this->onPostLoad  = function (object $entity) use (&$firedBeforeReturn): void {
            if (! ($entity instanceof CmsUser)) {
                return;
            }

            $phonenumber = $this->_em->getRepository(CmsPhonenumber::class)->findOneBy(['user' => $entity]);

            $firedBeforeReturn[] = $this->postLoaded[count($this->postLoaded) - 1] === $phonenumber;
        };

        $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u ORDER BY u.id')->getResult();

        self::assertSame([true, true, true], $firedBeforeReturn);
        self::assertCount(3, $this->postLoadedOf(CmsUser::class));
    }

    public function testArrayHydrationDuringObjectHydrationDoesNotFirePostLoadOfOuterHydration(): void
    {
        $firedDuringHydration = [];

        HookedObjectHydrator::$afterRow = function () use (&$firedDuringHydration): void {
            $this->_em->createQuery('SELECT u.id FROM ' . CmsUser::class . ' u')->getArrayResult();
            $this->_em->createQuery('SELECT g FROM ' . CmsGroup::class . ' g')->getArrayResult();
            $this->_em->createQuery('SELECT COUNT(u.id) FROM ' . CmsUser::class . ' u')->getSingleScalarResult();

            $firedDuringHydration[] = count($this->postLoadedOf(CmsUser::class));
        };

        $this->hookedQuery()->getResult(self::HOOKED_HYDRATOR);

        self::assertSame([0, 0, 0], $firedDuringHydration);
        self::assertCount(3, $this->postLoadedOf(CmsUser::class));
    }

    public function testIterationFiresPostLoadForEachRowBeforeItIsYielded(): void
    {
        $query = $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u ORDER BY u.id');

        foreach ($query->toIterable() as $user) {
            self::assertContains($user, $this->postLoaded);
        }

        self::assertCount(3, $this->postLoadedOf(CmsUser::class));
    }

    public function testIterationFiresPostLoadOfEntitiesLoadedLaterInTheRowBeforeItIsYielded(): void
    {
        $query = $this->_em->createQuery(
            'SELECT p, u FROM ' . CmsPhonenumber::class . ' p JOIN p.user u ORDER BY p.phonenumber',
        );

        foreach ($query->toIterable() as $phonenumber) {
            self::assertContains($phonenumber->user, $this->postLoaded);
            self::assertContains($phonenumber->user->address, $this->postLoaded);
        }
    }

    public function testIterationFiresPostLoadBeforeYieldForCustomHydratorCompletingItsRows(): void
    {
        $this->_em->getConfiguration()->addCustomHydrationMode('postload-row-completing', RowCompletingHydrator::class);

        $query = $this->_em->createQuery('SELECT g FROM ' . CmsGroup::class . ' g ORDER BY g.id');

        foreach ($query->toIterable([], 'postload-row-completing') as $group) {
            self::assertContains($group, $this->postLoaded);
        }
    }

    public function testEntityLoadedInsideIterationLoopReceivesPostLoadBeforeTheLoadReturns(): void
    {
        $query = $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u ORDER BY u.id');

        foreach ($query->toIterable() as $user) {
            $group = $this->_em->getRepository(CmsGroup::class)->findOneBy(['name' => 'Group 1-1']);

            self::assertContains($group, $this->postLoaded);

            $this->_em->clear(); // common batch processing pattern, must not disturb the iteration
            $this->postLoaded = [];
        }
    }

    public function testIterationAbandonedBeforeItsEndDoesNotDeferLaterPostLoads(): void
    {
        $query     = $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u ORDER BY u.id');
        $generator = $query->toIterable();

        foreach ($generator as $user) {
            break;
        }

        $group = $this->_em->getRepository(CmsGroup::class)->findOneBy(['name' => 'Group 1-1']);

        self::assertContains($group, $this->postLoaded, 'Iteration is suspended, but not finished');

        unset($generator);

        $group = $this->_em->getRepository(CmsGroup::class)->findOneBy(['name' => 'Group 2-1']);

        self::assertContains($group, $this->postLoaded, 'Iteration was destroyed');
    }

    public function testNativeQueryIterationFiresPostLoadForEachRowAfterEagerCollectionsAreLoaded(): void
    {
        $this->loadTravelers();

        $counts           = [];
        $this->onPostLoad = static function (object $entity) use (&$counts): void {
            if ($entity instanceof Traveler) {
                $counts[] = count($entity->travels);
            }
        };

        foreach ($this->eagerTravelsNativeQuery()->toIterable() as $traveler) {
            self::assertContains($traveler, $this->postLoaded);
        }

        self::assertSame([2, 2, 2], $counts);
    }

    public function testNativeQueryIterationDoesNotDuplicateEagerItemsAccessedInTheLoop(): void
    {
        $this->loadTravelers();

        $travelers = [];
        foreach ($this->eagerTravelsNativeQuery()->toIterable() as $traveler) {
            self::assertCount(2, $traveler->travels);

            $travelers[] = $traveler;
        }

        self::assertSame([2, 2, 2], array_map(static fn (Traveler $traveler): int => count($traveler->travels), $travelers));
    }

    public function testExceptionDuringHydrationFiresPostLoadOfAlreadyHydratedEntitiesAndKeepsTrackingIntact(): void
    {
        $rows                           = 0;
        HookedObjectHydrator::$afterRow = static function () use (&$rows): void {
            $rows++;

            if ($rows === 2) {
                throw new RuntimeException('hydration failed');
            }
        };

        $this->assertHydrationFails('hydration failed');
        $this->assertTrackingIsIntact([$this->userIds[0], $this->userIds[1]]);
    }

    public function testExceptionDuringNestedLoadThatIsCaughtDoesNotEndOuterHydration(): void
    {
        $firedDuringHydration           = [];
        $nested                         = false;
        HookedObjectHydrator::$afterRow = function () use (&$firedDuringHydration, &$nested): void {
            if ($nested) {
                throw new RuntimeException('nested load failed');
            }

            $nested = true;

            try {
                $this->_em->createQuery('SELECT g FROM ' . CmsGroup::class . ' g')->getResult(self::HOOKED_HYDRATOR);
                self::fail('Expected nested load to fail');
            } catch (RuntimeException $e) {
                self::assertSame('nested load failed', $e->getMessage());
            }

            $nested                 = false;
            $firedDuringHydration[] = count($this->postLoaded);
        };

        $this->hookedQuery()->getResult(self::HOOKED_HYDRATOR);

        self::assertSame([0, 0, 0], $firedDuringHydration);
        self::assertCount(3, $this->postLoadedOf(CmsUser::class));
    }

    public function testExceptionInCleanupFiresPostLoadAndKeepsTrackingIntact(): void
    {
        HookedObjectHydrator::$onCleanup = static function (): void {
            throw new RuntimeException('cleanup failed');
        };

        $this->assertHydrationFails('cleanup failed');
        $this->assertTrackingIsIntact($this->userIds);
    }

    public function testExceptionDuringIterationFiresPostLoadAndKeepsTrackingIntact(): void
    {
        $query = $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u ORDER BY u.id');

        try {
            foreach ($query->toIterable() as $user) {
                throw new RuntimeException('consumer failed');
            }
        } catch (RuntimeException) {
        }

        self::assertCount(1, $this->postLoadedOf(CmsUser::class));

        $this->assertTrackingIsIntact([$this->userIds[0]]);
    }

    public function testExceptionDuringRowHydrationKeepsTrackingIntact(): void
    {
        HookedObjectHydrator::$afterRow = static function (): void {
            throw new RuntimeException('row failed');
        };

        try {
            iterator_to_array($this->hookedQuery()->toIterable([], self::HOOKED_HYDRATOR));
            self::fail('Expected row hydration to fail');
        } catch (RuntimeException $e) {
            self::assertSame('row failed', $e->getMessage());
        }

        $this->assertTrackingIsIntact([$this->userIds[0]]);
    }

    public function testExceptionFromPostLoadListenerKeepsTrackingIntact(): void
    {
        $this->onPostLoad = static function (object $entity): void {
            if ($entity instanceof CmsUser) {
                throw new RuntimeException('listener failed');
            }
        };

        try {
            $this->_em->find(CmsUser::class, $this->userIds[0]);
            self::fail('Expected listener exception');
        } catch (RuntimeException $e) {
            self::assertSame('listener failed', $e->getMessage());
        }

        $this->onPostLoad = null;
        $this->postLoaded = [];

        $group = $this->_em->getRepository(CmsGroup::class)->findOneBy(['name' => 'Group 3-1']);

        self::assertSame([$group], $this->postLoaded);
    }

    public function testClearInPostLoadListenerStillFiresPostLoadOfTheOtherEntities(): void
    {
        $this->onPostLoad = function (object $entity): void {
            if ($entity instanceof CmsUser && $entity->id === $this->userIds[0]) {
                $this->_em->clear();
            }
        };

        $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u ORDER BY u.id')->getResult();

        self::assertCount(3, $this->postLoadedOf(CmsUser::class));

        $this->onPostLoad = null;

        $this->assertTrackingIsIntact($this->userIds);
    }

    public function testClearDuringHydrationKeepsTrackingIntact(): void
    {
        $rows                           = 0;
        HookedObjectHydrator::$afterRow = function () use (&$rows): void {
            $rows++;

            if ($rows === 1) {
                $this->_em->clear();
            }
        };

        $users = $this->hookedQuery()->getResult(self::HOOKED_HYDRATOR);

        self::assertCount(3, $users);
        self::assertCount(3, $this->postLoadedOf(CmsUser::class));

        $this->assertTrackingIsIntact($this->userIds);
    }

    /**
     * Travelers do not cause a nested load per row, which would trigger the eager batch load early.
     */
    private function loadTravelers(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $traveler = new Traveler('Traveler ' . $i);
            $traveler->addTravel(new Travel($traveler));
            $traveler->addTravel(new Travel($traveler));

            $this->_em->persist($traveler);
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    private function eagerTravelsQuery(): Query
    {
        return $this->_em
            ->createQuery('SELECT t FROM ' . Traveler::class . ' t ORDER BY t.id')
            ->setFetchMode(Traveler::class, 'travels', ClassMetadata::FETCH_EAGER);
    }

    private function eagerTravelsNativeQuery(): NativeQuery
    {
        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(Traveler::class, 't');

        return $this->_em
            ->createNativeQuery('SELECT ' . $rsm->generateSelectClause() . ' FROM cache_traveler t ORDER BY t.id', $rsm)
            ->setFetchMode(Traveler::class, 'travels', ClassMetadata::FETCH_EAGER);
    }

    private function eagerPhonenumbersQuery(): Query
    {
        return $this->_em
            ->createQuery('SELECT u FROM ' . CmsUser::class . ' u ORDER BY u.id')
            ->setFetchMode(CmsUser::class, 'phonenumbers', ClassMetadata::FETCH_EAGER);
    }

    private function hookedQuery(): Query
    {
        return $this->_em->createQuery('SELECT u FROM ' . CmsUser::class . ' u ORDER BY u.id');
    }

    private function assertHydrationFails(string $message): void
    {
        try {
            $this->hookedQuery()->getResult(self::HOOKED_HYDRATOR);
            self::fail('Expected hydration to fail');
        } catch (RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /**
     * Asserts that each given user received postLoad once and nothing is left over
     * for the next load, which also has to fire its own postLoad right away.
     *
     * @param list<int> $userIds
     */
    private function assertTrackingIsIntact(array $userIds): void
    {
        HookedObjectHydrator::$afterRow  = null;
        HookedObjectHydrator::$onCleanup = null;

        self::assertSame(
            $userIds,
            array_map(static fn (CmsUser $user): int => $user->id, $this->postLoadedOf(CmsUser::class)),
        );

        $this->postLoaded = [];

        $group = $this->_em->getRepository(CmsGroup::class)->findOneBy(['name' => 'Group 3-2']);

        self::assertSame([$group], $this->postLoaded);
    }

    /**
     * @param class-string<T> $className
     *
     * @return list<T>
     *
     * @template T of object
     */
    private function postLoadedOf(string $className): array
    {
        return array_values(array_filter(
            $this->postLoaded,
            static fn (object $entity): bool => $entity instanceof $className,
        ));
    }

    private function countRows(string $table): int
    {
        return (int) $this->_em->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}

class HookedObjectHydrator extends ObjectHydrator
{
    public static Closure|null $afterRow = null;

    public static Closure|null $onCleanup = null;

    /** {@inheritDoc} */
    protected function hydrateRow(array $row): void
    {
        parent::hydrateRow($row);

        if (self::$afterRow !== null) {
            (self::$afterRow)();
        }
    }

    protected function cleanup(): void
    {
        if (self::$onCleanup !== null) {
            (self::$onCleanup)();
        }

        parent::cleanup();
    }
}

class RowCompletingHydrator extends AbstractHydrator
{
    /** {@inheritDoc} */
    protected function hydrateAllData(): array
    {
        $this->hydrateAllRows();

        return $this->takeResult();
    }

    /** {@inheritDoc} */
    protected function hydrateRow(array $row): void
    {
        [$id, $name] = array_values($row);

        $this->hydrationResult[] = $this->uow->createEntity(CmsGroup::class, ['id' => $id, 'name' => $name], $this->hints);

        $this->uow->hydrationComplete();
    }
}
