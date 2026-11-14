<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use DateTime;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Tests\IterableTester;
use Doctrine\Tests\Models\Company\CompanyAuction;
use Doctrine\Tests\Models\Company\CompanyEmployee;
use Doctrine\Tests\Models\Company\CompanyEvent;
use Doctrine\Tests\Models\Company\CompanyManager;
use Doctrine\Tests\Models\Company\CompanyOrganization;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\Models\Company\CompanyRaffle;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Group;

use function get_debug_type;
use function sprintf;

/**
 * Functional tests for the Class Table Inheritance mapping strategy.
 */
class ClassTableInheritanceTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('company');

        parent::setUp();
    }

    public function testCRUD(): void
    {
        $person = new CompanyPerson();
        $person->setName('Roman S. Borschel');

        $this->_em->persist($person);

        $employee = new CompanyEmployee();
        $employee->setName('Roman S. Borschel');
        $employee->setSalary(100000);
        $employee->setDepartment('IT');

        $this->_em->persist($employee);

        $employee->setName('Guilherme Blanco');
        $this->_em->flush();

        $this->_em->clear();

        $query = $this->_em->createQuery('select p from ' . CompanyPerson::class . ' p order by p.name desc');

        $entities = $query->getResult();

        self::assertCount(2, $entities);
        self::assertInstanceOf(CompanyPerson::class, $entities[0]);
        self::assertInstanceOf(CompanyEmployee::class, $entities[1]);
        self::assertIsNumeric($entities[0]->getId());
        self::assertIsNumeric($entities[1]->getId());
        self::assertEquals('Roman S. Borschel', $entities[0]->getName());
        self::assertEquals('Guilherme Blanco', $entities[1]->getName());
        self::assertEquals(100000, $entities[1]->getSalary());

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);

        $this->_em->clear();

        $query = $this->_em->createQuery('select p from ' . CompanyEmployee::class . ' p');

        $entities = $query->getResult();

        self::assertCount(1, $entities);
        self::assertInstanceOf(CompanyEmployee::class, $entities[0]);
        self::assertIsNumeric($entities[0]->getId());
        self::assertEquals('Guilherme Blanco', $entities[0]->getName());
        self::assertEquals(100000, $entities[0]->getSalary());

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);

        $this->_em->clear();

        $guilherme = $this->_em->getRepository($employee::class)->findOneBy(['name' => 'Guilherme Blanco']);
        self::assertInstanceOf(CompanyEmployee::class, $guilherme);
        self::assertEquals('Guilherme Blanco', $guilherme->getName());

        $this->_em->clear();

        $query = $this->_em->createQuery('update ' . CompanyEmployee::class . " p set p.name = ?1, p.department = ?2 where p.name='Guilherme Blanco' and p.salary = ?3");
        $query->setParameter(1, 'NewName', 'string');
        $query->setParameter(2, 'NewDepartment');
        $query->setParameter(3, 100000);
        $query->getSQL();
        $numUpdated = $query->execute();
        self::assertEquals(1, $numUpdated);

        $query      = $this->_em->createQuery('delete from ' . CompanyPerson::class . ' p');
        $numDeleted = $query->execute();
        self::assertEquals(2, $numDeleted);
    }

    public function testMultiLevelUpdateAndFind(): void
    {
        $manager = new CompanyManager();
        $manager->setName('Roman S. Borschel');
        $manager->setSalary(100000);
        $manager->setDepartment('IT');
        $manager->setTitle('CTO');
        $this->_em->persist($manager);
        $this->_em->flush();

        $manager->setName('Roman B.');
        $manager->setSalary(119000);
        $manager->setTitle('CEO');
        $this->_em->persist($manager);
        $this->_em->flush();

        $this->_em->clear();

        $manager = $this->_em->find(CompanyManager::class, $manager->getId());

        self::assertInstanceOf(CompanyManager::class, $manager);
        self::assertEquals('Roman B.', $manager->getName());
        self::assertEquals(119000, $manager->getSalary());
        self::assertEquals('CEO', $manager->getTitle());
        self::assertIsNumeric($manager->getId());
    }

    public function testFindOnBaseClass(): void
    {
        $manager = new CompanyManager();
        $manager->setName('Roman S. Borschel');
        $manager->setSalary(100000);
        $manager->setDepartment('IT');
        $manager->setTitle('CTO');
        $this->_em->persist($manager);
        $this->_em->flush();

        $this->_em->clear();

        $person = $this->_em->find(CompanyPerson::class, $manager->getId());

        self::assertInstanceOf(CompanyManager::class, $person);
        self::assertEquals('Roman S. Borschel', $person->getName());
        self::assertEquals(100000, $person->getSalary());
        self::assertEquals('CTO', $person->getTitle());
        self::assertIsNumeric($person->getId());
    }

    public function testSelfReferencingOneToOne(): void
    {
        $manager = new CompanyManager();
        $manager->setName('John Smith');
        $manager->setSalary(100000);
        $manager->setDepartment('IT');
        $manager->setTitle('CTO');

        $wife = new CompanyPerson();
        $wife->setName('Mary Smith');
        $wife->setSpouse($manager);

        self::assertSame($manager, $wife->getSpouse());
        self::assertSame($wife, $manager->getSpouse());

        $this->_em->persist($manager);
        $this->_em->persist($wife);
        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('select p, s from ' . CompanyPerson::class . ' p join p.spouse s where p.name=\'Mary Smith\'');

        $result = $query->getResult();
        self::assertCount(1, $result);
        self::assertInstanceOf(CompanyPerson::class, $result[0]);
        self::assertEquals('Mary Smith', $result[0]->getName());
        self::assertInstanceOf(CompanyEmployee::class, $result[0]->getSpouse());
        self::assertEquals('John Smith', $result[0]->getSpouse()->getName());
        self::assertSame($result[0], $result[0]->getSpouse()->getSpouse());

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);
    }

    public function testSelfReferencingManyToMany(): void
    {
        $person1 = new CompanyPerson();
        $person1->setName('Roman');

        $person2 = new CompanyPerson();
        $person2->setName('Jonathan');

        $person1->addFriend($person2);

        self::assertCount(1, $person1->getFriends());
        self::assertCount(1, $person2->getFriends());

        $this->_em->persist($person1);
        $this->_em->persist($person2);

        $this->_em->flush();

        $this->_em->clear();

        $query = $this->_em->createQuery('select p, f from ' . CompanyPerson::class . ' p join p.friends f where p.name=?1');
        $query->setParameter(1, 'Roman');

        $result = $query->getResult();
        self::assertCount(1, $result);
        self::assertCount(1, $result[0]->getFriends());
        self::assertEquals('Roman', $result[0]->getName());

        $friends = $result[0]->getFriends();
        self::assertEquals('Jonathan', $friends[0]->getName());
    }

    public function testLazyLoading1(): void
    {
        $org    = new CompanyOrganization();
        $event1 = new CompanyAuction();
        $event1->setData('auction');
        $org->addEvent($event1);
        $event2 = new CompanyRaffle();
        $event2->setData('raffle');
        $org->addEvent($event2);

        $this->_em->persist($org);
        $this->_em->flush();
        $this->_em->clear();

        $orgId = $org->getId();

        $q = $this->_em->createQuery('select a from Doctrine\Tests\Models\Company\CompanyOrganization a where a.id = ?1');
        $q->setParameter(1, $orgId);

        $result = $q->getResult();

        self::assertCount(1, $result);
        self::assertInstanceOf(CompanyOrganization::class, $result[0]);
        self::assertNull($result[0]->getMainEvent());

        $events = $result[0]->getEvents();

        self::assertInstanceOf(PersistentCollection::class, $events);
        self::assertFalse($events->isInitialized());

        self::assertCount(2, $events);
        if ($events[0] instanceof CompanyAuction) {
            self::assertInstanceOf(CompanyRaffle::class, $events[1]);
        } else {
            self::assertInstanceOf(CompanyRaffle::class, $events[0]);
            self::assertInstanceOf(CompanyAuction::class, $events[1]);
        }

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($q);
    }

    public function testLazyLoading2(): void
    {
        $org    = new CompanyOrganization();
        $event1 = new CompanyAuction();
        $event1->setData('auction');
        $org->setMainEvent($event1);

        $this->_em->persist($org);
        $this->_em->flush();
        $this->_em->clear();

        $q = $this->_em->createQuery('select a from ' . CompanyEvent::class . ' a where a.id = ?1');
        $q->setParameter(1, $event1->getId());

        $result = $q->getResult();
        self::assertCount(1, $result);
        self::assertInstanceOf(CompanyAuction::class, $result[0], sprintf('Is of class %s', get_debug_type($result[0])));

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($q);

        $this->_em->clear();

        $q = $this->_em->createQuery('select a from ' . CompanyOrganization::class . ' a where a.id = ?1');
        $q->setParameter(1, $org->getId());

        $result = $q->getResult();

        self::assertCount(1, $result);
        self::assertInstanceOf(CompanyOrganization::class, $result[0]);

        $mainEvent = $result[0]->getMainEvent();
        // mainEvent should have been loaded because it can't be lazy
        self::assertInstanceOf(CompanyAuction::class, $mainEvent);
        self::assertFalse($this->isUninitializedObject($mainEvent));

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($q);
    }

    #[Group('DDC-368')]
    public function testBulkUpdateIssueDDC368(): void
    {
        $this->_em->createQuery('UPDATE ' . CompanyEmployee::class . ' AS p SET p.salary = 1')
                  ->execute();

        $query  = $this->_em->createQuery('SELECT count(p.id) FROM ' . CompanyEmployee::class . ' p WHERE p.salary = 1');
        $result = $query->getResult();

        self::assertGreaterThan(0, $result);

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);
    }

    #[Group('DDC-1341')]
    #[DoesNotPerformAssertions]
    public function testBulkUpdateNonScalarParameterDDC1341(): void
    {
        $this->_em->createQuery('UPDATE ' . CompanyEmployee::class . ' AS p SET p.startDate = ?0 WHERE p.department = ?1')
                  ->setParameter(0, new DateTime())
                  ->setParameter(1, 'IT')
                  ->execute();
    }

    #[Group('DDC-130')]
    public function testDeleteJoinTableRecords(): void
    {
        $employee1 = new CompanyEmployee();
        $employee1->setName('gblanco');
        $employee1->setSalary(0);
        $employee1->setDepartment('IT');

        $employee2 = new CompanyEmployee();
        $employee2->setName('jwage');
        $employee2->setSalary(0);
        $employee2->setDepartment('IT');

        $employee1->addFriend($employee2);

        $this->_em->persist($employee1);
        $this->_em->persist($employee2);
        $this->_em->flush();

        $employee1Id = $employee1->getId();

        $this->_em->remove($employee1);
        $this->_em->flush();

        self::assertNull($this->_em->find($employee1::class, $employee1Id));
    }

    #[Group('DDC-728')]
    public function testQueryForInheritedSingleValuedAssociation(): void
    {
        $manager = new CompanyManager();
        $manager->setName('gblanco');
        $manager->setSalary(1234);
        $manager->setTitle('Awesome!');
        $manager->setDepartment('IT');

        $person = new CompanyPerson();
        $person->setName('spouse');

        $manager->setSpouse($person);

        $this->_em->persist($manager);
        $this->_em->persist($person);
        $this->_em->flush();
        $this->_em->clear();

        $dqlManager = $this->_em->createQuery('SELECT m FROM ' . CompanyManager::class . ' m WHERE m.spouse = ?1')
                                ->setParameter(1, $person->getId())
                                ->getSingleResult();

        self::assertEquals($manager->getId(), $dqlManager->getId());
        self::assertEquals($person->getId(), $dqlManager->getSpouse()->getId());
    }

    #[Group('DDC-817')]
    public function testFindByAssociation(): void
    {
        $manager = new CompanyManager();
        $manager->setName('gblanco');
        $manager->setSalary(1234);
        $manager->setTitle('Awesome!');
        $manager->setDepartment('IT');

        $person = new CompanyPerson();
        $person->setName('spouse');

        $manager->setSpouse($person);

        $this->_em->persist($manager);
        $this->_em->persist($person);
        $this->_em->flush();
        $this->_em->clear();

        $repos    = $this->_em->getRepository(CompanyManager::class);
        $pmanager = $repos->findOneBy(['spouse' => $person->getId()]);

        self::assertEquals($manager->getId(), $pmanager->getId());

        $repos    = $this->_em->getRepository(CompanyPerson::class);
        $pmanager = $repos->findOneBy(['spouse' => $person->getId()]);

        self::assertEquals($manager->getId(), $pmanager->getId());
    }

    #[Group('DDC-834')]
    public function testGetReferenceEntityWithSubclasses(): void
    {
        $manager = new CompanyManager();
        $manager->setName('gblanco');
        $manager->setSalary(1234);
        $manager->setTitle('Awesome!');
        $manager->setDepartment('IT');

        $this->_em->persist($manager);
        $this->_em->flush();
        $this->_em->clear();

        $ref = $this->_em->getReference(CompanyPerson::class, $manager->getId());
        self::assertFalse($this->isUninitializedObject($ref), 'Cannot Request a proxy from a class that has subclasses.');
        self::assertInstanceOf(CompanyPerson::class, $ref);
        self::assertInstanceOf(CompanyEmployee::class, $ref, 'Direct fetch of the reference has to load the child class Employee directly.');
        $this->_em->clear();

        $ref = $this->_em->getReference(CompanyManager::class, $manager->getId());
        self::assertTrue($this->isUninitializedObject($ref), 'A proxy can be generated only if no subclasses exists for the requested reference.');
    }

    #[Group('DDC-992')]
    public function testGetSubClassManyToManyCollection(): void
    {
        $manager = new CompanyManager();
        $manager->setName('gblanco');
        $manager->setSalary(1234);
        $manager->setTitle('Awesome!');
        $manager->setDepartment('IT');

        $person = new CompanyPerson();
        $person->setName('friend');

        $manager->addFriend($person);

        $this->_em->persist($manager);
        $this->_em->persist($person);
        $this->_em->flush();
        $this->_em->clear();

        $manager = $this->_em->find(CompanyManager::class, $manager->getId());

        self::assertCount(1, $manager->getFriends());
    }

    #[Group('DDC-1777')]
    public function testExistsSubclass(): void
    {
        $manager = new CompanyManager();
        $manager->setName('gblanco');
        $manager->setSalary(1234);
        $manager->setTitle('Awesome!');
        $manager->setDepartment('IT');

        self::assertFalse($this->_em->getUnitOfWork()->getEntityPersister($manager::class)->exists($manager));

        $this->_em->persist($manager);
        $this->_em->flush();

        self::assertTrue($this->_em->getUnitOfWork()->getEntityPersister($manager::class)->exists($manager));
    }

    #[Group('DDC-1637')]
    public function testMatching(): void
    {
        $manager = new CompanyManager();
        $manager->setName('gblanco');
        $manager->setSalary(1234);
        $manager->setTitle('Awesome!');
        $manager->setDepartment('IT');

        $this->_em->persist($manager);
        $this->_em->flush();

        $repository = $this->_em->getRepository(CompanyEmployee::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('department', 'IT'),
        ));
        self::assertCount(1, $users);

        $repository = $this->_em->getRepository(CompanyManager::class);
        $users      = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('department', 'IT'),
        ));
        self::assertCount(1, $users);
    }
}
