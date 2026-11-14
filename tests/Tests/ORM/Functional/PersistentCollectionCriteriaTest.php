<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\LazyCriteriaCollection;
use Doctrine\Tests\Models\Quote\Group;
use Doctrine\Tests\Models\Quote\User as QuoteUser;
use Doctrine\Tests\Models\Tweet\Tweet;
use Doctrine\Tests\Models\Tweet\User;
use Doctrine\Tests\Models\Tweet\User as TweetUser;
use Doctrine\Tests\Models\ValueConversionType\InversedManyToManyExtraLazyEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToManyExtraLazyEntity;
use Doctrine\Tests\OrmFunctionalTestCase;

class PersistentCollectionCriteriaTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('tweet');
        $this->useModelSet('quote');
        $this->useModelSet('vct_manytomany_extralazy');

        parent::setUp();
    }

    public function tearDown(): void
    {
        if ($this->_em) {
            $this->_em->getConfiguration()->setEntityNamespaces([]);
        }

        parent::tearDown();
    }

    public function loadTweetFixture(): void
    {
        $author       = new TweetUser();
        $author->name = 'ngal';
        $this->_em->persist($author);

        $tweet1          = new Tweet();
        $tweet1->content = 'Foo';
        $author->addTweet($tweet1);

        $tweet2          = new Tweet();
        $tweet2->content = 'Bar';
        $author->addTweet($tweet2);

        $this->_em->flush();

        unset($author, $tweet1, $tweet2);

        $this->_em->clear();
    }

    public function loadQuoteFixture(): void
    {
        $user       = new QuoteUser();
        $user->name = 'mgal';
        $this->_em->persist($user);

        $quote1 = new Group('quote1');
        $user->groups->add($quote1);

        $quote2 = new Group('quote2');
        $user->groups->add($quote2);

        $this->_em->flush();

        $this->_em->clear();
    }

    public function testCanCountWithoutLoadingPersistentCollection(): void
    {
        $this->loadTweetFixture();

        $repository = $this->_em->getRepository(User::class);

        $user   = $repository->findOneBy(['name' => 'ngal']);
        $tweets = $user->tweets->matching(Criteria::create());

        self::assertInstanceOf(LazyCriteriaCollection::class, $tweets);
        self::assertFalse($tweets->isInitialized());
        self::assertCount(2, $tweets);
        self::assertFalse($tweets->isInitialized());

        // Make sure it works with constraints
        $tweets = $user->tweets->matching(Criteria::create()->where(
            Criteria::expr()->eq('content', 'Foo'),
        ));

        self::assertInstanceOf(LazyCriteriaCollection::class, $tweets);
        self::assertFalse($tweets->isInitialized());
        self::assertCount(1, $tweets);
        self::assertFalse($tweets->isInitialized());
    }

    public function testCanHandleComplexTypesOnAssociation(): void
    {
        $parent      = new OwningManyToManyExtraLazyEntity();
        $parent->id2 = 'Alice';

        $this->_em->persist($parent);

        $child      = new InversedManyToManyExtraLazyEntity();
        $child->id1 = 'Bob';

        $this->_em->persist($child);

        $parent->associatedEntities->add($child);

        $this->_em->flush();
        $this->_em->clear();

        $parent = $this->_em->find(OwningManyToManyExtraLazyEntity::class, $parent->id2);

        $criteria = Criteria::create()->where(Criteria::expr()->eq('id1', 'Bob'));

        $result = $parent->associatedEntities->matching($criteria);

        $this->assertCount(1, $result);
        $this->assertEquals('Bob', $result[0]->id1);
    }
}
