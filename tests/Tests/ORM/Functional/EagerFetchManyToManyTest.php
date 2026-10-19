<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\ECommerce\ECommerceProduct;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function array_filter;
use function array_map;
use function sort;
use function str_contains;
use function usort;

#[Group('non-cacheable')]
class EagerFetchManyToManyTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('cms');
        $this->useModelSet('ecommerce');

        parent::setUp();
    }

    /** @return iterable<string, array{int, int}> */
    public static function batchSizes(): iterable
    {
        yield 'single batch' => [100, 1];
        yield 'in chunks' => [2, 2];
    }

    #[DataProvider('batchSizes')]
    public function testBatchFetchOwningSide(int $batchSize, int $expectedQueries): void
    {
        $this->createFixture();
        $this->_em->getConfiguration()->setEagerFetchBatchSize($batchSize);

        $query = $this->_em->createQuery('SELECT u, a FROM ' . CmsUser::class . ' u LEFT JOIN u.address a ORDER BY u.id');
        $query->setFetchMode(CmsUser::class, 'groups', ClassMetadata::FETCH_EAGER);

        $this->getQueryLog()->reset()->enable();
        $users = $query->getResult();

        $this->assertJoinTableQueryCount($expectedQueries);
        self::assertCount(3, $users);
        $this->assertCollectionsLoaded($users, 'groups');

        self::assertSame(['admins', 'editors'], self::groupNames($users[0]->groups));
        self::assertSame(['admins', 'editors'], self::groupNames($users[1]->groups));
        self::assertCount(0, $users[2]->groups);

        foreach ($users[0]->groups as $group) {
            self::assertTrue($users[1]->groups->contains($group));
        }

        $this->assertJoinTableQueryCount($expectedQueries);

        $this->getQueryLog()->reset()->enable();
        $this->_em->flush();
        $this->assertQueryCount(0);
    }

    #[DataProvider('batchSizes')]
    public function testBatchFetchInverseSide(int $batchSize, int $expectedQueries): void
    {
        $this->createFixture();
        $this->_em->getConfiguration()->setEagerFetchBatchSize($batchSize);

        $query = $this->_em->createQuery('SELECT g FROM ' . CmsGroup::class . ' g ORDER BY g.id');
        $query->setFetchMode(CmsGroup::class, 'users', ClassMetadata::FETCH_EAGER);

        $this->getQueryLog()->reset()->enable();
        $groups = $query->getResult();

        $this->assertJoinTableQueryCount($expectedQueries);
        self::assertCount(3, $groups);
        $this->assertCollectionsLoaded($groups, 'users');

        self::assertSame(['alice', 'bob'], self::usernames($groups[0]->users));
        self::assertSame(['alice', 'bob'], self::usernames($groups[1]->users));
        self::assertCount(0, $groups[2]->users);

        foreach ($groups[0]->users as $user) {
            self::assertTrue($groups[1]->users->contains($user));
        }

        $this->getQueryLog()->reset()->enable();
        $this->_em->flush();
        $this->assertQueryCount(0);
    }

    public function testMappingFetchMode(): void
    {
        $this->createFixture();
        $this->_em->getClassMetadata(CmsUser::class)->associationMappings['groups']->fetch = ClassMetadata::FETCH_EAGER;

        $this->getQueryLog()->reset()->enable();
        $users = $this->_em->getRepository(CmsUser::class)->findBy([], ['id' => 'ASC']);

        $this->assertJoinTableQueryCount(1);
        self::assertCount(2, $users[0]->groups);
        self::assertCount(2, $users[1]->groups);
        self::assertCount(0, $users[2]->groups);
        $this->assertJoinTableQueryCount(1);
    }

    public function testPagination(): void
    {
        $this->createFixture();
        $query = $this->_em->createQuery('SELECT u, a FROM ' . CmsUser::class . ' u LEFT JOIN u.address a ORDER BY u.id');
        $query->setFetchMode(CmsUser::class, 'groups', ClassMetadata::FETCH_EAGER);
        $query->setMaxResults(1);

        $this->getQueryLog()->reset()->enable();
        $users = $query->getResult();

        self::assertCount(1, $users);
        self::assertSame(['admins', 'editors'], self::groupNames($users[0]->groups));
        $this->assertJoinTableQueryCount(1);
    }

    public function testChangesAfterBatchFetchArePersisted(): void
    {
        $this->createFixture();
        $query = $this->_em->createQuery('SELECT u, a FROM ' . CmsUser::class . ' u LEFT JOIN u.address a ORDER BY u.id');
        $query->setFetchMode(CmsUser::class, 'groups', ClassMetadata::FETCH_EAGER);
        $users = $query->getResult();

        $editors = $users[0]->groups->filter(static fn (CmsGroup $group) => $group->name === 'editors')->first();
        $users[0]->groups->removeElement($editors);
        $users[2]->groups->add($editors);
        $this->_em->flush();
        $this->_em->clear();

        $users = $query->getResult();
        self::assertSame(['admins'], self::groupNames($users[0]->groups));
        self::assertSame(['admins', 'editors'], self::groupNames($users[1]->groups));
        self::assertSame(['editors'], self::groupNames($users[2]->groups));
    }

    public function testBothSidesEager(): void
    {
        $this->createFixture();
        $this->_em->getClassMetadata(CmsUser::class)->associationMappings['groups']->fetch = ClassMetadata::FETCH_EAGER;
        $this->_em->getClassMetadata(CmsGroup::class)->associationMappings['users']->fetch = ClassMetadata::FETCH_EAGER;

        $this->getQueryLog()->reset()->enable();
        $users = $this->_em->getRepository(CmsUser::class)->findBy([], ['id' => 'ASC']);

        self::assertCount(2, $users[0]->groups);
        self::assertCount(2, $users[1]->groups);
        self::assertCount(0, $users[2]->groups);

        foreach ($users[0]->groups as $group) {
            self::assertInstanceOf(PersistentCollection::class, $group->users);
            self::assertTrue($group->users->isInitialized());
            self::assertCount(2, $group->users);
            self::assertTrue($group->users->contains($users[0]));
            self::assertTrue($group->users->contains($users[1]));
        }

        $this->assertJoinTableQueryCount(2);
    }

    public function testSelfReferentialUnidirectionalAssociation(): void
    {
        $products = [];
        foreach (['first', 'second', 'third'] as $name) {
            $products[] = $product = new ECommerceProduct();
            $product->setName($name);
            $this->_em->persist($product);
        }

        $products[0]->addRelated($products[1]);
        $products[1]->addRelated($products[2]);
        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('SELECT p FROM ' . ECommerceProduct::class . ' p ORDER BY p.id');
        $query->setFetchMode(ECommerceProduct::class, 'related', ClassMetadata::FETCH_EAGER);
        $this->getQueryLog()->reset()->enable();
        $products = $query->getResult();

        self::assertSame([$products[1]], $products[0]->getRelated()->toArray());
        self::assertSame([$products[0], $products[2]], self::sortedById($products[1]->getRelated()));
        self::assertSame([$products[1]], $products[2]->getRelated()->toArray());
        $this->assertJoinTableQueryCount(1, 'ecommerce_products_related');
    }

    public function testTargetFilter(): void
    {
        $this->createFixture();
        $this->_em->getConfiguration()->addFilter('no_editors', CmsGroupNameFilter::class);
        $this->_em->getFilters()->enable('no_editors');
        $query = $this->_em->createQuery('SELECT u, a FROM ' . CmsUser::class . ' u LEFT JOIN u.address a ORDER BY u.id');
        $query->setFetchMode(CmsUser::class, 'groups', ClassMetadata::FETCH_EAGER);

        $this->getQueryLog()->reset()->enable();
        $users = $query->getResult();

        self::assertSame(['admins'], self::groupNames($users[0]->groups));
        self::assertSame(['admins'], self::groupNames($users[1]->groups));
        self::assertCount(0, $users[2]->groups);
        $this->assertJoinTableQueryCount(1);
    }

    public function testIndexedAssociationUsesPerOwnerLoading(): void
    {
        $this->createFixture();
        $mapping = $this->_em->getClassMetadata(CmsUser::class)->associationMappings['groups'];
        self::assertInstanceOf(ManyToManyOwningSideMapping::class, $mapping);
        $mapping->indexBy = 'name';
        $mapping->fetch   = ClassMetadata::FETCH_EAGER;

        $this->getQueryLog()->reset()->enable();
        $users = $this->_em->getRepository(CmsUser::class)->findBy([], ['id' => 'ASC']);

        self::assertSame(['admins', 'editors'], self::groupNames($users[0]->groups));
        self::assertSame(['admins', 'editors'], self::groupNames($users[1]->groups));
        self::assertArrayHasKey('admins', $users[0]->groups->toArray());
        self::assertArrayHasKey('editors', $users[1]->groups->toArray());
        self::assertCount(0, $users[2]->groups);
        $this->assertJoinTableQueryCount(3);
    }

    public function testIterationUsesPerOwnerLoading(): void
    {
        $this->createFixture();
        $query = $this->_em->createQuery('SELECT u, a FROM ' . CmsUser::class . ' u LEFT JOIN u.address a ORDER BY u.id');
        $query->setFetchMode(CmsUser::class, 'groups', ClassMetadata::FETCH_EAGER);

        $this->getQueryLog()->reset()->enable();
        $counts = [];
        foreach ($query->toIterable() as $user) {
            self::assertTrue($user->groups->isInitialized());
            $counts[] = $user->groups->count();
        }

        self::assertSame([2, 2, 0], $counts);
        $this->assertJoinTableQueryCount(3);
    }

    /**
     * Inverse one-to-one associations of CmsUser add queries per user,
     * so only the queries that read the join table are counted.
     */
    private function assertJoinTableQueryCount(int $expectedCount, string $joinTable = 'cms_users_groups'): void
    {
        $queries = array_filter(
            $this->getQueryLog()->queries,
            static fn (array $query) => str_contains($query['sql'], $joinTable),
        );

        self::assertCount($expectedCount, $queries);
    }

    /** @param list<object> $entities */
    private function assertCollectionsLoaded(array $entities, string $field): void
    {
        foreach ($entities as $entity) {
            $collection = $entity->$field;
            self::assertInstanceOf(PersistentCollection::class, $collection);
            self::assertTrue($collection->isInitialized());
            self::assertFalse($collection->isDirty());
            self::assertSame($collection->toArray(), $collection->getSnapshot());
        }
    }

    /**
     * @param Collection<array-key, CmsGroup> $groups
     *
     * @return list<string>
     */
    private static function groupNames(Collection $groups): array
    {
        $names = array_map(static fn (CmsGroup $group) => $group->name, $groups->toArray());
        sort($names);

        return $names;
    }

    /**
     * @param Collection<array-key, CmsUser> $users
     *
     * @return list<string>
     */
    private static function usernames(Collection $users): array
    {
        $names = array_map(static fn (CmsUser $user) => $user->username, $users->toArray());
        sort($names);

        return $names;
    }

    /**
     * @param Collection<int, ECommerceProduct> $collection
     *
     * @return list<ECommerceProduct>
     */
    private static function sortedById(Collection $collection): array
    {
        $products = $collection->toArray();
        usort($products, static fn (ECommerceProduct $a, ECommerceProduct $b) => $a->getId() <=> $b->getId());

        return $products;
    }

    private function createFixture(): void
    {
        $admins = new CmsGroup();
        $admins->setName('admins');
        $editors = new CmsGroup();
        $editors->setName('editors');
        $empty = new CmsGroup();
        $empty->setName('empty');

        foreach (['alice' => [$admins, $editors], 'bob' => [$admins, $editors], 'carol' => []] as $username => $groups) {
            $user           = new CmsUser();
            $user->username = $username;
            $user->name     = $username;
            $user->status   = 'active';

            foreach ($groups as $group) {
                $user->addGroup($group);
            }

            $this->_em->persist($user);
        }

        $this->_em->persist($admins);
        $this->_em->persist($editors);
        $this->_em->persist($empty);
        $this->_em->flush();
        $this->_em->clear();
    }
}

class CmsGroupNameFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return $targetEntity->name === CmsGroup::class
            ? $targetTableAlias . ".name <> 'editors'"
            : '';
    }
}
