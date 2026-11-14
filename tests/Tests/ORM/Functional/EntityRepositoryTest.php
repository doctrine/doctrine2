<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use BadMethodCallException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\LockMode;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Exception\UnrecognizedIdentifierFields;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Persisters\Exception\InvalidOrientation;
use Doctrine\ORM\Persisters\Exception\UnrecognizedField;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\ORM\Repository\Exception\InvalidFindByCall;
use Doctrine\ORM\Repository\Exception\InvalidMagicMethodCall;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\DDC753\DDC753CustomRepository;
use Doctrine\Tests\Models\DDC753\DDC753DefaultRepository;
use Doctrine\Tests\Models\DDC753\DDC753EntityWithCustomRepository;
use Doctrine\Tests\Models\DDC753\DDC753EntityWithDefaultCustomRepository;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use SortDirection;

use function array_values;
use function reset;

class EntityRepositoryTest extends OrmFunctionalTestCase
{
    use VerifyDeprecations;

    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();
    }

    public function tearDown(): void
    {
        if ($this->_em) {
            $this->_em->getConfiguration()->setEntityNamespaces([]);
        }

        parent::tearDown();
    }

    public function loadFixture(): int
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'freak';
        $this->_em->persist($user);

        $user2           = new CmsUser();
        $user2->name     = 'Guilherme';
        $user2->username = 'gblanco';
        $user2->status   = 'dev';
        $this->_em->persist($user2);

        $user3           = new CmsUser();
        $user3->name     = 'Benjamin';
        $user3->username = 'beberlei';
        $user3->status   = null;
        $this->_em->persist($user3);

        $user4           = new CmsUser();
        $user4->name     = 'Alexander';
        $user4->username = 'asm89';
        $user4->status   = 'dev';
        $this->_em->persist($user4);

        $this->_em->flush();

        $user1Id = $user->getId();

        unset($user, $user2, $user3, $user4);

        $this->_em->clear();

        return $user1Id;
    }

    /** @phpstan-return array{int, int} */
    public function loadAssociatedFixture(): array
    {
        $address          = new CmsAddress();
        $address->city    = 'Berlin';
        $address->country = 'Germany';
        $address->street  = 'Foostreet';
        $address->zip     = '12345';

        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'freak';
        $user->setAddress($address);

        $this->_em->persist($user);
        $this->_em->persist($address);
        $this->_em->flush();
        $this->_em->clear();

        return [$user->id, $address->id];
    }

    /** @phpstan-return list<CmsUser> */
    public function loadFixtureUserEmail(): array
    {
        $user1 = new CmsUser();
        $user2 = new CmsUser();
        $user3 = new CmsUser();

        $email1 = new CmsEmail();
        $email2 = new CmsEmail();
        $email3 = new CmsEmail();

        $user1->name     = 'Test 1';
        $user1->username = 'test1';
        $user1->status   = 'active';

        $user2->name     = 'Test 2';
        $user2->username = 'test2';
        $user2->status   = 'active';

        $user3->name     = 'Test 3';
        $user3->username = 'test3';
        $user3->status   = 'active';

        $email1->email = 'test1@test.com';
        $email2->email = 'test2@test.com';
        $email3->email = 'test3@test.com';

        $user1->setEmail($email1);
        $user2->setEmail($email2);
        $user3->setEmail($email3);

        $this->_em->persist($user1);
        $this->_em->persist($user2);
        $this->_em->persist($user3);

        $this->_em->persist($email1);
        $this->_em->persist($email2);
        $this->_em->persist($email3);

        $this->_em->flush();
        $this->_em->clear();

        return [$user1, $user2, $user3];
    }

    public function buildUser($name, $username, $status, $address): CmsUser
    {
        $user           = new CmsUser();
        $user->name     = $name;
        $user->username = $username;
        $user->status   = $status;
        $user->setAddress($address);

        $this->_em->persist($user);
        $this->_em->flush();

        return $user;
    }

    public function buildAddress($country, $city, $street, $zip): CmsAddress
    {
        $address          = new CmsAddress();
        $address->country = $country;
        $address->city    = $city;
        $address->street  = $street;
        $address->zip     = $zip;

        $this->_em->persist($address);
        $this->_em->flush();

        return $address;
    }

    public function testBasicFind(): void
    {
        $user1Id = $this->loadFixture();
        $repos   = $this->_em->getRepository(CmsUser::class);

        $user = $repos->find($user1Id);
        self::assertInstanceOf(CmsUser::class, $user);
        self::assertEquals('Roman', $user->name);
        self::assertEquals('freak', $user->status);
    }

    public function testFindByField(): void
    {
        $user1Id = $this->loadFixture();
        $repos   = $this->_em->getRepository(CmsUser::class);

        $users = $repos->findBy(['status' => 'dev']);
        self::assertCount(2, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Guilherme', $users[0]->name);
        self::assertEquals('dev', $users[0]->status);
    }

    public function testFindByAssociationWithIntegerAsParameter(): void
    {
        $address1 = $this->buildAddress('Germany', 'Berlim', 'Foo st.', '123456');
        $user1    = $this->buildUser('Benjamin', 'beberlei', 'dev', $address1);

        $address2 = $this->buildAddress('Brazil', 'São Paulo', 'Bar st.', '654321');
        $user2    = $this->buildUser('Guilherme', 'guilhermeblanco', 'freak', $address2);

        $address3 = $this->buildAddress('USA', 'Nashville', 'Woo st.', '321654');
        $user3    = $this->buildUser('Jonathan', 'jwage', 'dev', $address3);

        unset($address1, $address2, $address3);

        $this->_em->clear();

        $repository = $this->_em->getRepository(CmsAddress::class);
        $addresses  = $repository->findBy(['user' => [$user1->getId(), $user2->getId()]]);

        self::assertCount(2, $addresses);
        self::assertInstanceOf(CmsAddress::class, $addresses[0]);
    }

    public function testFindByAssociationWithObjectAsParameter(): void
    {
        $address1 = $this->buildAddress('Germany', 'Berlim', 'Foo st.', '123456');
        $user1    = $this->buildUser('Benjamin', 'beberlei', 'dev', $address1);

        $address2 = $this->buildAddress('Brazil', 'São Paulo', 'Bar st.', '654321');
        $user2    = $this->buildUser('Guilherme', 'guilhermeblanco', 'freak', $address2);

        $address3 = $this->buildAddress('USA', 'Nashville', 'Woo st.', '321654');
        $user3    = $this->buildUser('Jonathan', 'jwage', 'dev', $address3);

        unset($address1, $address2, $address3);

        $this->_em->clear();

        $repository = $this->_em->getRepository(CmsAddress::class);
        $addresses  = $repository->findBy(['user' => [$user1, $user2]]);

        self::assertCount(2, $addresses);
        self::assertInstanceOf(CmsAddress::class, $addresses[0]);
    }

    public function testFindFieldByMagicCall(): void
    {
        $user1Id = $this->loadFixture();
        $repos   = $this->_em->getRepository(CmsUser::class);

        $users = $repos->findByStatus('dev');
        self::assertCount(2, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Guilherme', $users[0]->name);
        self::assertEquals('dev', $users[0]->status);
    }

    public function testFindAll(): void
    {
        $user1Id = $this->loadFixture();
        $repos   = $this->_em->getRepository(CmsUser::class);

        $users = $repos->findAll();
        self::assertCount(4, $users);
    }

    public function testCount(): void
    {
        $this->loadFixture();
        $repos = $this->_em->getRepository(CmsUser::class);

        $userCount = $repos->count([]);
        self::assertSame(4, $userCount);

        $userCount = $repos->count(['status' => 'dev']);
        self::assertSame(2, $userCount);

        $userCount = $repos->count(['status' => 'nonexistent']);
        self::assertSame(0, $userCount);
    }

    public function testCountBy(): void
    {
        $this->loadFixture();
        $repos = $this->_em->getRepository(CmsUser::class);

        $userCount = $repos->countByStatus('dev');
        self::assertSame(2, $userCount);
    }

    public function testExceptionIsThrownWhenCallingFindByWithoutParameter(): void
    {
        $this->expectException(InvalidMagicMethodCall::class);
        $this->_em->getRepository(CmsUser::class)
                  ->findByStatus();
    }

    public function testExceptionIsThrownWhenUsingInvalidFieldName(): void
    {
        $this->expectException(InvalidMagicMethodCall::class);
        $this->_em->getRepository(CmsUser::class)
                  ->findByThisFieldDoesNotExist('testvalue');
    }

    #[Group('locking')]
    #[Group('DDC-178')]
    public function testPessimisticReadLockWithoutTransactionThrowsException(): void
    {
        $this->expectException(TransactionRequiredException::class);

        $this->_em->getRepository(CmsUser::class)
                  ->find(1, LockMode::PESSIMISTIC_READ);
    }

    #[Group('locking')]
    #[Group('DDC-178')]
    public function testPessimisticWriteLockWithoutTransactionThrowsException(): void
    {
        $this->expectException(TransactionRequiredException::class);

        $this->_em->getRepository(CmsUser::class)
                  ->find(1, LockMode::PESSIMISTIC_WRITE);
    }

    #[Group('locking')]
    #[Group('DDC-178')]
    public function testOptimisticLockUnversionedEntityThrowsException(): void
    {
        $this->expectException(OptimisticLockException::class);

        $this->_em->getRepository(CmsUser::class)
                  ->find(1, LockMode::OPTIMISTIC);
    }

    #[Group('locking')]
    #[Group('DDC-178')]
    public function testIdentityMappedOptimisticLockUnversionedEntityThrowsException(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'freak';
        $this->_em->persist($user);
        $this->_em->flush();

        $userId = $user->id;

        $this->_em->find(CmsUser::class, $userId);

        $this->expectException(OptimisticLockException::class);

        $this->_em->find(CmsUser::class, $userId, LockMode::OPTIMISTIC);
    }

    #[Group('DDC-819')]
    public function testFindMagicCallByNullValue(): void
    {
        $this->loadFixture();

        $repos = $this->_em->getRepository(CmsUser::class);

        $users = $repos->findByStatus(null);
        self::assertCount(1, $users);
    }

    #[Group('DDC-819')]
    public function testInvalidMagicCall(): void
    {
        $this->expectException(BadMethodCallException::class);

        $repos = $this->_em->getRepository(CmsUser::class);
        $repos->foo();
    }

    #[Group('DDC-817')]
    public function testFindByAssociationKeyExceptionOnInverseSide(): void
    {
        [$userId, $addressId] = $this->loadAssociatedFixture();
        $repos                = $this->_em->getRepository(CmsUser::class);

        $this->expectException(InvalidFindByCall::class);
        $this->expectExceptionMessage("You cannot search for the association field 'Doctrine\Tests\Models\CMS\CmsUser#address', because it is the inverse side of an association. Find methods only work on owning side associations.");

        $user = $repos->findBy(['address' => $addressId]);
    }

    #[Group('DDC-817')]
    public function testFindOneByAssociationKey(): void
    {
        [$userId, $addressId] = $this->loadAssociatedFixture();
        $repos                = $this->_em->getRepository(CmsAddress::class);
        $address              = $repos->findOneBy(['user' => $userId]);

        self::assertInstanceOf(CmsAddress::class, $address);
        self::assertEquals($addressId, $address->id);
    }

    #[Group('DDC-1241')]
    public function testFindOneByOrderBy(): void
    {
        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/11313');
        $this->loadFixture();

        $repos    = $this->_em->getRepository(CmsUser::class);
        $userAsc  = $repos->findOneBy([], ['username' => 'ASC']);
        $userDesc = $repos->findOneBy([], ['username' => 'DESC']);

        self::assertNotSame($userAsc, $userDesc);
    }

    public function testFindOneByOrderBySortDirection(): void
    {
        $this->loadFixture();

        $repos    = $this->_em->getRepository(CmsUser::class);
        $userAsc  = $repos->findOneBy([], ['username' => SortDirection::Ascending]);
        $userDesc = $repos->findOneBy([], ['username' => SortDirection::Descending]);

        self::assertNotSame($userAsc, $userDesc);
    }

    #[Group('DDC-817')]
    public function testFindByAssociationKey(): void
    {
        [$userId, $addressId] = $this->loadAssociatedFixture();
        $repos                = $this->_em->getRepository(CmsAddress::class);
        $addresses            = $repos->findBy(['user' => $userId]);

        self::assertContainsOnly(CmsAddress::class, $addresses);
        self::assertCount(1, $addresses);
        self::assertEquals($addressId, $addresses[0]->id);
    }

    #[Group('DDC-817')]
    public function testFindAssociationByMagicCall(): void
    {
        [$userId, $addressId] = $this->loadAssociatedFixture();
        $repos                = $this->_em->getRepository(CmsAddress::class);
        $addresses            = $repos->findByUser($userId);

        self::assertContainsOnly(CmsAddress::class, $addresses);
        self::assertCount(1, $addresses);
        self::assertEquals($addressId, $addresses[0]->id);
    }

    #[Group('DDC-817')]
    public function testFindOneAssociationByMagicCall(): void
    {
        [$userId, $addressId] = $this->loadAssociatedFixture();
        $repos                = $this->_em->getRepository(CmsAddress::class);
        $address              = $repos->findOneByUser($userId);

        self::assertInstanceOf(CmsAddress::class, $address);
        self::assertEquals($addressId, $address->id);
    }

    #[Group('DDC-1087')]
    public function testIsNullCriteriaDoesNotGenerateAParameter(): void
    {
        $repos = $this->_em->getRepository(CmsUser::class);
        $users = $repos->findBy(['status' => null, 'username' => 'romanb']);

        $params = $this->getLastLoggedQuery()['params'];
        self::assertCount(1, $params, 'Should only execute with one parameter.');
        self::assertEquals(['romanb'], array_values($params));
    }

    public function testIsNullCriteria(): void
    {
        $this->loadFixture();

        $repos = $this->_em->getRepository(CmsUser::class);

        $users = $repos->findBy(['status' => null]);
        self::assertCount(1, $users);
    }

    #[Group('DDC-1094')]
    public function testFindByLimitOffset(): void
    {
        $this->loadFixture();

        $repos = $this->_em->getRepository(CmsUser::class);

        $users1 = $repos->findBy([], null, 1, 0);
        $users2 = $repos->findBy([], null, 1, 1);

        self::assertCount(4, $repos->findBy([]));
        self::assertCount(1, $users1);
        self::assertCount(1, $users2);
        self::assertNotSame($users1[0], $users2[0]);
    }

    #[Group('DDC-1094')]
    public function testFindByOrderBy(): void
    {
        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/11313');
        $this->loadFixture();

        $repos     = $this->_em->getRepository(CmsUser::class);
        $usersAsc  = $repos->findBy([], ['username' => 'ASC']);
        $usersDesc = $repos->findBy([], ['username' => 'DESC']);

        self::assertCount(4, $usersAsc, 'Pre-condition: only four users in fixture');
        self::assertCount(4, $usersDesc, 'Pre-condition: only four users in fixture');
        self::assertSame($usersAsc[0], $usersDesc[3]);
        self::assertSame($usersAsc[3], $usersDesc[0]);
    }

    public function testFindByOrderBySortDirection(): void
    {
        $this->loadFixture();

        $repos     = $this->_em->getRepository(CmsUser::class);
        $usersAsc  = $repos->findBy([], ['username' => SortDirection::Ascending]);
        $usersDesc = $repos->findBy([], ['username' => SortDirection::Descending]);

        self::assertCount(4, $usersAsc, 'Pre-condition: only four users in fixture');
        self::assertCount(4, $usersDesc, 'Pre-condition: only four users in fixture');
        self::assertSame($usersAsc[0], $usersDesc[3]);
        self::assertSame($usersAsc[3], $usersDesc[0]);
    }

    #[Group('DDC-1376')]
    public function testFindByOrderByAssociation(): void
    {
        $this->loadFixtureUserEmail();

        $repository = $this->_em->getRepository(CmsUser::class);
        $resultAsc  = $repository->findBy([], ['email' => 'ASC']);
        $resultDesc = $repository->findBy([], ['email' => 'DESC']);

        self::assertCount(3, $resultAsc);
        self::assertCount(3, $resultDesc);

        self::assertEquals($resultAsc[0]->getEmail()->getId(), $resultDesc[2]->getEmail()->getId());
        self::assertEquals($resultAsc[2]->getEmail()->getId(), $resultDesc[0]->getEmail()->getId());
    }

    #[Group('DDC-1426')]
    public function testFindFieldByMagicCallOrderBy(): void
    {
        $this->loadFixture();
        $repos = $this->_em->getRepository(CmsUser::class);

        $usersAsc  = $repos->findByStatus('dev', ['username' => 'ASC']);
        $usersDesc = $repos->findByStatus('dev', ['username' => 'DESC']);

        self::assertCount(2, $usersAsc);
        self::assertCount(2, $usersDesc);

        self::assertInstanceOf(CmsUser::class, $usersAsc[0]);
        self::assertEquals('Alexander', $usersAsc[0]->name);
        self::assertEquals('dev', $usersAsc[0]->status);

        self::assertSame($usersAsc[0], $usersDesc[1]);
        self::assertSame($usersAsc[1], $usersDesc[0]);
    }

    #[Group('DDC-1426')]
    public function testFindFieldByMagicCallLimitOffset(): void
    {
        $this->loadFixture();
        $repos = $this->_em->getRepository(CmsUser::class);

        $users1 = $repos->findByStatus('dev', [], 1, 0);
        $users2 = $repos->findByStatus('dev', [], 1, 1);

        self::assertCount(1, $users1);
        self::assertCount(1, $users2);
        self::assertNotSame($users1[0], $users2[0]);
    }

    #[Group('DDC-753')]
    public function testDefaultRepositoryClassName(): void
    {
        self::assertEquals($this->_em->getConfiguration()->getDefaultRepositoryClassName(), EntityRepository::class);
        $this->_em->getConfiguration()->setDefaultRepositoryClassName(DDC753DefaultRepository::class);
        self::assertEquals($this->_em->getConfiguration()->getDefaultRepositoryClassName(), DDC753DefaultRepository::class);

        $repos = $this->_em->getRepository(DDC753EntityWithDefaultCustomRepository::class);
        self::assertInstanceOf(DDC753DefaultRepository::class, $repos);
        self::assertTrue($repos->isDefaultRepository());

        $repos = $this->_em->getRepository(DDC753EntityWithCustomRepository::class);
        self::assertInstanceOf(DDC753CustomRepository::class, $repos);
        self::assertTrue($repos->isCustomRepository());

        self::assertEquals($this->_em->getConfiguration()->getDefaultRepositoryClassName(), DDC753DefaultRepository::class);
        $this->_em->getConfiguration()->setDefaultRepositoryClassName(EntityRepository::class);
        self::assertEquals($this->_em->getConfiguration()->getDefaultRepositoryClassName(), EntityRepository::class);
    }

    #[Group('DDC-3257')]
    public function testCanRetrieveRepositoryFromClassNameWithLeadingBackslash(): void
    {
        self::assertSame(
            $this->_em->getRepository('\\' . CmsUser::class),
            $this->_em->getRepository(CmsUser::class),
        );
    }

    #[Group('DDC-1376')]
    public function testInvalidOrderByAssociation(): void
    {
        $this->expectException(InvalidFindByCall::class);
        $this->expectExceptionMessage('You cannot search for the association field \'Doctrine\Tests\Models\CMS\CmsUser#address\', because it is the inverse side of an association.');
        $this->_em->getRepository(CmsUser::class)
            ->findBy(['status' => 'test'], ['address' => 'ASC']);
    }

    #[Group('DDC-1500')]
    public function testInvalidOrientation(): void
    {
        $this->expectException(InvalidOrientation::class);
        $this->expectExceptionMessage('Invalid order by orientation specified for Doctrine\Tests\Models\CMS\CmsUser#username');

        $repo = $this->_em->getRepository(CmsUser::class);
        $repo->findBy(['status' => 'test'], ['username' => 'INVALID']);
    }

    #[Group('DDC-1713')]
    public function testFindByAssociationArray(): void
    {
        $address1          = new CmsAddress();
        $address1->country = 'Germany';
        $address1->zip     = '12345';
        $address1->city    = 'Berlin';
        $user1             = new CmsUser();
        $user1->username   = 'nifnif';
        $user1->name       = 'Nif-Nif';
        $user1->setAddress($address1);

        $address2          = new CmsAddress();
        $address2->country = 'France';
        $address2->zip     = '54321';
        $address2->city    = 'Paris';
        $user2             = new CmsUser();
        $user2->username   = 'noufnouf';
        $user2->name       = 'Nouf-Nouf';
        $user2->setAddress($address2);

        $address3          = new CmsAddress();
        $address3->country = 'Spain';
        $address3->zip     = '98765';
        $address3->city    = 'Madrid';
        $user3             = new CmsUser();
        $user3->username   = 'nafnaf';
        $user3->name       = 'Naf-Naf';
        $user3->setAddress($address3);

        $address4          = new CmsAddress();
        $address4->country = 'United Kingdom';
        $address4->zip     = '32145';
        $address4->city    = 'London';
        $user4             = new CmsUser();
        $user4->username   = 'wolf';
        $user4->name       = 'Wolf';
        $user4->setAddress($address4);

        $this->_em->persist($user1);
        $this->_em->persist($user2);
        $this->_em->persist($user3);
        $this->_em->persist($user4);
        $this->_em->flush();

        $ids = [$user1->id, $user2->id, $user3->id];
        $this->_em->clear();

        $repo      = $this->_em->getRepository(CmsAddress::class);
        $addresses = $repo->findBy(['user' => $ids]);

        self::assertCount(3, $addresses);
        foreach ($addresses as $address) {
            self::assertContains($address->zip, ['12345', '54321', '98765']);
        }
    }

    #[Group('DDC-1637')]
    public function testMatchingEmptyCriteria(): void
    {
        $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);
        $users      = $repository->matching(Criteria::create());

        self::assertCount(4, $users);
    }

    #[Group('DDC-1637')]
    public function testMatchingCriteriaEqComparison(): void
    {
        $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('username', 'beberlei'),
        ));

        self::assertCount(1, $users);
    }

    #[Group('DDC-1637')]
    public function testMatchingCriteriaNeqComparison(): void
    {
        $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->neq('username', 'beberlei'),
        ));

        self::assertCount(3, $users);
    }

    #[Group('DDC-1637')]
    public function testMatchingCriteriaInComparison(): void
    {
        $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->in('username', ['beberlei', 'gblanco']),
        ));

        self::assertCount(2, $users);
    }

    #[Group('DDC-1637')]
    public function testMatchingCriteriaNotInComparison(): void
    {
        $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->notIn('username', ['beberlei', 'gblanco', 'asm89']),
        ));

        self::assertCount(1, $users);
    }

    #[Group('DDC-1637')]
    public function testMatchingCriteriaLtComparison(): void
    {
        $firstUserId = $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->lt('id', $firstUserId + 1),
        ));

        self::assertCount(1, $users);
    }

    #[Group('DDC-1637')]
    public function testMatchingCriteriaLeComparison(): void
    {
        $firstUserId = $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->lte('id', $firstUserId + 1),
        ));

        self::assertCount(2, $users);
    }

    #[Group('DDC-1637')]
    public function testMatchingCriteriaGtComparison(): void
    {
        $firstUserId = $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->gt('id', $firstUserId),
        ));

        self::assertCount(3, $users);
    }

    #[Group('DDC-1637')]
    public function testMatchingCriteriaGteComparison(): void
    {
        $firstUserId = $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->gte('id', $firstUserId),
        ));

        self::assertCount(4, $users);
    }

    #[Group('DDC-2430')]
    public function testMatchingCriteriaAssocationByObjectInMemory(): void
    {
        [$userId, $addressId] = $this->loadAssociatedFixture();

        $user = $this->_em->find(CmsUser::class, $userId);

        $criteria = Criteria::create()->where(
            Criteria::expr()->eq('user', $user),
        );

        $repository = $this->_em->getRepository(CmsAddress::class);
        $addresses  = $repository->matching($criteria);

        self::assertCount(1, $addresses);

        $addresses = new ArrayCollection($repository->findAll());

        self::assertCount(1, $addresses->matching($criteria));
    }

    #[Group('DDC-2430')]
    public function testMatchingCriteriaAssocationInWithArray(): void
    {
        [$userId, $addressId] = $this->loadAssociatedFixture();

        $user = $this->_em->find(CmsUser::class, $userId);

        $criteria = Criteria::create()->where(
            Criteria::expr()->in('user', [$user]),
        );

        $repository = $this->_em->getRepository(CmsAddress::class);
        $addresses  = $repository->matching($criteria);

        self::assertCount(1, $addresses);

        $addresses = new ArrayCollection($repository->findAll());

        self::assertCount(1, $addresses->matching($criteria));
    }

    public function testMatchingCriteriaContainsComparison(): void
    {
        $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);

        $users = $repository->matching(Criteria::create()->where(Criteria::expr()->contains('name', 'Foobar')));
        self::assertCount(0, $users);

        $users = $repository->matching(Criteria::create()->where(Criteria::expr()->contains('name', 'Rom')));
        self::assertCount(1, $users);

        $users = $repository->matching(Criteria::create()->where(Criteria::expr()->contains('status', 'dev')));
        self::assertCount(2, $users);
    }

    public function testMatchingCriteriaStartsWithComparison(): void
    {
        $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);

        $users = $repository->matching(Criteria::create()->where(
            Criteria::expr()->startsWith('name', 'Foo'),
        ));
        self::assertCount(0, $users);

        $users = $repository->matching(Criteria::create()->where(
            Criteria::expr()->startsWith('name', 'R'),
        ));
        self::assertCount(1, $users);

        $users = $repository->matching(Criteria::create()->where(
            Criteria::expr()->startsWith('status', 'de'),
        ));
        self::assertCount(2, $users);
    }

    public function testMatchingCriteriaEndsWithComparison(): void
    {
        $this->loadFixture();

        $repository = $this->_em->getRepository(CmsUser::class);

        $users = $repository->matching(Criteria::create()->where(
            Criteria::expr()->endsWith('name', 'foo'),
        ));
        self::assertCount(0, $users);

        $users = $repository->matching(Criteria::create()->where(
            Criteria::expr()->endsWith('name', 'oman'),
        ));
        self::assertCount(1, $users);

        $users = $repository->matching(Criteria::create()->where(
            Criteria::expr()->endsWith('status', 'ev'),
        ));
        self::assertCount(2, $users);
    }

    #[Group('DDC-2478')]
    public function testMatchingCriteriaNullAssocComparison(): void
    {
        $fixtures       = $this->loadFixtureUserEmail();
        $user           = $this->_em->find(CmsUser::class, $fixtures[0]->id);
        $repository     = $this->_em->getRepository(CmsUser::class);
        $criteriaIsNull = Criteria::create()->where(Criteria::expr()->isNull('email'));
        $criteriaEqNull = Criteria::create()->where(Criteria::expr()->eq('email', null));

        $user->setEmail(null);
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $usersIsNull = $repository->matching($criteriaIsNull);
        $usersEqNull = $repository->matching($criteriaEqNull);

        self::assertCount(1, $usersIsNull);
        self::assertCount(1, $usersEqNull);

        self::assertInstanceOf(CmsUser::class, $usersIsNull[0]);
        self::assertInstanceOf(CmsUser::class, $usersEqNull[0]);

        self::assertNull($usersIsNull[0]->getEmail());
        self::assertNull($usersEqNull[0]->getEmail());
    }

    #[Group('DDC-2055')]
    public function testCreateResultSetMappingBuilder(): void
    {
        $repository = $this->_em->getRepository(CmsUser::class);
        $rsm        = $repository->createResultSetMappingBuilder('u');

        self::assertInstanceOf(ResultSetMappingBuilder::class, $rsm);
        self::assertEquals(['u' => CmsUser::class], $rsm->aliasMap);
    }

    #[Group('DDC-3045')]
    public function testFindByFieldInjectionPrevented(): void
    {
        $this->expectException(UnrecognizedField::class);
        $this->expectExceptionMessage('Unrecognized field: ');

        $repository = $this->_em->getRepository(CmsUser::class);
        $repository->findBy(['username = ?; DELETE FROM cms_users; SELECT 1 WHERE 1' => 'test']);
    }

    #[Group('DDC-3045')]
    public function testFindOneByFieldInjectionPrevented(): void
    {
        $this->expectException(ORMException::class);
        $this->expectExceptionMessage('Unrecognized field: ');

        $repository = $this->_em->getRepository(CmsUser::class);
        $repository->findOneBy(['username = ?; DELETE FROM cms_users; SELECT 1 WHERE 1' => 'test']);
    }

    #[Group('DDC-3045')]
    public function testMatchingInjectionPrevented(): void
    {
        $this->expectException(UnrecognizedField::class);
        $this->expectExceptionMessage('Unrecognized field: ');

        $repository = $this->_em->getRepository(CmsUser::class);
        $result     = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('username = ?; DELETE FROM cms_users; SELECT 1 WHERE 1', 'beberlei'),
        ));

        // Because repository returns a lazy collection, we call toArray to force initialization
        $result->toArray();
    }

    #[Group('DDC-3045')]
    public function testFindInjectionPrevented(): void
    {
        $this->expectException(UnrecognizedIdentifierFields::class);
        $this->expectExceptionMessage('Unrecognized identifier fields: ');

        $repository = $this->_em->getRepository(CmsUser::class);
        $repository->find(['username = ?; DELETE FROM cms_users; SELECT 1 WHERE 1' => 'test', 'id' => 1]);
    }

    #[Group('DDC-3056')]
    public function testFindByNullValueInInCondition(): void
    {
        $user1 = new CmsUser();
        $user2 = new CmsUser();

        $user1->username = 'ocramius';
        $user1->name     = 'Marco';
        $user2->status   = null;
        $user2->username = 'deeky666';
        $user2->name     = 'Steve';
        $user2->status   = 'dbal maintainer';

        $this->_em->persist($user1);
        $this->_em->persist($user2);
        $this->_em->flush();

        $users = $this->_em->getRepository(CmsUser::class)->findBy(['status' => [null]]);

        self::assertCount(1, $users);
        self::assertSame($user1, reset($users));
    }

    #[Group('DDC-3056')]
    public function testFindByNullValueInMultipleInCriteriaValues(): void
    {
        $user1 = new CmsUser();
        $user2 = new CmsUser();

        $user1->username = 'ocramius';
        $user1->name     = 'Marco';
        $user2->status   = null;
        $user2->username = 'deeky666';
        $user2->name     = 'Steve';
        $user2->status   = 'dbal maintainer';

        $this->_em->persist($user1);
        $this->_em->persist($user2);
        $this->_em->flush();

        $users = $this
            ->_em
            ->getRepository(CmsUser::class)
            ->findBy(['status' => ['foo', null]]);

        self::assertCount(1, $users);
        self::assertSame($user1, reset($users));
    }

    #[Group('DDC-3056')]
    public function testFindMultipleByNullValueInMultipleInCriteriaValues(): void
    {
        $user1 = new CmsUser();
        $user2 = new CmsUser();

        $user1->username = 'ocramius';
        $user1->name     = 'Marco';
        $user2->status   = null;
        $user2->username = 'deeky666';
        $user2->name     = 'Steve';
        $user2->status   = 'dbal maintainer';

        $this->_em->persist($user1);
        $this->_em->persist($user2);
        $this->_em->flush();

        $users = $this
            ->_em
            ->getRepository(CmsUser::class)
            ->findBy(['status' => ['dbal maintainer', null]]);

        self::assertCount(2, $users);

        foreach ($users as $user) {
            self::assertContains($user, [$user1, $user2]);
        }
    }
}
