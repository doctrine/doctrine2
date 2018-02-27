<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\Models;

/**
 * @author asm89
 *
 * @group non-cacheable
 * @group DDC-1301
 */
class DDC1301Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    private $userId;

    public function setUp()
    {
        $this->useModelSet('legacy');
        parent::setUp();

        $class = $this->_em->getClassMetadata(Models\Legacy\LegacyUser::class);
        $class->associationMappings['_articles']['fetch'] = ClassMetadata::FETCH_EXTRA_LAZY;
        $class->associationMappings['_references']['fetch'] = ClassMetadata::FETCH_EXTRA_LAZY;
        $class->associationMappings['_cars']['fetch'] = ClassMetadata::FETCH_EXTRA_LAZY;

        $this->loadFixture();
    }

    public function tearDown()
    {
        parent::tearDown();

        $class = $this->_em->getClassMetadata(Models\Legacy\LegacyUser::class);
        $class->associationMappings['_articles']['fetch'] = ClassMetadata::FETCH_LAZY;
        $class->associationMappings['_references']['fetch'] = ClassMetadata::FETCH_LAZY;
        $class->associationMappings['_cars']['fetch'] = ClassMetadata::FETCH_LAZY;
    }

    public function testCountNotInitializesLegacyCollection()
    {
        $user = $this->_em->find(Models\Legacy\LegacyUser::class, $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->_articles->isInitialized());
        $this->assertEquals(2, count($user->_articles));
        $this->assertFalse($user->_articles->isInitialized());

        foreach ($user->_articles AS $article) { }

        $this->assertEquals($queryCount + 2, $this->getCurrentQueryCount(), "Expecting two queries to be fired for count, then iteration.");
    }

    public function testCountNotInitializesLegacyCollectionWithForeignIdentifier()
    {
        $user = $this->_em->find(Models\Legacy\LegacyUser::class, $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->_references->isInitialized());
        $this->assertEquals(2, count($user->_references));
        $this->assertFalse($user->_references->isInitialized());

        foreach ($user->_references AS $reference) { }

        $this->assertEquals($queryCount + 2, $this->getCurrentQueryCount(), "Expecting two queries to be fired for count, then iteration.");
    }

    public function testCountNotInitializesLegacyManyToManyCollection()
    {
        $user = $this->_em->find(Models\Legacy\LegacyUser::class, $this->userId);
        $queryCount = $this->getCurrentQueryCount();

        $this->assertFalse($user->_cars->isInitialized());
        $this->assertEquals(3, count($user->_cars));
        $this->assertFalse($user->_cars->isInitialized());

        foreach ($user->_cars AS $reference) { }

        $this->assertEquals($queryCount + 2, $this->getCurrentQueryCount(), "Expecting two queries to be fired for count, then iteration.");
    }

    public function loadFixture()
    {
        $user1 = new Models\Legacy\LegacyUser();
        $user1->_username = "beberlei";
        $user1->_name = "Benjamin";
        $user1->_status = "active";

        $user2 = new Models\Legacy\LegacyUser();
        $user2->_username = "jwage";
        $user2->_name = "Jonathan";
        $user2->_status = "active";

        $user3 = new Models\Legacy\LegacyUser();
        $user3->_username = "romanb";
        $user3->_name = "Roman";
        $user3->_status = "active";

        $this->_em->persist($user1);
        $this->_em->persist($user2);
        $this->_em->persist($user3);

        $article1 = new Models\Legacy\LegacyArticle();
        $article1->_topic = "Test";
        $article1->_text = "Test";
        $article1->setAuthor($user1);

        $article2 = new Models\Legacy\LegacyArticle();
        $article2->_topic = "Test";
        $article2->_text = "Test";
        $article2->setAuthor($user1);

        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $car1 = new Models\Legacy\LegacyCar();
        $car1->_description = "Test1";

        $car2 = new Models\Legacy\LegacyCar();
        $car2->_description = "Test2";

        $car3 = new Models\Legacy\LegacyCar();
        $car3->_description = "Test3";

        $user1->addCar($car1);
        $user1->addCar($car2);
        $user1->addCar($car3);

        $user2->addCar($car1);
        $user3->addCar($car1);

        $this->_em->persist($car1);
        $this->_em->persist($car2);
        $this->_em->persist($car3);

        $this->_em->flush();

        $detail1 = new Models\Legacy\LegacyUserReference($user1, $user2, "foo");
        $detail2 = new Models\Legacy\LegacyUserReference($user1, $user3, "bar");

        $this->_em->persist($detail1);
        $this->_em->persist($detail2);

        $this->_em->flush();
        $this->_em->clear();

        $this->userId = $user1->getId();
    }
}
