<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\UnrecognizedIdentifierFields;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Persisters\Exception\InvalidOrientation;
use Doctrine\ORM\Persisters\Exception\UnrecognizedField;
use Doctrine\ORM\Query;
use Doctrine\ORM\Repository\Exception\InvalidFindByCall;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\DDC753\DDC753CustomRepository;
use Doctrine\Tests\Models\DDC753\DDC753DefaultRepository;
use Doctrine\Tests\Models\DDC753\DDC753EntityWithCustomRepository;
use Doctrine\Tests\Models\DDC753\DDC753EntityWithDefaultCustomRepository;
use Doctrine\Tests\Models\DDC753\DDC753InvalidRepository;
use Doctrine\Tests\OrmFunctionalTestCase;
use function array_pop;
use function get_class;
use function reset;

class EntityRepositoryTest extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        $this->useModelSet('cms');
        parent::setUp();
    }

    public function loadFixture()
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'freak';
        $this->em->persist($user);

        $user2           = new CmsUser();
        $user2->name     = 'Guilherme';
        $user2->username = 'gblanco';
        $user2->status   = 'dev';
        $this->em->persist($user2);

        $user3           = new CmsUser();
        $user3->name     = 'Benjamin';
        $user3->username = 'beberlei';
        $user3->status   = null;
        $this->em->persist($user3);

        $user4           = new CmsUser();
        $user4->name     = 'Alexander';
        $user4->username = 'asm89';
        $user4->status   = 'dev';
        $this->em->persist($user4);

        $this->em->flush();

        $user1Id = $user->getId();

        unset($user, $user2, $user3, $user4);

        $this->em->clear();

        return $user1Id;
    }

    public function loadAssociatedFixture()
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

        $this->em->persist($user);
        $this->em->persist($address);
        $this->em->flush();
        $this->em->clear();

        return [$user->id, $address->id];
    }

    public function loadFixtureUserEmail()
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

        $this->em->persist($user1);
        $this->em->persist($user2);
        $this->em->persist($user3);

        $this->em->persist($email1);
        $this->em->persist($email2);
        $this->em->persist($email3);

        $this->em->flush();
        $this->em->clear();

        return [$user1, $user2, $user3];
    }

    public function buildUser($name, $username, $status, $address)
    {
        $user           = new CmsUser();
        $user->name     = $name;
        $user->username = $username;
        $user->status   = $status;
        $user->setAddress($address);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function buildAddress($country, $city, $street, $zip)
    {
        $address          = new CmsAddress();
        $address->country = $country;
        $address->city    = $city;
        $address->street  = $street;
        $address->zip     = $zip;

        $this->em->persist($address);
        $this->em->flush();

        return $address;
    }

    public function testBasicFind() : void
    {
        $user1Id = $this->loadFixture();
        $repos   = $this->em->getRepository(CmsUser::class);

        $user = $repos->find($user1Id);
        self::assertInstanceOf(CmsUser::class, $user);
        self::assertEquals('Roman', $user->name);
        self::assertEquals('freak', $user->status);
    }

    public function testFindByField() : void
    {
        $user1Id = $this->loadFixture();
        $repos   = $this->em->getRepository(CmsUser::class);

        $users = $repos->findBy(['status' => 'dev']);
        self::assertCount(2, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Guilherme', $users[0]->name);
        self::assertEquals('dev', $users[0]->status);
    }

    public function testFindByAssociationWithIntegerAsParameter() : void
    {
        $address1 = $this->buildAddress('Germany', 'Berlim', 'Foo st.', '123456');
        $user1    = $this->buildUser('Benjamin', 'beberlei', 'dev', $address1);

        $address2 = $this->buildAddress('Brazil', 'São Paulo', 'Bar st.', '654321');
        $user2    = $this->buildUser('Guilherme', 'guilhermeblanco', 'freak', $address2);

        $address3 = $this->buildAddress('USA', 'Nashville', 'Woo st.', '321654');
        $user3    = $this->buildUser('Jonathan', 'jwage', 'dev', $address3);

        unset($address1, $address2, $address3);

        $this->em->clear();

        $repository = $this->em->getRepository(CmsAddress::class);
        $addresses  = $repository->findBy(['user' => [$user1->getId(), $user2->getId()]]);

        self::assertCount(2, $addresses);
        self::assertInstanceOf(CmsAddress::class, $addresses[0]);
    }

    public function testFindByAssociationWithObjectAsParameter() : void
    {
        $address1 = $this->buildAddress('Germany', 'Berlim', 'Foo st.', '123456');
        $user1    = $this->buildUser('Benjamin', 'beberlei', 'dev', $address1);

        $address2 = $this->buildAddress('Brazil', 'São Paulo', 'Bar st.', '654321');
        $user2    = $this->buildUser('Guilherme', 'guilhermeblanco', 'freak', $address2);

        $address3 = $this->buildAddress('USA', 'Nashville', 'Woo st.', '321654');
        $user3    = $this->buildUser('Jonathan', 'jwage', 'dev', $address3);

        unset($address1, $address2, $address3);

        $this->em->clear();

        $repository = $this->em->getRepository(CmsAddress::class);
        $addresses  = $repository->findBy(['user' => [$user1, $user2]]);

        self::assertCount(2, $addresses);
        self::assertInstanceOf(CmsAddress::class, $addresses[0]);
    }

    public function testFindFieldByMagicCall() : void
    {
        $user1Id = $this->loadFixture();
        $repos   = $this->em->getRepository(CmsUser::class);

        $users = $repos->findByStatus('dev');
        self::assertCount(2, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Guilherme', $users[0]->name);
        self::assertEquals('dev', $users[0]->status);
    }

    public function testFindAll() : void
    {
        $user1Id = $this->loadFixture();
        $repos   = $this->em->getRepository(CmsUser::class);

        $users = $repos->findAll();
        self::assertCount(4, $users);
    }

    public function testCount() : void
    {
        $this->loadFixture();
        $repos = $this->em->getRepository(CmsUser::class);

        $userCount = $repos->count([]);
        self::assertSame(4, $userCount);

        $userCount = $repos->count(['status' => 'dev']);
        self::assertSame(2, $userCount);

        $userCount = $repos->count(['status' => 'nonexistent']);
        self::assertSame(0, $userCount);
    }

    public function testCountBy() : void
    {
        $this->loadFixture();
        $repos = $this->em->getRepository(CmsUser::class);

        $userCount = $repos->countByStatus('dev');
        self::assertSame(2, $userCount);
    }

    /**
     * @expectedException Doctrine\ORM\Repository\Exception\InvalidMagicMethodCall
     */
    public function testExceptionIsThrownWhenCallingFindByWithoutParameter() : void
    {
        $this->em->getRepository(CmsUser::class)
                  ->findByStatus();
    }

    /**
     * @expectedException Doctrine\ORM\Repository\Exception\InvalidMagicMethodCall
     */
    public function testExceptionIsThrownWhenUsingInvalidFieldName() : void
    {
        $this->em->getRepository(CmsUser::class)
                  ->findByThisFieldDoesNotExist('testvalue');
    }

    /**
     * @group locking
     * @group DDC-178
     */
    public function testPessimisticReadLockWithoutTransactionThrowsException() : void
    {
        $this->expectException(TransactionRequiredException::class);

        $this->em->getRepository(CmsUser::class)
                  ->find(1, LockMode::PESSIMISTIC_READ);
    }

    /**
     * @group locking
     * @group DDC-178
     */
    public function testPessimisticWriteLockWithoutTransactionThrowsException() : void
    {
        $this->expectException(TransactionRequiredException::class);

        $this->em->getRepository(CmsUser::class)
                  ->find(1, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * @group locking
     * @group DDC-178
     */
    public function testOptimisticLockUnversionedEntityThrowsException() : void
    {
        $this->expectException(OptimisticLockException::class);

        $this->em->getRepository(CmsUser::class)
                  ->find(1, LockMode::OPTIMISTIC);
    }

    /**
     * @group locking
     * @group DDC-178
     */
    public function testIdentityMappedOptimisticLockUnversionedEntityThrowsException() : void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'freak';
        $this->em->persist($user);
        $this->em->flush();

        $userId = $user->id;

        $this->em->find(CmsUser::class, $userId);

        $this->expectException(OptimisticLockException::class);

        $this->em->find(CmsUser::class, $userId, LockMode::OPTIMISTIC);
    }

    /**
     * @group DDC-819
     */
    public function testFindMagicCallByNullValue() : void
    {
        $this->loadFixture();

        $repos = $this->em->getRepository(CmsUser::class);

        $users = $repos->findByStatus(null);
        self::assertCount(1, $users);
    }

    /**
     * @group DDC-819
     */
    public function testInvalidMagicCall() : void
    {
        $this->expectException(\BadMethodCallException::class);

        $repos = $this->em->getRepository(CmsUser::class);
        $repos->foo();
    }

    /**
     * @group DDC-817
     */
    public function testFindByAssociationKeyExceptionOnInverseSide() : void
    {
        list($userId, $addressId) = $this->loadAssociatedFixture();
        $repos                    = $this->em->getRepository(CmsUser::class);

        $this->expectException(InvalidFindByCall::class);
        $this->expectExceptionMessage("You cannot search for the association field 'Doctrine\Tests\Models\CMS\CmsUser#address', because it is the inverse side of an association. Find methods only work on owning side associations.");

        $user = $repos->findBy(['address' => $addressId]);
    }

    /**
     * @group DDC-817
     */
    public function testFindOneByAssociationKey() : void
    {
        list($userId, $addressId) = $this->loadAssociatedFixture();
        $repos                    = $this->em->getRepository(CmsAddress::class);
        $address                  = $repos->findOneBy(['user' => $userId]);

        self::assertInstanceOf(CmsAddress::class, $address);
        self::assertEquals($addressId, $address->id);
    }

    /**
     * @group DDC-1241
     */
    public function testFindOneByOrderBy() : void
    {
        $this->loadFixture();

        $repos    = $this->em->getRepository(CmsUser::class);
        $userAsc  = $repos->findOneBy([], ['username' => 'ASC']);
        $userDesc = $repos->findOneBy([], ['username' => 'DESC']);

        self::assertNotSame($userAsc, $userDesc);
    }

    /**
     * @group DDC-817
     */
    public function testFindByAssociationKey() : void
    {
        list($userId, $addressId) = $this->loadAssociatedFixture();
        $repos                    = $this->em->getRepository(CmsAddress::class);
        $addresses                = $repos->findBy(['user' => $userId]);

        self::assertContainsOnly(CmsAddress::class, $addresses);
        self::assertCount(1, $addresses);
        self::assertEquals($addressId, $addresses[0]->id);
    }

    /**
     * @group DDC-817
     */
    public function testFindAssociationByMagicCall() : void
    {
        list($userId, $addressId) = $this->loadAssociatedFixture();
        $repos                    = $this->em->getRepository(CmsAddress::class);
        $addresses                = $repos->findByUser($userId);

        self::assertContainsOnly(CmsAddress::class, $addresses);
        self::assertCount(1, $addresses);
        self::assertEquals($addressId, $addresses[0]->id);
    }

    /**
     * @group DDC-817
     */
    public function testFindOneAssociationByMagicCall() : void
    {
        list($userId, $addressId) = $this->loadAssociatedFixture();
        $repos                    = $this->em->getRepository(CmsAddress::class);
        $address                  = $repos->findOneByUser($userId);

        self::assertInstanceOf(CmsAddress::class, $address);
        self::assertEquals($addressId, $address->id);
    }

    /**
     * @group DDC-1087
     */
    public function testIsNullCriteriaDoesNotGenerateAParameter() : void
    {
        $repos = $this->em->getRepository(CmsUser::class);
        $users = $repos->findBy(['status' => null, 'username' => 'romanb']);

        $params = $this->sqlLoggerStack->queries[$this->sqlLoggerStack->currentQuery]['params'];
        self::assertCount(1, $params, 'Should only execute with one parameter.');
        self::assertEquals(['romanb'], $params);
    }

    public function testIsNullCriteria() : void
    {
        $this->loadFixture();

        $repos = $this->em->getRepository(CmsUser::class);

        $users = $repos->findBy(['status' => null]);
        self::assertCount(1, $users);
    }

    /**
     * @group DDC-1094
     */
    public function testFindByLimitOffset() : void
    {
        $this->loadFixture();

        $repos = $this->em->getRepository(CmsUser::class);

        $users1 = $repos->findBy([], null, 1, 0);
        $users2 = $repos->findBy([], null, 1, 1);

        self::assertCount(4, $repos->findBy([]));
        self::assertCount(1, $users1);
        self::assertCount(1, $users2);
        self::assertNotSame($users1[0], $users2[0]);
    }

    /**
     * @group DDC-1094
     */
    public function testFindByOrderBy() : void
    {
        $this->loadFixture();

        $repos     = $this->em->getRepository(CmsUser::class);
        $usersAsc  = $repos->findBy([], ['username' => 'ASC']);
        $usersDesc = $repos->findBy([], ['username' => 'DESC']);

        self::assertCount(4, $usersAsc, 'Pre-condition: only four users in fixture');
        self::assertCount(4, $usersDesc, 'Pre-condition: only four users in fixture');
        self::assertSame($usersAsc[0], $usersDesc[3]);
        self::assertSame($usersAsc[3], $usersDesc[0]);
    }

    /**
     * @group DDC-1376
     */
    public function testFindByOrderByAssociation() : void
    {
        $this->loadFixtureUserEmail();

        $repository = $this->em->getRepository(CmsUser::class);
        $resultAsc  = $repository->findBy([], ['email' => 'ASC']);
        $resultDesc = $repository->findBy([], ['email' => 'DESC']);

        self::assertCount(3, $resultAsc);
        self::assertCount(3, $resultDesc);

        self::assertEquals($resultAsc[0]->getEmail()->getId(), $resultDesc[2]->getEmail()->getId());
        self::assertEquals($resultAsc[2]->getEmail()->getId(), $resultDesc[0]->getEmail()->getId());
    }

    /**
     * @group DDC-1426
     */
    public function testFindFieldByMagicCallOrderBy() : void
    {
        $this->loadFixture();
        $repos = $this->em->getRepository(CmsUser::class);

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

    /**
     * @group DDC-1426
     */
    public function testFindFieldByMagicCallLimitOffset() : void
    {
        $this->loadFixture();
        $repos = $this->em->getRepository(CmsUser::class);

        $users1 = $repos->findByStatus('dev', [], 1, 0);
        $users2 = $repos->findByStatus('dev', [], 1, 1);

        self::assertCount(1, $users1);
        self::assertCount(1, $users2);
        self::assertNotSame($users1[0], $users2[0]);
    }

    /**
     * @group DDC-753
     */
    public function testDefaultRepositoryClassName() : void
    {
        self::assertEquals($this->em->getConfiguration()->getDefaultRepositoryClassName(), EntityRepository::class);
        $this->em->getConfiguration()->setDefaultRepositoryClassName(DDC753DefaultRepository::class);
        self::assertEquals($this->em->getConfiguration()->getDefaultRepositoryClassName(), DDC753DefaultRepository::class);

        $repos = $this->em->getRepository(DDC753EntityWithDefaultCustomRepository::class);
        self::assertInstanceOf(DDC753DefaultRepository::class, $repos);
        self::assertTrue($repos->isDefaultRepository());

        $repos = $this->em->getRepository(DDC753EntityWithCustomRepository::class);
        self::assertInstanceOf(DDC753CustomRepository::class, $repos);
        self::assertTrue($repos->isCustomRepository());

        self::assertEquals($this->em->getConfiguration()->getDefaultRepositoryClassName(), DDC753DefaultRepository::class);
        $this->em->getConfiguration()->setDefaultRepositoryClassName(EntityRepository::class);
        self::assertEquals($this->em->getConfiguration()->getDefaultRepositoryClassName(), EntityRepository::class);
    }

    /**
     * @group DDC-753
     * @expectedException Doctrine\ORM\Exception\InvalidEntityRepository
     * @expectedExceptionMessage Invalid repository class 'Doctrine\Tests\Models\DDC753\DDC753InvalidRepository'. It must be a Doctrine\Common\Persistence\ObjectRepository.
     */
    public function testSetDefaultRepositoryInvalidClassError() : void
    {
        self::assertEquals($this->em->getConfiguration()->getDefaultRepositoryClassName(), EntityRepository::class);
        $this->em->getConfiguration()->setDefaultRepositoryClassName(DDC753InvalidRepository::class);
    }

    /**
     * @group DDC-3257
     */
    public function testCanRetrieveRepositoryFromClassNameWithLeadingBackslash() : void
    {
        self::assertSame(
            $this->em->getRepository('\\' . CmsUser::class),
            $this->em->getRepository(CmsUser::class)
        );
    }

    /**
     * @group DDC-1376
     *
     * @expectedException Doctrine\ORM\Repository\Exception\InvalidFindByCall
     * @expectedExceptionMessage You cannot search for the association field 'Doctrine\Tests\Models\CMS\CmsUser#address', because it is the inverse side of an association.
     */
    public function testInvalidOrderByAssociation() : void
    {
        $this->em->getRepository(CmsUser::class)
            ->findBy(['status' => 'test'], ['address' => 'ASC']);
    }

    /**
     * @group DDC-1500
     */
    public function testInvalidOrientation() : void
    {
        $this->expectException(InvalidOrientation::class);
        $this->expectExceptionMessage('Invalid order by orientation specified for Doctrine\Tests\Models\CMS\CmsUser#username');

        $repo = $this->em->getRepository(CmsUser::class);
        $repo->findBy(['status' => 'test'], ['username' => 'INVALID']);
    }

    /**
     * @group DDC-1713
     */
    public function testFindByAssociationArray() : void
    {
        $repo = $this->em->getRepository(CmsAddress::class);
        $data = $repo->findBy(['user' => [1, 2, 3]]);

        $query = array_pop($this->sqlLoggerStack->queries);
        self::assertEquals([1, 2, 3], $query['params'][0]);
        self::assertEquals(Connection::PARAM_INT_ARRAY, $query['types'][0]);
    }

    /**
     * @group DDC-1637
     */
    public function testMatchingEmptyCriteria() : void
    {
        $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);
        $users      = $repository->matching(new Criteria());

        self::assertCount(4, $users);
    }

    /**
     * @group DDC-1637
     */
    public function testMatchingCriteriaEqComparison() : void
    {
        $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);
        $users      = $repository->matching(new Criteria(
            Criteria::expr()->eq('username', 'beberlei')
        ));

        self::assertCount(1, $users);
    }

    /**
     * @group DDC-1637
     */
    public function testMatchingCriteriaNeqComparison() : void
    {
        $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);
        $users      = $repository->matching(new Criteria(
            Criteria::expr()->neq('username', 'beberlei')
        ));

        self::assertCount(3, $users);
    }

    /**
     * @group DDC-1637
     */
    public function testMatchingCriteriaInComparison() : void
    {
        $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);
        $users      = $repository->matching(new Criteria(
            Criteria::expr()->in('username', ['beberlei', 'gblanco'])
        ));

        self::assertCount(2, $users);
    }

    /**
     * @group DDC-1637
     */
    public function testMatchingCriteriaNotInComparison() : void
    {
        $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);
        $users      = $repository->matching(new Criteria(
            Criteria::expr()->notIn('username', ['beberlei', 'gblanco', 'asm89'])
        ));

        self::assertCount(1, $users);
    }

    /**
     * @group DDC-1637
     */
    public function testMatchingCriteriaLtComparison() : void
    {
        $firstUserId = $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);
        $users      = $repository->matching(new Criteria(
            Criteria::expr()->lt('id', $firstUserId + 1)
        ));

        self::assertCount(1, $users);
    }

    /**
     * @group DDC-1637
     */
    public function testMatchingCriteriaLeComparison() : void
    {
        $firstUserId = $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);
        $users      = $repository->matching(new Criteria(
            Criteria::expr()->lte('id', $firstUserId + 1)
        ));

        self::assertCount(2, $users);
    }

    /**
     * @group DDC-1637
     */
    public function testMatchingCriteriaGtComparison() : void
    {
        $firstUserId = $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);
        $users      = $repository->matching(new Criteria(
            Criteria::expr()->gt('id', $firstUserId)
        ));

        self::assertCount(3, $users);
    }

    /**
     * @group DDC-1637
     */
    public function testMatchingCriteriaGteComparison() : void
    {
        $firstUserId = $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);
        $users      = $repository->matching(new Criteria(
            Criteria::expr()->gte('id', $firstUserId)
        ));

        self::assertCount(4, $users);
    }

    /**
     * @group DDC-2430
     */
    public function testMatchingCriteriaAssocationByObjectInMemory() : void
    {
        list($userId, $addressId) = $this->loadAssociatedFixture();

        $user = $this->em->find(CmsUser::class, $userId);

        $criteria = new Criteria(
            Criteria::expr()->eq('user', $user)
        );

        $repository = $this->em->getRepository(CmsAddress::class);
        $addresses  = $repository->matching($criteria);

        self::assertCount(1, $addresses);

        $addresses = new ArrayCollection($repository->findAll());

        self::assertCount(1, $addresses->matching($criteria));
    }

    /**
     * @group DDC-2430
     */
    public function testMatchingCriteriaAssocationInWithArray() : void
    {
        list($userId, $addressId) = $this->loadAssociatedFixture();

        $user = $this->em->find(CmsUser::class, $userId);

        $criteria = new Criteria(
            Criteria::expr()->in('user', [$user])
        );

        $repository = $this->em->getRepository(CmsAddress::class);
        $addresses  = $repository->matching($criteria);

        self::assertCount(1, $addresses);

        $addresses = new ArrayCollection($repository->findAll());

        self::assertCount(1, $addresses->matching($criteria));
    }

    public function testMatchingCriteriaContainsComparison() : void
    {
        $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);

        $users = $repository->matching(new Criteria(Criteria::expr()->contains('name', 'Foobar')));
        self::assertCount(0, $users);

        $users = $repository->matching(new Criteria(Criteria::expr()->contains('name', 'Rom')));
        self::assertCount(1, $users);

        $users = $repository->matching(new Criteria(Criteria::expr()->contains('status', 'dev')));
        self::assertCount(2, $users);
    }

    public function testMatchingCriteriaStartsWithComparison() : void
    {
        $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);

        $users = $repository->matching(new Criteria(Criteria::expr()->startsWith('name', 'Foo')));
        self::assertCount(0, $users);

        $users = $repository->matching(new Criteria(Criteria::expr()->startsWith('name', 'R')));
        self::assertCount(1, $users);

        $users = $repository->matching(new Criteria(Criteria::expr()->startsWith('status', 'de')));
        self::assertCount(2, $users);
    }

    public function testMatchingCriteriaEndsWithComparison() : void
    {
        $this->loadFixture();

        $repository = $this->em->getRepository(CmsUser::class);

        $users = $repository->matching(new Criteria(Criteria::expr()->endsWith('name', 'foo')));
        self::assertCount(0, $users);

        $users = $repository->matching(new Criteria(Criteria::expr()->endsWith('name', 'oman')));
        self::assertCount(1, $users);

        $users = $repository->matching(new Criteria(Criteria::expr()->endsWith('status', 'ev')));
        self::assertCount(2, $users);
    }

    /**
     * @group DDC-2478
     */
    public function testMatchingCriteriaNullAssocComparison() : void
    {
        $fixtures       = $this->loadFixtureUserEmail();
        $user           = $this->em->find(get_class($fixtures[0]), $fixtures[0]->id);
        $repository     = $this->em->getRepository(CmsUser::class);
        $criteriaIsNull = Criteria::create()->where(Criteria::expr()->isNull('email'));
        $criteriaEqNull = Criteria::create()->where(Criteria::expr()->eq('email', null));

        $user->setEmail(null);
        $this->em->flush();
        $this->em->clear();

        $usersIsNull = $repository->matching($criteriaIsNull);
        $usersEqNull = $repository->matching($criteriaEqNull);

        self::assertCount(1, $usersIsNull);
        self::assertCount(1, $usersEqNull);

        self::assertInstanceOf(CmsUser::class, $usersIsNull[0]);
        self::assertInstanceOf(CmsUser::class, $usersEqNull[0]);

        self::assertNull($usersIsNull[0]->getEmail());
        self::assertNull($usersEqNull[0]->getEmail());
    }

    /**
     * @group DDC-2055
     */
    public function testCreateResultSetMappingBuilder() : void
    {
        $repository = $this->em->getRepository(CmsUser::class);
        $rsm        = $repository->createResultSetMappingBuilder('u');

        self::assertInstanceOf(Query\ResultSetMappingBuilder::class, $rsm);
        self::assertEquals(['u' => CmsUser::class], $rsm->aliasMap);
    }

    /**
     * @group DDC-3045
     */
    public function testFindByFieldInjectionPrevented() : void
    {
        $this->expectException(UnrecognizedField::class);
        $this->expectExceptionMessage('Unrecognized field: ');

        $repository = $this->em->getRepository(CmsUser::class);
        $repository->findBy(['username = ?; DELETE FROM cms_users; SELECT 1 WHERE 1' => 'test']);
    }

    /**
     * @group DDC-3045
     */
    public function testFindOneByFieldInjectionPrevented() : void
    {
        $this->expectException(ORMException::class);
        $this->expectExceptionMessage('Unrecognized field: ');

        $repository = $this->em->getRepository(CmsUser::class);
        $repository->findOneBy(['username = ?; DELETE FROM cms_users; SELECT 1 WHERE 1' => 'test']);
    }

    /**
     * @group DDC-3045
     */
    public function testMatchingInjectionPrevented() : void
    {
        $this->expectException(UnrecognizedField::class);
        $this->expectExceptionMessage('Unrecognized field: ');

        $repository = $this->em->getRepository(CmsUser::class);
        $result     = $repository->matching(new Criteria(
            Criteria::expr()->eq('username = ?; DELETE FROM cms_users; SELECT 1 WHERE 1', 'beberlei')
        ));

        // Because repository returns a lazy collection, we call toArray to force initialization
        $result->toArray();
    }

    /**
     * @group DDC-3045
     */
    public function testFindInjectionPrevented() : void
    {
        $this->expectException(UnrecognizedIdentifierFields::class);
        $this->expectExceptionMessage('Unrecognized identifier fields: ');

        $repository = $this->em->getRepository(CmsUser::class);
        $repository->find(['username = ?; DELETE FROM cms_users; SELECT 1 WHERE 1' => 'test', 'id' => 1]);
    }

    /**
     * @group DDC-3056
     */
    public function testFindByNullValueInInCondition() : void
    {
        $user1 = new CmsUser();
        $user2 = new CmsUser();

        $user1->username = 'ocramius';
        $user1->name     = 'Marco';
        $user2->status   = null;
        $user2->username = 'deeky666';
        $user2->name     = 'Steve';
        $user2->status   = 'dbal maintainer';

        $this->em->persist($user1);
        $this->em->persist($user2);
        $this->em->flush();

        $users = $this->em->getRepository(CmsUser::class)->findBy(['status' => [null]]);

        self::assertCount(1, $users);
        self::assertSame($user1, reset($users));
    }

    /**
     * @group DDC-3056
     */
    public function testFindByNullValueInMultipleInCriteriaValues() : void
    {
        $user1 = new CmsUser();
        $user2 = new CmsUser();

        $user1->username = 'ocramius';
        $user1->name     = 'Marco';
        $user2->status   = null;
        $user2->username = 'deeky666';
        $user2->name     = 'Steve';
        $user2->status   = 'dbal maintainer';

        $this->em->persist($user1);
        $this->em->persist($user2);
        $this->em->flush();

        $users = $this
            ->em
            ->getRepository(CmsUser::class)
            ->findBy(['status' => ['foo', null]]);

        self::assertCount(1, $users);
        self::assertSame($user1, reset($users));
    }

    /**
     * @group DDC-3056
     */
    public function testFindMultipleByNullValueInMultipleInCriteriaValues() : void
    {
        $user1 = new CmsUser();
        $user2 = new CmsUser();

        $user1->username = 'ocramius';
        $user1->name     = 'Marco';
        $user2->status   = null;
        $user2->username = 'deeky666';
        $user2->name     = 'Steve';
        $user2->status   = 'dbal maintainer';

        $this->em->persist($user1);
        $this->em->persist($user2);
        $this->em->flush();

        $users = $this
            ->em
            ->getRepository(CmsUser::class)
            ->findBy(['status' => ['dbal maintainer', null]]);

        self::assertCount(2, $users);

        foreach ($users as $user) {
            self::assertContains($user, [$user1, $user2]);
        }
    }
}
