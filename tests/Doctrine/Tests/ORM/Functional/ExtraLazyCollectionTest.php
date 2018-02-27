<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\DDC2504\DDC2504ChildClass;
use Doctrine\Tests\Models\DDC2504\DDC2504OtherClass;
use Doctrine\Tests\Models\Tweet\Tweet;
use Doctrine\Tests\Models\Tweet\User;
use Doctrine\Tests\Models\Tweet\UserList;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Description of ExtraLazyCollectionTest
 *
 * @author beberlei
 */
class ExtraLazyCollectionTest extends OrmFunctionalTestCase
{
    private $userId;
    private $userId2;
    private $groupId;
    private $articleId;
    private $ddc2504OtherClassId;
    private $ddc2504ChildClassId;

    private $username;
    private $groupname;
    private $topic;
    private $phonenumber;

    public function setUp()
    {
        $this->useModelSet('tweet');
        $this->useModelSet('cms');
        $this->useModelSet('ddc2504');
        parent::setUp();

        $class = $this->_em->getClassMetadata(CmsUser::class);
        $class->associationMappings['groups']['fetch'] = ClassMetadata::FETCH_EXTRA_LAZY;
        $class->associationMappings['groups']['indexBy'] = 'name';
        $class->associationMappings['articles']['fetch'] = ClassMetadata::FETCH_EXTRA_LAZY;
        $class->associationMappings['articles']['indexBy'] = 'topic';
        $class->associationMappings['phonenumbers']['fetch'] = ClassMetadata::FETCH_EXTRA_LAZY;
        $class->associationMappings['phonenumbers']['indexBy'] = 'phonenumber';

        unset($class->associationMappings['phonenumbers']['cache']);
        unset($class->associationMappings['articles']['cache']);
        unset($class->associationMappings['users']['cache']);

        $class = $this->_em->getClassMetadata(CmsGroup::class);
        $class->associationMappings['users']['fetch'] = ClassMetadata::FETCH_EXTRA_LAZY;
        $class->associationMappings['users']['indexBy'] = 'username';

        $this->loadFixture();
    }

    public function tearDown()
    {
        parent::tearDown();

        $class = $this->_em->getClassMetadata(CmsUser::class);
        $class->associationMappings['groups']['fetch'] = ClassMetadata::FETCH_LAZY;
        $class->associationMappings['articles']['fetch'] = ClassMetadata::FETCH_LAZY;
        $class->associationMappings['phonenumbers']['fetch'] = ClassMetadata::FETCH_LAZY;

        unset($class->associationMappings['groups']['indexBy']);
        unset($class->associationMappings['articles']['indexBy']);
        unset($class->associationMappings['phonenumbers']['indexBy']);

        $class = $this->_em->getClassMetadata(CmsGroup::class);
        $class->associationMappings['users']['fetch'] = ClassMetadata::FETCH_LAZY;

        unset($class->associationMappings['users']['indexBy']);
    }

