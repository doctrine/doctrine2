<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\IterableTester;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsComment;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;
use Doctrine\Tests\VerifyDeprecations;

class BasicFunctionalTest extends OrmFunctionalTestCase
{
    use VerifyDeprecations;

    protected function setUp() : void
    {
        $this->useModelSet('cms');
        parent::setUp();
    }

    public function testBasicUnitsOfWorkWithOneToManyAssociation()
    {
        // Create
        $user = new CmsUser;
        $user->name = 'Roman';
        $user->username = 'romanb';
        $user->status = 'developer';
        $this->_em->persist($user);

        $this->_em->flush();

        $this->assertTrue(is_numeric($user->id));
        $this->assertTrue($this->_em->contains($user));

        // Read
        $user2 = $this->_em->find(CmsUser::class, $user->id);
        $this->assertTrue($user === $user2);

        // Add a phonenumber
        $ph = new CmsPhonenumber;
        $ph->phonenumber = "12345";
        $user->addPhonenumber($ph);
        $this->_em->flush();
        $this->assertTrue($this->_em->contains($ph));
        $this->assertTrue($this->_em->contains($user));

        // Update name
        $user->name = 'guilherme';
        $this->_em->flush();
        $this->assertEquals('guilherme', $user->name);

        // Add another phonenumber
        $ph2 = new CmsPhonenumber;
        $ph2->phonenumber = "6789";
        $user->addPhonenumber($ph2);
        $this->_em->flush();
        $this->assertTrue($this->_em->contains($ph2));

        // Delete
        $this->_em->remove($user);
        $this->assertTrue($this->_em->getUnitOfWork()->isScheduledForDelete($user));
        $this->assertTrue($this->_em->getUnitOfWork()->isScheduledForDelete($ph));
        $this->assertTrue($this->_em->getUnitOfWork()->isScheduledForDelete($ph2));
        $this->_em->flush();
        $this->assertFalse($this->_em->getUnitOfWork()->isScheduledForDelete($user));
        $this->assertFalse($this->_em->getUnitOfWork()->isScheduledForDelete($ph));
        $this->assertFalse($this->_em->getUnitOfWork()->isScheduledForDelete($ph2));
        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_em->getUnitOfWork()->getEntityState($user));
        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_em->getUnitOfWork()->getEntityState($ph));
        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_em->getUnitOfWork()->getEntityState($ph2));
    }

    public function testOneToManyAssociationModification()
    {
        $user = new CmsUser;
        $user->name = 'Roman';
        $user->username = 'romanb';
        $user->status = 'developer';

        $ph1 = new CmsPhonenumber;
        $ph1->phonenumber = "0301234";
        $ph2 = new CmsPhonenumber;
        $ph2->phonenumber = "987654321";

        $user->addPhonenumber($ph1);
        $user->addPhonenumber($ph2);

        $this->_em->persist($user);
        $this->_em->flush();

        // Remove the first element from the collection
        unset($user->phonenumbers[0]);
        $ph1->user = null; // owning side!

        $this->_em->flush();

        $this->assertEquals(1, count($user->phonenumbers));
        $this->assertNull($ph1->user);
    }

    public function testBasicOneToOne()
    {
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);
        $user = new CmsUser;
        $user->name = 'Roman';
        $user->username = 'romanb';
        $user->status = 'developer';

        $address = new CmsAddress;
        $address->country = 'Germany';
        $address->city = 'Berlin';
        $address->zip = '12345';

        $user->address = $address; // inverse side
        $address->user = $user; // owning side!

        $this->_em->persist($user);
        $this->_em->flush();

        // Check that the foreign key has been set
        $userId = $this->_em->getConnection()->executeQuery(
            "SELECT user_id FROM cms_addresses WHERE id=?", [$address->id]
        )->fetchOne();
        $this->assertTrue(is_numeric($userId));

        $this->_em->clear();

        $user2 = $this->_em->createQuery('select u from \Doctrine\Tests\Models\CMS\CmsUser u where u.id=?1')
                ->setParameter(1, $userId)
                ->getSingleResult();

        // Address has been eager-loaded because it cant be lazy
        $this->assertInstanceOf(CmsAddress::class, $user2->address);
        $this->assertNotInstanceOf(Proxy::class, $user2->address);
    }

    /**
     * @group DDC-1230
     */
    public function testRemove()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_em->getUnitOfWork()->getEntityState($user), "State should be UnitOfWork::STATE_NEW");

        $this->_em->persist($user);

        $this->assertEquals(UnitOfWork::STATE_MANAGED, $this->_em->getUnitOfWork()->getEntityState($user), "State should be UnitOfWork::STATE_MANAGED");

        $this->_em->remove($user);

        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_em->getUnitOfWork()->getEntityState($user), "State should be UnitOfWork::STATE_NEW");

        $this->_em->persist($user);
        $this->_em->flush();
        $id = $user->getId();

        $this->_em->remove($user);

        $this->assertEquals(UnitOfWork::STATE_REMOVED, $this->_em->getUnitOfWork()->getEntityState($user), "State should be UnitOfWork::STATE_REMOVED");
        $this->_em->flush();

        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_em->getUnitOfWork()->getEntityState($user), "State should be UnitOfWork::STATE_NEW");

        $this->assertNull($this->_em->find(CmsUser::class, $id));
    }

    public function testOneToManyOrphanRemoval()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        for ($i=0; $i<3; ++$i) {
            $phone = new CmsPhonenumber;
            $phone->phonenumber = 100 + $i;
            $user->addPhonenumber($phone);
        }

        $this->_em->persist($user);

        $this->_em->flush();

        $user->getPhonenumbers()->remove(0);
        $this->assertEquals(2, count($user->getPhonenumbers()));

        $this->_em->flush();

        // Check that there are just 2 phonenumbers left
        $count = $this->_em->getConnection()->fetchOne("SELECT COUNT(*) FROM cms_phonenumbers");
        $this->assertEquals(2, $count); // only 2 remaining

        // check that clear() removes the others via orphan removal
        $user->getPhonenumbers()->clear();
        $this->_em->flush();
        $this->assertEquals(0, $this->_em->getConnection()->fetchOne("select count(*) from cms_phonenumbers"));
    }

    public function testBasicQuery()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';
        $this->_em->persist($user);
        $this->_em->flush();

        $query = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u");

        $users = $query->getResult();

        $this->assertEquals(1, count($users));
        $this->assertEquals('Guilherme', $users[0]->name);
        $this->assertEquals('gblanco', $users[0]->username);
        $this->assertEquals('developer', $users[0]->status);
        //$this->assertNull($users[0]->phonenumbers);
        //$this->assertNull($users[0]->articles);

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);

        $usersArray = $query->getArrayResult();

        $this->assertTrue(is_array($usersArray));
        $this->assertEquals(1, count($usersArray));
        $this->assertEquals('Guilherme', $usersArray[0]['name']);
        $this->assertEquals('gblanco', $usersArray[0]['username']);
        $this->assertEquals('developer', $usersArray[0]['status']);

        $usersScalar = $query->getScalarResult();

        $this->assertTrue(is_array($usersScalar));
        $this->assertEquals(1, count($usersScalar));
        $this->assertEquals('Guilherme', $usersScalar[0]['u_name']);
        $this->assertEquals('gblanco', $usersScalar[0]['u_username']);
        $this->assertEquals('developer', $usersScalar[0]['u_status']);
    }

    public function testBasicOneToManyInnerJoin()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';
        $this->_em->persist($user);
        $this->_em->flush();

        $query = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u join u.phonenumbers p");

        $users = $query->getResult();

        $this->assertEquals(0, count($users));
    }

    public function testBasicOneToManyLeftJoin()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';
        $this->_em->persist($user);
        $this->_em->flush();

        $query = $this->_em->createQuery("select u,p from Doctrine\Tests\Models\CMS\CmsUser u left join u.phonenumbers p");

        $users = $query->getResult();

        $this->assertEquals(1, count($users));
        $this->assertEquals('Guilherme', $users[0]->name);
        $this->assertEquals('gblanco', $users[0]->username);
        $this->assertEquals('developer', $users[0]->status);
        $this->assertInstanceOf(PersistentCollection::class, $users[0]->phonenumbers);
        $this->assertTrue($users[0]->phonenumbers->isInitialized());
        $this->assertEquals(0, $users[0]->phonenumbers->count());
    }

    public function testBasicRefresh()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        $this->_em->persist($user);
        $this->_em->flush();

        $user->status = 'mascot';

        $this->assertEquals('mascot', $user->status);
        $this->_em->refresh($user);
        $this->assertEquals('developer', $user->status);
    }

    /**
     * @group DDC-833
     */
    public function testRefreshResetsCollection()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        // Add a phonenumber
        $ph1 = new CmsPhonenumber;
        $ph1->phonenumber = "12345";
        $user->addPhonenumber($ph1);

        // Add a phonenumber
        $ph2 = new CmsPhonenumber;
        $ph2->phonenumber = "54321";

        $this->_em->persist($user);
        $this->_em->persist($ph1);
        $this->_em->persist($ph2);
        $this->_em->flush();

        $user->addPhonenumber($ph2);

        $this->assertEquals(2, count($user->phonenumbers));
        $this->_em->refresh($user);

        $this->assertEquals(1, count($user->phonenumbers));
    }

    /**
     * @group DDC-833
     */
    public function testDqlRefreshResetsCollection()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        // Add a phonenumber
        $ph1 = new CmsPhonenumber;
        $ph1->phonenumber = "12345";
        $user->addPhonenumber($ph1);

        // Add a phonenumber
        $ph2 = new CmsPhonenumber;
        $ph2->phonenumber = "54321";

        $this->_em->persist($user);
        $this->_em->persist($ph1);
        $this->_em->persist($ph2);
        $this->_em->flush();

        $user->addPhonenumber($ph2);

        $this->assertEquals(2, count($user->phonenumbers));
        $dql = "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1";
        $user = $this->_em->createQuery($dql)
                          ->setParameter(1, $user->id)
                          ->setHint(Query::HINT_REFRESH, true)
                          ->getSingleResult();

        $this->assertEquals(1, count($user->phonenumbers));
    }

    /**
     * @group DDC-833
     */
    public function testCreateEntityOfProxy()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        // Add a phonenumber
        $ph1 = new CmsPhonenumber;
        $ph1->phonenumber = "12345";
        $user->addPhonenumber($ph1);

        // Add a phonenumber
        $ph2 = new CmsPhonenumber;
        $ph2->phonenumber = "54321";

        $this->_em->persist($user);
        $this->_em->persist($ph1);
        $this->_em->persist($ph2);
        $this->_em->flush();
        $this->_em->clear();

        $userId = $user->id;
        $user = $this->_em->getReference(CmsUser::class, $user->id);

        $dql = "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1";
        $user = $this->_em->createQuery($dql)
                          ->setParameter(1, $userId)
                          ->getSingleResult();

        $this->assertEquals(1, count($user->phonenumbers));
    }

    public function testAddToCollectionDoesNotInitialize()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        for ($i=0; $i<3; ++$i) {
            $phone = new CmsPhonenumber;
            $phone->phonenumber = 100 + $i;
            $user->addPhonenumber($phone);
        }

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $this->assertEquals(3, $user->getPhonenumbers()->count());

        $query = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username='gblanco'");

        $gblanco = $query->getSingleResult();

        $this->assertFalse($gblanco->getPhonenumbers()->isInitialized());

        $newPhone = new CmsPhonenumber;
        $newPhone->phonenumber = 555;
        $gblanco->addPhonenumber($newPhone);

        $this->assertFalse($gblanco->getPhonenumbers()->isInitialized());
        $this->_em->persist($gblanco);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery("select u, p from Doctrine\Tests\Models\CMS\CmsUser u join u.phonenumbers p where u.username='gblanco'");
        $gblanco2 = $query->getSingleResult();
        $this->assertEquals(4, $gblanco2->getPhonenumbers()->count());
    }

    public function testInitializeCollectionWithNewObjectsRetainsNewObjects()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        for ($i=0; $i<3; ++$i) {
            $phone = new CmsPhonenumber;
            $phone->phonenumber = 100 + $i;
            $user->addPhonenumber($phone);
        }

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $this->assertEquals(3, $user->getPhonenumbers()->count());

        $query = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username='gblanco'");

        $gblanco = $query->getSingleResult();

        $this->assertFalse($gblanco->getPhonenumbers()->isInitialized());

        $newPhone = new CmsPhonenumber;
        $newPhone->phonenumber = 555;
        $gblanco->addPhonenumber($newPhone);

        $this->assertFalse($gblanco->getPhonenumbers()->isInitialized());
        $this->assertEquals(4, $gblanco->getPhonenumbers()->count());
        $this->assertTrue($gblanco->getPhonenumbers()->isInitialized());

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery("select u, p from Doctrine\Tests\Models\CMS\CmsUser u join u.phonenumbers p where u.username='gblanco'");
        $gblanco2 = $query->getSingleResult();
        $this->assertEquals(4, $gblanco2->getPhonenumbers()->count());
    }

    public function testSetSetAssociationWithGetReference()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';
        $this->_em->persist($user);

        $address = new CmsAddress;
        $address->country = 'Germany';
        $address->city = 'Berlin';
        $address->zip = '12345';
        $this->_em->persist($address);

        $this->_em->flush();
        $this->_em->clear(CmsAddress::class);

        $this->assertFalse($this->_em->contains($address));
        $this->assertTrue($this->_em->contains($user));

        // Assume we only got the identifier of the address and now want to attach
        // that address to the user without actually loading it, using getReference().
        $addressRef = $this->_em->getReference(CmsAddress::class, $address->getId());

        $user->setAddress($addressRef); // Ugh! Initializes address 'cause of $address->setUser($user)!

        $this->_em->flush();
        $this->_em->clear();

        // Check with a fresh load that the association is indeed there
        $query = $this->_em->createQuery("select u, a from Doctrine\Tests\Models\CMS\CmsUser u join u.address a where u.username='gblanco'");
        $gblanco = $query->getSingleResult();

        $this->assertInstanceOf(CmsUser::class, $gblanco);
        $this->assertInstanceOf(CmsAddress::class, $gblanco->getAddress());
        $this->assertEquals('Berlin', $gblanco->getAddress()->getCity());

    }

    public function testOneToManyCascadeRemove()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        for ($i=0; $i<3; ++$i) {
            $phone = new CmsPhonenumber;
            $phone->phonenumber = 100 + $i;
            $user->addPhonenumber($phone);
        }

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery("select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username='gblanco'");
        $gblanco = $query->getSingleResult();

        $this->_em->remove($gblanco);
        $this->_em->flush();

        $this->_em->clear();

        $this->assertEquals(0, $this->_em->createQuery(
                "select count(p.phonenumber) from Doctrine\Tests\Models\CMS\CmsPhonenumber p")
                ->getSingleScalarResult());

        $this->assertEquals(0, $this->_em->createQuery(
                "select count(u.id) from Doctrine\Tests\Models\CMS\CmsUser u")
                ->getSingleScalarResult());
    }

    public function testTextColumnSaveAndRetrieve()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        $this->_em->persist($user);

        $article = new CmsArticle();
        $article->text = "Lorem ipsum dolor sunt.";
        $article->topic = "A Test Article!";
        $article->setAuthor($user);

        $this->_em->persist($article);
        $this->_em->flush();
        $articleId = $article->id;

        $this->_em->clear();

        // test find() with leading backslash at the same time
        $articleNew = $this->_em->find('\Doctrine\Tests\Models\CMS\CmsArticle', $articleId);
        $this->assertTrue($this->_em->contains($articleNew));
        $this->assertEquals("Lorem ipsum dolor sunt.", $articleNew->text);

        $this->assertNotSame($article, $articleNew);

        $articleNew->text = "Lorem ipsum dolor sunt. And stuff!";

        $this->_em->flush();
        $this->_em->clear();

        $articleNew = $this->_em->find(CmsArticle::class, $articleId);
        $this->assertEquals("Lorem ipsum dolor sunt. And stuff!", $articleNew->text);
        $this->assertTrue($this->_em->contains($articleNew));
    }

    public function testFlushDoesNotIssueUnnecessaryUpdates()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        $address = new CmsAddress;
        $address->country = 'Germany';
        $address->city = 'Berlin';
        $address->zip = '12345';

        $address->user = $user;
        $user->address = $address;

        $article = new CmsArticle();
        $article->text = "Lorem ipsum dolor sunt.";
        $article->topic = "A Test Article!";
        $article->setAuthor($user);

        $this->_em->persist($article);
        $this->_em->persist($user);

        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery('select u,a,ad from Doctrine\Tests\Models\CMS\CmsUser u join u.articles a join u.address ad');
        $user2 = $query->getSingleResult();

        $this->assertEquals(1, count($user2->articles));
        $this->assertInstanceOf(CmsAddress::class, $user2->address);

        $oldLogger = $this->_em->getConnection()->getConfiguration()->getSQLLogger();
        $debugStack = new DebugStack();
        $this->_em->getConnection()->getConfiguration()->setSQLLogger($debugStack);

        $this->_em->flush();
        $this->assertEquals(0, count($debugStack->queries));

        $this->_em->getConnection()->getConfiguration()->setSQLLogger($oldLogger);
    }

    public function testRemoveEntityByReference()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $userRef = $this->_em->getReference(CmsUser::class, $user->getId());
        $this->_em->remove($userRef);
        $this->_em->flush();
        $this->_em->clear();

        $this->assertEquals(0, $this->_em->getConnection()->fetchOne("select count(*) from cms_users"));

        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(null);
    }

    public function testQueryEntityByReference()
    {
        $user = new CmsUser;
        $user->name = 'Guilherme';
        $user->username = 'gblanco';
        $user->status = 'developer';

        $address = new CmsAddress;
        $address->country = 'Germany';
        $address->city = 'Berlin';
        $address->zip = '12345';

        $user->setAddress($address);

        $this->_em->transactional(function($em) use($user) {
            $em->persist($user);
        });
        $this->_em->clear();

        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);

        $userRef = $this->_em->getReference(CmsUser::class, $user->getId());
        $address2 = $this->_em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsAddress a where a.user = :user')
                ->setParameter('user', $userRef)
                ->getSingleResult();

        $this->assertInstanceOf(Proxy::class, $address2->getUser());
        $this->assertTrue($userRef === $address2->getUser());
        $this->assertFalse($userRef->__isInitialized__);
        $this->assertEquals('Germany', $address2->country);
        $this->assertEquals('Berlin', $address2->city);
        $this->assertEquals('12345', $address2->zip);
    }

    public function testOneToOneNullUpdate()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';

        $address = new CmsAddress();
        $address->city = "Bonn";
        $address->zip = "12354";
        $address->country = "Germany";
        $address->street = "somestreet";
        $address->user = $user;

        $this->_em->persist($address);
        $this->_em->persist($user);
        $this->_em->flush();

        $this->assertEquals(1, $this->_em->getConnection()->fetchOne("select 1 from cms_addresses where user_id = ".$user->id));

        $address->user = null;
        $this->_em->flush();

        $this->assertNotEquals(1, $this->_em->getConnection()->fetchOne("select 1 from cms_addresses where user_id = ".$user->id));
    }

    /**
     * @group DDC-600
     * @group DDC-455
     */
    public function testNewAssociatedEntityDuringFlushThrowsException()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';

        $address = new CmsAddress();
        $address->city = "Bonn";
        $address->zip = "12354";
        $address->country = "Germany";
        $address->street = "somestreet";
        $address->user = $user;

        $this->_em->persist($address);

        // flushing without persisting $user should raise an exception
        $this->expectException(\InvalidArgumentException::class);
        $this->_em->flush();
    }

    /**
     * @group DDC-600
     * @group DDC-455
     */
    public function testNewAssociatedEntityDuringFlushThrowsException2()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';

        $address = new CmsAddress();
        $address->city = "Bonn";
        $address->zip = "12354";
        $address->country = "Germany";
        $address->street = "somestreet";
        $address->user = $user;

        $this->_em->persist($address);
        $this->_em->persist($user);
        $this->_em->flush();

        $u2 = new CmsUser;
        $u2->username = "beberlei";
        $u2->name = "Benjamin E.";
        $u2->status = 'inactive';
        $address->user = $u2;

        // flushing without persisting $u2 should raise an exception
        $this->expectException(\InvalidArgumentException::class);
        $this->_em->flush();
    }

    /**
     * @group DDC-600
     * @group DDC-455
     */
    public function testNewAssociatedEntityDuringFlushThrowsException3()
    {
        $art = new CmsArticle();
        $art->topic = 'topic';
        $art->text = 'the text';

        $com = new CmsComment();
        $com->topic = 'Good';
        $com->text = 'Really good!';
        $art->addComment($com);

        $this->_em->persist($art);

        // flushing without persisting $com should raise an exception
        $this->expectException(\InvalidArgumentException::class);
        $this->_em->flush();
    }

    public function testOneToOneOrphanRemoval()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';

        $address = new CmsAddress();
        $address->city = "Bonn";
        $address->zip = "12354";
        $address->country = "Germany";
        $address->street = "somestreet";
        $address->user = $user;
        $user->address = $address;

        $this->_em->persist($address);
        $this->_em->persist($user);
        $this->_em->flush();
        $addressId = $address->getId();

        $user->address = null;

        $this->_em->flush();

        $this->assertEquals(0, $this->_em->getConnection()->fetchOne("select count(*) from cms_addresses"));

        // check orphan removal through replacement
        $user->address = $address;
        $address->user = $user;

        $this->_em->flush();
        $this->assertEquals(1, $this->_em->getConnection()->fetchOne("select count(*) from cms_addresses"));

        // remove $address to free up unique key id
        $this->_em->remove($address);
        $this->_em->flush();

        $newAddress = new CmsAddress();
        $newAddress->city = "NewBonn";
        $newAddress->zip = "12354";
        $newAddress->country = "NewGermany";
        $newAddress->street = "somenewstreet";
        $newAddress->user = $user;
        $user->address = $newAddress;

        $this->_em->flush();
        $this->assertEquals(1, $this->_em->getConnection()->fetchOne("select count(*) from cms_addresses"));
    }

    public function testGetPartialReferenceToUpdateObjectWithoutLoadingIt()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';
        $this->_em->persist($user);
        $this->_em->flush();
        $userId = $user->id;
        $this->_em->clear();

        $user = $this->_em->getPartialReference(CmsUser::class, $userId);
        $this->assertTrue($this->_em->contains($user));
        $this->assertNull($user->getName());
        $this->assertEquals($userId, $user->id);

        $user->name = 'Stephan';
        $this->_em->flush();
        $this->_em->clear();

        $this->assertEquals('Benjamin E.', $this->_em->find(get_class($user), $userId)->name);
    }

    public function testMergePersistsNewEntities()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';

        $managedUser = $this->_em->merge($user);
        $this->assertEquals('beberlei', $managedUser->username);
        $this->assertEquals('Benjamin E.', $managedUser->name);
        $this->assertEquals('active', $managedUser->status);

        $this->assertTrue($user !== $managedUser);
        $this->assertTrue($this->_em->contains($managedUser));

        $this->_em->flush();
        $userId = $managedUser->id;
        $this->_em->clear();

        $user2 = $this->_em->find(get_class($managedUser), $userId);
        $this->assertInstanceOf(CmsUser::class, $user2);
        $this->assertHasDeprecationMessages();
    }

    public function testMergeNonPersistedProperties()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';
        $user->nonPersistedProperty = 'test';
        $user->nonPersistedPropertyObject = new CmsPhonenumber();

        $managedUser = $this->_em->merge($user);
        $this->assertEquals('test', $managedUser->nonPersistedProperty);
        $this->assertSame($user->nonPersistedProperty, $managedUser->nonPersistedProperty);
        $this->assertSame($user->nonPersistedPropertyObject, $managedUser->nonPersistedPropertyObject);

        $this->assertTrue($user !== $managedUser);
        $this->assertTrue($this->_em->contains($managedUser));

        $this->_em->flush();
        $userId = $managedUser->id;
        $this->_em->clear();

        $user2 = $this->_em->find(get_class($managedUser), $userId);
        $this->assertNull($user2->nonPersistedProperty);
        $this->assertNull($user2->nonPersistedPropertyObject);
        $this->assertEquals('active', $user2->status);
        $this->assertHasDeprecationMessages();
    }

    public function testMergeThrowsExceptionIfEntityWithGeneratedIdentifierDoesNotExist()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';
        $user->id = 42;

        $this->expectException(EntityNotFoundException::class);
        $this->_em->merge($user);
        $this->assertHasDeprecationMessages();
    }

    /**
     * @group DDC-634
     */
    public function testOneToOneMergeSetNull()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';

        $ph = new CmsPhonenumber();
        $ph->phonenumber = "12345";
        $user->addPhonenumber($ph);

        $this->_em->persist($user);
        $this->_em->persist($ph);
        $this->_em->flush();

        $this->_em->clear();

        $ph->user = null;
        $managedPh = $this->_em->merge($ph);

        $this->_em->flush();
        $this->_em->clear();

        $this->assertNull($this->_em->find(get_class($ph), $ph->phonenumber)->getUser());
        $this->assertHasDeprecationMessages();
    }

    /**
     * @group DDC-952
     */
    public function testManyToOneFetchModeQuery()
    {
        $user = new CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin E.";
        $user->status = 'active';

        $article = new CmsArticle();
        $article->topic = "foo";
        $article->text = "bar";
        $article->user = $user;

        $this->_em->persist($article);
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $qc = $this->getCurrentQueryCount();
        $dql = "SELECT a FROM Doctrine\Tests\Models\CMS\CmsArticle a WHERE a.id = ?1";
        $article = $this->_em->createQuery($dql)
                             ->setParameter(1, $article->id)
                             ->setFetchMode(CmsArticle::class, 'user', ClassMetadata::FETCH_EAGER)
                             ->getSingleResult();
        $this->assertInstanceOf(Proxy::class, $article->user, "It IS a proxy, ...");
        $this->assertTrue($article->user->__isInitialized__, "...but its initialized!");
        $this->assertEquals($qc+2, $this->getCurrentQueryCount());
    }

    /**
     * @group DDC-1278
     */
    public function testClearWithEntityName()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';

        $address = new CmsAddress();
        $address->city = "Springfield";
        $address->zip = "12354";
        $address->country = "Germany";
        $address->street = "Foo Street";
        $address->user = $user;
        $user->address = $address;

        $article1 = new CmsArticle();
        $article1->topic = 'Foo';
        $article1->text = 'Foo Text';

        $article2 = new CmsArticle();
        $article2->topic = 'Bar';
        $article2->text = 'Bar Text';

        $user->addArticle($article1);
        $user->addArticle($article2);

        $this->_em->persist($article1);
        $this->_em->persist($article2);
        $this->_em->persist($address);
        $this->_em->persist($user);
        $this->_em->flush();

        $unitOfWork = $this->_em->getUnitOfWork();

        $this->_em->clear(CmsUser::class);

        $this->assertEquals(UnitOfWork::STATE_DETACHED, $unitOfWork->getEntityState($user));
        $this->assertEquals(UnitOfWork::STATE_DETACHED, $unitOfWork->getEntityState($article1));
        $this->assertEquals(UnitOfWork::STATE_DETACHED, $unitOfWork->getEntityState($article2));
        $this->assertEquals(UnitOfWork::STATE_MANAGED, $unitOfWork->getEntityState($address));

        $this->_em->clear();

        $this->assertEquals(UnitOfWork::STATE_DETACHED, $unitOfWork->getEntityState($address));
    }

    public function testFlushManyExplicitEntities()
    {
        $userA = new CmsUser;
        $userA->username = 'UserA';
        $userA->name = 'UserA';

        $userB = new CmsUser;
        $userB->username = 'UserB';
        $userB->name = 'UserB';

        $userC = new CmsUser;
        $userC->username = 'UserC';
        $userC->name = 'UserC';

        $this->_em->persist($userA);
        $this->_em->persist($userB);
        $this->_em->persist($userC);

        $this->_em->flush([$userA, $userB, $userB]);

        $userC->name = 'changed name';

        $this->_em->flush([$userA, $userB]);
        $this->_em->refresh($userC);

        $this->assertTrue($userA->id > 0, 'user a has an id');
        $this->assertTrue($userB->id > 0, 'user b has an id');
        $this->assertTrue($userC->id > 0, 'user c has an id');
        $this->assertEquals('UserC', $userC->name, 'name has not changed because we did not flush it');
        $this->assertHasDeprecationMessages();
    }

    /**
     * @group DDC-720
     */
    public function testFlushSingleManagedEntity()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';

        $this->_em->persist($user);
        $this->_em->flush();

        $user->status = 'administrator';
        $this->_em->flush($user);
        $this->_em->clear();

        $user = $this->_em->find(get_class($user), $user->id);
        $this->assertEquals('administrator', $user->status);
        $this->assertHasDeprecationMessages();
    }

    /**
     * @group DDC-720
     */
    public function testFlushSingleUnmanagedEntity()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity has to be managed or scheduled for removal for single computation');

        $this->_em->flush($user);
    }

    /**
     * @group DDC-720
     */
    public function testFlushSingleAndNewEntity()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';

        $this->_em->persist($user);
        $this->_em->flush();

        $otherUser = new CmsUser;
        $otherUser->name = 'Dominik2';
        $otherUser->username = 'domnikl2';
        $otherUser->status = 'developer';

        $user->status = 'administrator';

        $this->_em->persist($otherUser);
        $this->_em->flush($user);

        $this->assertTrue($this->_em->contains($otherUser), "Other user is contained in EntityManager");
        $this->assertTrue($otherUser->id > 0, "other user has an id");
        $this->assertHasDeprecationMessages();
    }

    /**
     * @group DDC-720
     */
    public function testFlushAndCascadePersist()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';

        $this->_em->persist($user);
        $this->_em->flush();

        $address = new CmsAddress();
        $address->city = "Springfield";
        $address->zip = "12354";
        $address->country = "Germany";
        $address->street = "Foo Street";
        $address->user = $user;
        $user->address = $address;

        $this->_em->flush($user);

        $this->assertTrue($this->_em->contains($address), "Other user is contained in EntityManager");
        $this->assertTrue($address->id > 0, "other user has an id");
        $this->assertHasDeprecationMessages();
    }

    /**
     * @group DDC-720
     */
    public function testFlushSingleAndNoCascade()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';

        $this->_em->persist($user);
        $this->_em->flush();

        $article1 = new CmsArticle();
        $article1->topic = 'Foo';
        $article1->text = 'Foo Text';
        $article1->author = $user;
        $user->articles[] = $article1;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("A new entity was found through the relationship 'Doctrine\Tests\Models\CMS\CmsUser#articles'");

        $this->_em->flush($user);
    }

    /**
     * @group DDC-720
     * @group DDC-1612
     * @group DDC-2267
     */
    public function testFlushSingleNewEntityThenRemove()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';

        $this->_em->persist($user);
        $this->_em->flush($user);

        $userId = $user->id;

        $this->_em->remove($user);
        $this->_em->flush($user);
        $this->_em->clear();

        $this->assertNull($this->_em->find(get_class($user), $userId));
        $this->assertHasDeprecationMessages();
    }

    /**
     * @group DDC-720
     */
    public function testProxyIsIgnored()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->getReference(get_class($user), $user->id);

        $otherUser = new CmsUser;
        $otherUser->name = 'Dominik2';
        $otherUser->username = 'domnikl2';
        $otherUser->status = 'developer';

        $this->_em->persist($otherUser);
        $this->_em->flush($user);

        $this->assertTrue($this->_em->contains($otherUser), "Other user is contained in EntityManager");
        $this->assertTrue($otherUser->id > 0, "other user has an id");
        $this->assertHasDeprecationMessages();
    }

    /**
     * @group DDC-720
     */
    public function testFlushSingleSaveOnlySingle()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';
        $this->_em->persist($user);

        $user2 = new CmsUser;
        $user2->name = 'Dominik';
        $user2->username = 'domnikl2';
        $user2->status = 'developer';
        $this->_em->persist($user2);

        $this->_em->flush();

        $user->status = 'admin';
        $user2->status = 'admin';

        $this->_em->flush($user);
        $this->_em->clear();

        $user2 = $this->_em->find(get_class($user2), $user2->id);
        $this->assertEquals('developer', $user2->status);
        $this->assertHasDeprecationMessages();
    }

    /**
     * @group DDC-1585
     */
    public function testWrongAssociationInstance()
    {
        $user = new CmsUser;
        $user->name = 'Dominik';
        $user->username = 'domnikl';
        $user->status = 'developer';
        $user->address = $user;

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected value of type "Doctrine\Tests\Models\CMS\CmsAddress" for association field ' .
            '"Doctrine\Tests\Models\CMS\CmsUser#$address", got "Doctrine\Tests\Models\CMS\CmsUser" instead.'
        );

        $this->_em->persist($user);

        $this->_em->flush();
    }
}
