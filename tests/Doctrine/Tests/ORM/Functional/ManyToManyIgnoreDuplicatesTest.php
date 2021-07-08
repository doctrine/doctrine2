<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\CMS\CmsTag;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;

class ManyToManyIgnoreDuplicatesTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('cms');
        parent::setUp();
    }

    public function testDuplicateInsertionDoesNotResultInException()
    {
        $user           = new CmsUser();
        $user->name     = 'Maciej';
        $user->username = 'malarzm';
        $user->status   = 'developer';
        $this->_em->persist($user);

        $tag = new CmsTag();
        $tag->setName('A tag');
        $this->_em->persist($tag);
        $this->_em->flush();

        $concurrentEm = $this->getEntityManager();
        /** @var CmsUser $concurrentUser */
        $concurrentUser = $concurrentEm->find(CmsUser::class, $user->getId());
        $concurrentTag = $concurrentEm->find(CmsTag::class, $tag->getId());
        self::assertEmpty($concurrentUser->tags);

        $user->addTag($tag);
        $this->_em->flush();
        self::assertCount(1, $user->tags);

        $concurrentUser->addTag($concurrentTag);
        $concurrentEm->flush();
        self::assertCount(1, $concurrentUser->tags);

        $this->_em->clear();
        $refreshedUser = $this->_em->find(CmsUser::class, $user->getId());
        self::assertCount(1, $refreshedUser->tags);
        self::assertSame($tag->getName(), $refreshedUser->tags->first()->getName());
    }
}