    /**
     * @group DDC-546
     * @group non-cacheable
     */
    public function testCountNotInitializesCollection()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->groups->isInitialized());
        $this->assertEquals(3, count($user->groups));
        $this->assertFalse($user->groups->isInitialized());

        foreach ($user->groups AS $group) { }

        $this->assertEquals($queryCount + 2, $this->getCurrentQueryCount(), "Expecting two queries to be fired for count, then iteration.");
    }

    /**
     * @group DDC-546
     */
    public function testCountWhenNewEntityPresent()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);

        $newGroup = new CmsGroup();
        $newGroup->name = "Test4";

        $user->addGroup($newGroup);
        $this->_em->persist($newGroup);

        $this->assertFalse($user->groups->isInitialized());
        $this->assertEquals(4, count($user->groups));
        $this->assertFalse($user->groups->isInitialized());
    }

    /**
     * @group DDC-546
     * @group non-cacheable
     */
    public function testCountWhenInitialized()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        foreach ($user->groups AS $group) { }

        $this->assertTrue($user->groups->isInitialized());
        $this->assertEquals(3, count($user->groups));
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Should only execute one query to initialize collection, no extra query for count() more.");
    }

    /**
     * @group DDC-546
     */
    public function testCountInverseCollection()
    {
        $group = $this->_em->find(CmsGroup::class, $this->groupId);
        $this->assertFalse($group->users->isInitialized(), "Pre-Condition");

        $this->assertEquals(4, count($group->users));
        $this->assertFalse($group->users->isInitialized(), "Extra Lazy collection should not be initialized by counting the collection.");
    }

    /**
     * @group DDC-546
     */
    public function testCountOneToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition");

        $this->assertEquals(2, count($user->articles));
    }

    /**
     * @group DDC-2504
     */
    public function testCountOneToManyJoinedInheritance()
    {
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);

        $this->assertFalse($otherClass->childClasses->isInitialized(), "Pre-Condition");
        $this->assertEquals(2, count($otherClass->childClasses));
    }

    /**
     * @group DDC-546
     */
    public function testFullSlice()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $someGroups = $user->groups->slice(null);
        $this->assertEquals(3, count($someGroups));
    }

    /**
     * @group DDC-546
     * @group non-cacheable
     */
    public function testSlice()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $queryCount = $this->getCurrentQueryCount();

        $someGroups = $user->groups->slice(0, 2);

        $this->assertContainsOnly(CmsGroup::class, $someGroups);
        $this->assertEquals(2, count($someGroups));
        $this->assertFalse($user->groups->isInitialized(), "Slice should not initialize the collection if it wasn't before!");

        $otherGroup = $user->groups->slice(2, 1);

        $this->assertContainsOnly(CmsGroup::class, $otherGroup);
        $this->assertEquals(1, count($otherGroup));
        $this->assertFalse($user->groups->isInitialized());

        foreach ($user->groups AS $group) { }

        $this->assertTrue($user->groups->isInitialized());
        $this->assertEquals(3, count($user->groups));

        $this->assertEquals($queryCount + 3, $this->getCurrentQueryCount());
    }

    /**
     * @group DDC-546
     * @group non-cacheable
     */
    public function testSliceInitializedCollection()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        foreach ($user->groups AS $group) { }

        $someGroups = $user->groups->slice(0, 2);

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        $this->assertEquals(2, count($someGroups));
        $this->assertTrue($user->groups->contains(array_shift($someGroups)));
        $this->assertTrue($user->groups->contains(array_shift($someGroups)));
    }

    /**
     * @group DDC-546
     */
    public function testSliceInverseCollection()
    {
        $group = $this->_em->find(CmsGroup::class, $this->groupId);
        $this->assertFalse($group->users->isInitialized(), "Pre-Condition");
        $queryCount = $this->getCurrentQueryCount();

        $someUsers = $group->users->slice(0, 2);
        $otherUsers = $group->users->slice(2, 2);

        $this->assertContainsOnly(CmsUser::class, $someUsers);
        $this->assertContainsOnly(CmsUser::class, $otherUsers);
        $this->assertEquals(2, count($someUsers));
        $this->assertEquals(2, count($otherUsers));

        // +2 queries executed by slice
        $this->assertEquals($queryCount + 2, $this->getCurrentQueryCount(), "Slicing two parts should only execute two additional queries.");
    }

    /**
     * @group DDC-546
     */
    public function testSliceOneToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $this->assertFalse($user->articles->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $queryCount = $this->getCurrentQueryCount();

        $someArticle = $user->articles->slice(0, 1);
        $otherArticle = $user->articles->slice(1, 1);

        $this->assertEquals($queryCount + 2, $this->getCurrentQueryCount());
    }

    /**
     * @group DDC-546
     */
    public function testContainsOneToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $this->assertFalse($user->articles->isInitialized(), "Pre-Condition: Collection is not initialized.");

        // Test One to Many existence retrieved from DB
        $article    = $this->_em->find(CmsArticle::class, $this->articleId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertTrue($user->articles->contains($article));
        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        // Test One to Many existence with state new
        $article = new CmsArticle();
        $article->topic = "Testnew";
        $article->text = "blub";

        $queryCount = $this->getCurrentQueryCount();
        $this->assertFalse($user->articles->contains($article));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of new entity should cause no query to be executed.");

        // Test One to Many existence with state clear
        $this->_em->persist($article);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();
        $this->assertFalse($user->articles->contains($article));
        $this->assertEquals($queryCount+1, $this->getCurrentQueryCount(), "Checking for contains of persisted entity should cause one query to be executed.");
        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test One to Many existence with state managed
        $article = new CmsArticle();
        $article->topic = "How to not fail anymore on tests";
        $article->text = "That is simple! Just write more tests!";

        $this->_em->persist($article);

        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->articles->contains($article));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of managed entity (but not persisted) should cause no query to be executed.");
        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     * @group DDC-2504
     */
    public function testLazyOneToManyJoinedInheritanceIsLazilyInitialized()
    {
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);

        $this->assertFalse($otherClass->childClasses->isInitialized(), 'Collection is not initialized.');
    }

    /**
     * @group DDC-2504
     */
    public function testContainsOnOneToManyJoinedInheritanceWillNotInitializeCollectionWhenMatchingItemIsFound()
    {
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);

        // Test One to Many existence retrieved from DB
        $childClass = $this->_em->find(DDC2504ChildClass::class, $this->ddc2504ChildClassId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertTrue($otherClass->childClasses->contains($childClass));
        $this->assertFalse($otherClass->childClasses->isInitialized(), 'Collection is not initialized.');
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), 'Search operation was performed via SQL');
    }

    /**
     * @group DDC-2504
     */
    public function testContainsOnOneToManyJoinedInheritanceWillNotCauseQueriesWhenNonPersistentItemIsMatched()
    {
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($otherClass->childClasses->contains(new DDC2504ChildClass()));
        $this->assertEquals(
            $queryCount,
            $this->getCurrentQueryCount(),
            'Checking for contains of new entity should cause no query to be executed.'
        );
    }

    /**
     * @group DDC-2504
     */
    public function testContainsOnOneToManyJoinedInheritanceWillNotInitializeCollectionWithClearStateMatchingItem()
    {
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);
        $childClass = new DDC2504ChildClass();

        // Test One to Many existence with state clear
        $this->_em->persist($childClass);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();
        $this->assertFalse($otherClass->childClasses->contains($childClass));
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Checking for contains of persisted entity should cause one query to be executed.");
        $this->assertFalse($otherClass->childClasses->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     * @group DDC-2504
     */
    public function testContainsOnOneToManyJoinedInheritanceWillNotInitializeCollectionWithNewStateNotMatchingItem()
    {
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);
        $childClass = new DDC2504ChildClass();

        $this->_em->persist($childClass);

        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($otherClass->childClasses->contains($childClass));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of managed entity (but not persisted) should cause no query to be executed.");
        $this->assertFalse($otherClass->childClasses->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     * @group DDC-2504
     */
    public function testCountingOnOneToManyJoinedInheritanceWillNotInitializeCollection()
    {
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);

        $this->assertEquals(2, count($otherClass->childClasses));

        $this->assertFalse($otherClass->childClasses->isInitialized());
    }

    /**
     * @group DDC-546
     */
    public function testContainsManyToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition: Collection is not initialized.");

        // Test Many to Many existence retrieved from DB
        $group      = $this->_em->find(CmsGroup::class, $this->groupId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertTrue($user->groups->contains($group));
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Checking for contains of managed entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many existence with state new
        $group = new CmsGroup();
        $group->name = "A New group!";

        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->groups->contains($group));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of new entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many existence with state clear
        $this->_em->persist($group);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->groups->contains($group));
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Checking for contains of persisted entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many existence with state managed
        $group = new CmsGroup();
        $group->name = "My managed group";

        $this->_em->persist($group);

        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->groups->contains($group));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of managed entity (but not persisted) should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     * @group DDC-546
     */
    public function testContainsManyToManyInverse()
    {
        $group = $this->_em->find(CmsGroup::class, $this->groupId);
        $this->assertFalse($group->users->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $user = $this->_em->find(CmsUser::class, $this->userId);

        $queryCount = $this->getCurrentQueryCount();
        $this->assertTrue($group->users->contains($user));
        $this->assertEquals($queryCount+1, $this->getCurrentQueryCount(), "Checking for contains of managed entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        $newUser = new CmsUser();
        $newUser->name = "A New group!";

        $queryCount = $this->getCurrentQueryCount();
        $this->assertFalse($group->users->contains($newUser));
        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Checking for contains of new entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     *
     */
    public function testRemoveElementOneToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $this->assertFalse($user->articles->isInitialized(), "Pre-Condition: Collection is not initialized.");

        // Test One to Many removal with Entity retrieved from DB
        $article    = $this->_em->find(CmsArticle::class, $this->articleId);
        $queryCount = $this->getCurrentQueryCount();

        $user->articles->removeElement($article);

        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");
        $this->assertEquals($queryCount, $this->getCurrentQueryCount());

        // Test One to Many removal with Entity state as new
        $article = new CmsArticle();
        $article->topic = "Testnew";
        $article->text = "blub";

        $queryCount = $this->getCurrentQueryCount();

        $user->articles->removeElement($article);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing a new entity should cause no query to be executed.");

        // Test One to Many removal with Entity state as clean
        $this->_em->persist($article);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();

        $user->articles->removeElement($article);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing a persisted entity will not cause queries when the owning side doesn't actually change.");
        $this->assertFalse($user->articles->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test One to Many removal with Entity state as managed
        $article = new CmsArticle();
        $article->topic = "How to not fail anymore on tests";
        $article->text = "That is simple! Just write more tests!";

        $this->_em->persist($article);

        $queryCount = $this->getCurrentQueryCount();

        $user->articles->removeElement($article);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing a managed entity should cause no query to be executed.");
    }

    /**
     * @group DDC-2504
     */
    public function testRemovalOfManagedElementFromOneToManyJoinedInheritanceCollectionDoesNotInitializeIt()
    {
        /* @var $otherClass DDC2504OtherClass */
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);
        /* @var $childClass DDC2504ChildClass */
        $childClass = $this->_em->find(DDC2504ChildClass::class, $this->ddc2504ChildClassId);

        $queryCount = $this->getCurrentQueryCount();

        $otherClass->childClasses->removeElement($childClass);
        $childClass->other = null; // updating owning side

        $this->assertFalse($otherClass->childClasses->isInitialized(), 'Collection is not initialized.');

        $this->assertEquals(
            $queryCount,
            $this->getCurrentQueryCount(),
            'No queries have been executed'
        );

        $this->assertTrue(
            $otherClass->childClasses->contains($childClass),
            'Collection item still not updated (needs flushing)'
        );

        $this->_em->flush();

        $this->assertFalse(
            $otherClass->childClasses->contains($childClass),
            'Referenced item was removed in the transaction'
        );

        $this->assertFalse($otherClass->childClasses->isInitialized(), 'Collection is not initialized.');
    }

    /**
     * @group DDC-2504
     */
    public function testRemovalOfNonManagedElementFromOneToManyJoinedInheritanceCollectionDoesNotInitializeIt()
    {
        /* @var $otherClass DDC2504OtherClass */
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);
        $queryCount = $this->getCurrentQueryCount();

        $otherClass->childClasses->removeElement(new DDC2504ChildClass());

        $this->assertEquals(
            $queryCount,
            $this->getCurrentQueryCount(),
            'Removing an unmanaged entity should cause no query to be executed.'
        );
    }

    /**
     * @group DDC-2504
     */
    public function testRemovalOfNewElementFromOneToManyJoinedInheritanceCollectionDoesNotInitializeIt()
    {
        /* @var $otherClass DDC2504OtherClass */
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);
        $childClass = new DDC2504ChildClass();

        $this->_em->persist($childClass);

        $queryCount = $this->getCurrentQueryCount();

        $otherClass->childClasses->removeElement($childClass);

        $this->assertEquals(
            $queryCount,
            $this->getCurrentQueryCount(),
            'Removing a new entity should cause no query to be executed.'
        );
    }

    /**
     * @group DDC-2504
     */
    public function testRemovalOfNewManagedElementFromOneToManyJoinedInheritanceCollectionDoesNotInitializeIt()
    {
        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);
        $childClass = new DDC2504ChildClass();

        $this->_em->persist($childClass);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();

        $otherClass->childClasses->removeElement($childClass);

        $this->assertEquals(
            $queryCount,
            $this->getCurrentQueryCount(),
            'No queries are executed, as the owning side of the association is not actually updated.'
        );
        $this->assertFalse($otherClass->childClasses->isInitialized(), 'Collection is not initialized.');
    }

    /**
     *
     */
    public function testRemoveElementManyToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $this->assertFalse($user->groups->isInitialized(), "Pre-Condition: Collection is not initialized.");

        // Test Many to Many removal with Entity retrieved from DB
        $group      = $this->_em->find(CmsGroup::class, $this->groupId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertTrue($user->groups->removeElement($group));

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Removing a persisted entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        $this->assertFalse($user->groups->removeElement($group), "Removing an already removed element returns false");

        // Test Many to Many removal with Entity state as new
        $group = new CmsGroup();
        $group->name = "A New group!";

        $queryCount = $this->getCurrentQueryCount();

        $user->groups->removeElement($group);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing new entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many removal with Entity state as clean
        $this->_em->persist($group);
        $this->_em->flush();

        $queryCount = $this->getCurrentQueryCount();

        $user->groups->removeElement($group);

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Removing a persisted entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        // Test Many to Many removal with Entity state as managed
        $group = new CmsGroup();
        $group->name = "A New group!";

        $this->_em->persist($group);

        $queryCount = $this->getCurrentQueryCount();

        $user->groups->removeElement($group);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing a managed entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     *
     */
    public function testRemoveElementManyToManyInverse()
    {
        $group = $this->_em->find(CmsGroup::class, $this->groupId);
        $this->assertFalse($group->users->isInitialized(), "Pre-Condition: Collection is not initialized.");

        $user       = $this->_em->find(CmsUser::class, $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        $group->users->removeElement($user);

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Removing a managed entity should cause one query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");

        $newUser = new CmsUser();
        $newUser->name = "A New group!";

        $queryCount = $this->getCurrentQueryCount();

        $group->users->removeElement($newUser);

        $this->assertEquals($queryCount, $this->getCurrentQueryCount(), "Removing a new entity should cause no query to be executed.");
        $this->assertFalse($user->groups->isInitialized(), "Post-Condition: Collection is not initialized.");
    }

    /**
     * @group DDC-1399
     */
    public function testCountAfterAddThenFlush()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);

        $newGroup = new CmsGroup();
        $newGroup->name = "Test4";

        $user->addGroup($newGroup);
        $this->_em->persist($newGroup);

        $this->assertFalse($user->groups->isInitialized());
        $this->assertEquals(4, count($user->groups));
        $this->assertFalse($user->groups->isInitialized());

        $this->_em->flush();

        $this->assertEquals(4, count($user->groups));
    }

    /**
     * @group DDC-1462
     * @group non-cacheable
     */
    public function testSliceOnDirtyCollection()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        /* @var $user CmsUser */

        $newGroup = new CmsGroup();
        $newGroup->name = "Test4";

        $user->addGroup($newGroup);
        $this->_em->persist($newGroup);

        $qc = $this->getCurrentQueryCount();
        $groups = $user->groups->slice(0, 10);

        $this->assertEquals(4, count($groups));
        $this->assertEquals($qc + 1, $this->getCurrentQueryCount());
    }

    /**
     * @group DDC-1398
     * @group non-cacheable
     */
    public function testGetIndexByIdentifier()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        /* @var $user CmsUser */

        $queryCount = $this->getCurrentQueryCount();
        $phonenumber = $user->phonenumbers->get($this->phonenumber);

        $this->assertFalse($user->phonenumbers->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        $this->assertSame($phonenumber, $this->_em->find(CmsPhonenumber::class, $this->phonenumber));

        $article = $user->phonenumbers->get($this->phonenumber);
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount(), "Getting the same entity should not cause an extra query to be executed");
    }

    /**
     * @group DDC-1398
     */
    public function testGetIndexByOneToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        /* @var $user CmsUser */

        $queryCount = $this->getCurrentQueryCount();

        $article = $user->articles->get($this->topic);

        $this->assertFalse($user->articles->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        $this->assertSame($article, $this->_em->find(CmsArticle::class, $this->articleId));
    }

    /**
     * @group DDC-1398
     */
    public function testGetIndexByManyToManyInverseSide()
    {
        $group = $this->_em->find(CmsGroup::class, $this->groupId);
        /* @var $group CmsGroup */

        $queryCount = $this->getCurrentQueryCount();

        $user = $group->users->get($this->username);

        $this->assertFalse($group->users->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        $this->assertSame($user, $this->_em->find(CmsUser::class, $this->userId));
    }

    /**
     * @group DDC-1398
     */
    public function testGetIndexByManyToManyOwningSide()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        /* @var $user CmsUser */

        $queryCount = $this->getCurrentQueryCount();

        $group = $user->groups->get($this->groupname);

        $this->assertFalse($user->groups->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        $this->assertSame($group, $this->_em->find(CmsGroup::class, $this->groupId));
    }

    /**
     * @group DDC-1398
     */
    public function testGetNonExistentIndexBy()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        $this->assertNull($user->articles->get(-1));
        $this->assertNull($user->groups->get(-1));
    }

    public function testContainsKeyIndexByOneToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);
        /* @var $user CmsUser */

        $queryCount = $this->getCurrentQueryCount();

        $contains = $user->articles->containsKey($this->topic);

        $this->assertTrue($contains);
        $this->assertFalse($user->articles->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }

    public function testContainsKeyIndexByOneToManyJoinedInheritance()
    {
        $class = $this->_em->getClassMetadata(DDC2504OtherClass::class);
        $class->associationMappings['childClasses']['indexBy'] = 'id';

        $otherClass = $this->_em->find(DDC2504OtherClass::class, $this->ddc2504OtherClassId);

        $queryCount = $this->getCurrentQueryCount();

        $contains = $otherClass->childClasses->containsKey($this->ddc2504ChildClassId);

        $this->assertTrue($contains);
        $this->assertFalse($otherClass->childClasses->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }

    public function testContainsKeyIndexByManyToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId2);

        $group = $this->_em->find(CmsGroup::class, $this->groupId);

        $queryCount = $this->getCurrentQueryCount();

        $contains = $user->groups->containsKey($group->name);

        $this->assertTrue($contains, "The item is not into collection");
        $this->assertFalse($user->groups->isInitialized(), "The collection must not be initialized");
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }
    public function testContainsKeyIndexByManyToManyNonOwning()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId2);
        $group = $this->_em->find(CmsGroup::class, $this->groupId);

        $queryCount = $this->getCurrentQueryCount();

        $contains = $group->users->containsKey($user->username);

        $this->assertTrue($contains, "The item is not into collection");
        $this->assertFalse($group->users->isInitialized(), "The collection must not be initialized");
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }

    public function testContainsKeyIndexByWithPkManyToMany()
    {
        $class = $this->_em->getClassMetadata(CmsUser::class);
        $class->associationMappings['groups']['indexBy'] = 'id';

        $user = $this->_em->find(CmsUser::class, $this->userId2);

        $queryCount = $this->getCurrentQueryCount();

        $contains = $user->groups->containsKey($this->groupId);

        $this->assertTrue($contains, "The item is not into collection");
        $this->assertFalse($user->groups->isInitialized(), "The collection must not be initialized");
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }
    public function testContainsKeyIndexByWithPkManyToManyNonOwning()
    {
        $class = $this->_em->getClassMetadata(CmsGroup::class);
        $class->associationMappings['users']['indexBy'] = 'id';

        $group = $this->_em->find(CmsGroup::class, $this->groupId);

        $queryCount = $this->getCurrentQueryCount();

        $contains = $group->users->containsKey($this->userId2);

        $this->assertTrue($contains, "The item is not into collection");
        $this->assertFalse($group->users->isInitialized(), "The collection must not be initialized");
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }

    public function testContainsKeyNonExistentIndexByOneToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId2);

        $queryCount = $this->getCurrentQueryCount();

        $contains = $user->articles->containsKey("NonExistentTopic");

        $this->assertFalse($contains);
        $this->assertFalse($user->articles->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }

    public function testContainsKeyNonExistentIndexByManyToMany()
    {
        $user = $this->_em->find(CmsUser::class, $this->userId2);


        $queryCount = $this->getCurrentQueryCount();

        $contains = $user->groups->containsKey("NonExistentTopic");

        $this->assertFalse($contains);
        $this->assertFalse($user->groups->isInitialized());
        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }

    private function loadFixture()
    {
        $user1 = new CmsUser();
        $user1->username = "beberlei";
        $user1->name = "Benjamin";
        $user1->status = "active";

        $user2 = new CmsUser();
        $user2->username = "jwage";
        $user2->name = "Jonathan";
        $user2->status = "active";

        $user3 = new CmsUser();
        $user3->username = "romanb";
        $user3->name = "Roman";
        $user3->status = "active";

        $user4 = new CmsUser();
        $user4->username = "gblanco";
        $user4->name = "Guilherme";
        $user4->status = "active";

        $this->_em->persist($user1);
        $this->_em->persist($user2);
        $this->_em->persist($user3);
        $this->_em->persist($user4);

        $group1 = new CmsGroup();
        $group1->name = "Test1";

        $group2 = new CmsGroup();
        $group2->name = "Test2";

        $group3 = new CmsGroup();
        $group3->name = "Test3";

        $user1->addGroup($group1);
        $user1->addGroup($group2);
        $user1->addGroup($group3);

        $user2->addGroup($group1);
        $user3->addGroup($group1);
        $user4->addGroup($group1);

        $this->_em->persist($group1);
        $this->_em->persist($group2);
        $this->_em->persist($group3);

        $article1 = new CmsArticle();
        $article1->topic = "Test1";
        $article1->text = "Test1";
        $article1->setAuthor($user1);

        $article2 = new CmsArticle();
        $article2->topic = "Test2";
        $article2->text = "Test2";
        $article2->setAuthor($user1);

        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $phonenumber1 = new CmsPhonenumber();
        $phonenumber1->phonenumber = '12345';

        $phonenumber2 = new CmsPhonenumber();
        $phonenumber2->phonenumber = '67890';

        $this->_em->persist($phonenumber1);
        $this->_em->persist($phonenumber2);

        $user1->addPhonenumber($phonenumber1);

        // DDC-2504
        $otherClass = new DDC2504OtherClass();
        $childClass1 = new DDC2504ChildClass();
        $childClass2 = new DDC2504ChildClass();

        $childClass1->other = $otherClass;
        $childClass2->other = $otherClass;

        $otherClass->childClasses[] = $childClass1;
        $otherClass->childClasses[] = $childClass2;

        $this->_em->persist($childClass1);
        $this->_em->persist($childClass2);
        $this->_em->persist($otherClass);

        $this->_em->flush();
        $this->_em->clear();

        $this->articleId = $article1->id;
        $this->userId = $user1->getId();
        $this->userId2 = $user2->getId();
        $this->groupId = $group1->id;
        $this->ddc2504OtherClassId = $otherClass->id;
        $this->ddc2504ChildClassId = $childClass1->id;

        $this->username = $user1->username;
        $this->groupname = $group1->name;
        $this->topic = $article1->topic;
        $this->phonenumber = $phonenumber1->phonenumber;

    }

    /**
     * @group DDC-3343
     */
    public function testRemoveManagedElementFromOneToManyExtraLazyCollectionIsNoOp()
    {
        list($userId, $tweetId) = $this->loadTweetFixture();

        /* @var $user User */
        $user = $this->_em->find(User::class, $userId);

        $user->tweets->removeElement($this->_em->find(Tweet::class, $tweetId));

        $this->_em->clear();

        /* @var $user User */
        $user = $this->_em->find(User::class, $userId);

        $this->assertCount(1, $user->tweets, 'Element was not removed - need to update the owning side first');
    }

    /**
     * @group DDC-3343
     */
    public function testRemoveManagedElementFromOneToManyExtraLazyCollectionWithoutDeletingTheTargetEntityEntryIsNoOp()
    {
        list($userId, $tweetId) = $this->loadTweetFixture();

        /* @var $user User */
        $user  = $this->_em->find(User::class, $userId);
        $tweet = $this->_em->find(Tweet::class, $tweetId);

        $user->tweets->removeElement($tweet);

        $this->_em->clear();

        /* @var $tweet Tweet */
        $tweet = $this->_em->find(Tweet::class, $tweetId);
        $this->assertInstanceOf(
            Tweet::class,
            $tweet,
            'Even though the collection is extra lazy, the tweet should not have been deleted'
        );

        $this->assertInstanceOf(
            User::class,
            $tweet->author,
            'Tweet author link has not been removed - need to update the owning side first'
        );
    }

    /**
     * @group DDC-3343
     */
    public function testRemovingManagedLazyProxyFromExtraLazyOneToManyDoesRemoveTheAssociationButNotTheEntity()
    {
        list($userId, $tweetId) = $this->loadTweetFixture();

        /* @var $user User */
        $user  = $this->_em->find(User::class, $userId);
        $tweet = $this->_em->getReference(Tweet::class, $tweetId);

        $user->tweets->removeElement($this->_em->getReference(Tweet::class, $tweetId));

        $this->_em->clear();

        /* @var $tweet Tweet */
        $tweet = $this->_em->find(Tweet::class, $tweet->id);
        $this->assertInstanceOf(
            Tweet::class,
            $tweet,
            'Even though the collection is extra lazy, the tweet should not have been deleted'
        );

        $this->assertInstanceOf(User::class, $tweet->author);

        /* @var $user User */
        $user = $this->_em->find(User::class, $userId);

        $this->assertCount(1, $user->tweets, 'Element was not removed - need to update the owning side first');
    }

    /**
     * @group DDC-3343
     */
    public function testRemoveOrphanedManagedElementFromOneToManyExtraLazyCollection()
    {
        list($userId, $userListId) = $this->loadUserListFixture();

        /* @var $user User */
        $user = $this->_em->find(User::class, $userId);

        $user->userLists->removeElement($this->_em->find(UserList::class, $userListId));

        $this->_em->clear();

        /* @var $user User */
        $user = $this->_em->find(User::class, $userId);

        $this->assertCount(0, $user->userLists, 'Element was removed from association due to orphan removal');
        $this->assertNull(
            $this->_em->find(UserList::class, $userListId),
            'Element was deleted due to orphan removal'
        );
    }

    /**
     * @group DDC-3343
     */
    public function testRemoveOrphanedUnManagedElementFromOneToManyExtraLazyCollection()
    {
        list($userId, $userListId) = $this->loadUserListFixture();

        /* @var $user User */
        $user = $this->_em->find(User::class, $userId);

        $user->userLists->removeElement(new UserList());

        $this->_em->clear();

        /* @var $userList UserList */
        $userList = $this->_em->find(UserList::class, $userListId);
        $this->assertInstanceOf(
            UserList::class,
            $userList,
            'Even though the collection is extra lazy + orphan removal, the user list should not have been deleted'
        );

        $this->assertInstanceOf(
            User::class,
            $userList->owner,
            'User list to owner link has not been removed'
        );
    }

    /**
     * @group DDC-3343
     */
    public function testRemoveOrphanedManagedLazyProxyFromExtraLazyOneToMany()
    {
        list($userId, $userListId) = $this->loadUserListFixture();

        /* @var $user User */
        $user = $this->_em->find(User::class, $userId);

        $user->userLists->removeElement($this->_em->getReference(UserList::class, $userListId));

        $this->_em->clear();

        /* @var $user User */
        $user = $this->_em->find(User::class, $userId);

        $this->assertCount(0, $user->userLists, 'Element was removed from association due to orphan removal');
        $this->assertNull(
            $this->_em->find(UserList::class, $userListId),
            'Element was deleted due to orphan removal'
        );
    }

    /**
     * @return int[] ordered tuple: user id and tweet id
     */
    private function loadTweetFixture()
    {
        $user  = new User();
        $tweet = new Tweet();

        $user->name     = 'ocramius';
        $tweet->content = 'The cat is on the table';

        $user->addTweet($tweet);

        $this->_em->persist($user);
        $this->_em->persist($tweet);
        $this->_em->flush();
        $this->_em->clear();

        return [$user->id, $tweet->id];
    }

    /**
     * @return int[] ordered tuple: user id and user list id
     */
    private function loadUserListFixture()
    {
        $user     = new User();
        $userList = new UserList();

        $user->name     = 'ocramius';
        $userList->listName = 'PHP Developers to follow closely';

        $user->addUserList($userList);

        $this->_em->persist($user);
        $this->_em->persist($userList);
        $this->_em->flush();
        $this->_em->clear();

        return [$user->id, $userList->id];
    }
}
