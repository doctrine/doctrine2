<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

class UnitOfWorkLifecycleTest extends OrmFunctionalTestCase
{
    use VerifyDeprecations;

    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();
    }

    public function testScheduleInsertManaged(): void
    {
        $user           = new CmsUser();
        $user->username = 'beberlei';
        $user->name     = 'Benjamin';
        $user->status   = 'active';
        $this->_em->persist($user);
        $this->_em->flush();

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage('A managed+dirty entity Doctrine\Tests\Models\CMS\CmsUser');

        $this->_em->getUnitOfWork()->scheduleForInsert($user);
    }

    public function testScheduleInsertDeleted(): void
    {
        $user           = new CmsUser();
        $user->username = 'beberlei';
        $user->name     = 'Benjamin';
        $user->status   = 'active';
        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->remove($user);

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage('Removed entity Doctrine\Tests\Models\CMS\CmsUser');

        $this->_em->getUnitOfWork()->scheduleForInsert($user);
    }

    #[IgnoreDeprecations]
    public function testRemoveStillSetsIdentifierNullByDefault(): void
    {
        $user           = new CmsUser();
        $user->username = 'beberlei';
        $user->name     = 'Benjamin';
        $user->status   = 'active';
        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->remove($user);

        // The deprecation fires only when the old nulling behavior actually
        // executes on a real removal, not merely from constructing an
        // EntityManager/UnitOfWork - matching how every other deprecation in
        // this codebase only fires when the deprecated code path runs.
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12578');

        $this->_em->flush();

        self::assertNull($user->id);
    }

    public function testRemoveDoesNotSetIdentifierNullWhenFlagIsDisabled(): void
    {
        $this->_em->getConfiguration()->setOnRemoveEntitySetIdentifierNull(false);

        $user           = new CmsUser();
        $user->username = 'beberlei';
        $user->name     = 'Benjamin';
        $user->status   = 'active';
        $this->_em->persist($user);
        $this->_em->flush();

        $originalId = $user->id;
        self::assertNotNull($originalId);

        $this->_em->remove($user);

        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12578');

        $this->_em->flush();

        self::assertSame($originalId, $user->id);
    }

    public function testScheduleInsertTwice(): void
    {
        $user           = new CmsUser();
        $user->username = 'beberlei';
        $user->name     = 'Benjamin';
        $user->status   = 'active';

        $this->_em->getUnitOfWork()->scheduleForInsert($user);

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage('Entity Doctrine\Tests\Models\CMS\CmsUser');

        $this->_em->getUnitOfWork()->scheduleForInsert($user);
    }

    public function testAddToIdentityMapWithoutIdentity(): void
    {
        $user = new CmsUser();

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage("The given entity of type 'Doctrine\Tests\Models\CMS\CmsUser' (Doctrine\Tests\Models\CMS\CmsUser@");

        $this->_em->getUnitOfWork()->registerManaged($user, [], []);
    }

    public function testMarkReadOnlyNonManaged(): void
    {
        $user = new CmsUser();

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage('Only managed entities can be marked or checked as read only. But Doctrine\Tests\Models\CMS\CmsUser@');

        $this->_em->getUnitOfWork()->markReadOnly($user);
    }
}
