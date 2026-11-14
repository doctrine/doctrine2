<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_key_exists;
use function array_keys;

class PartialObjectsTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();
    }

    public function testPartialObjectProxyLoadedChangeset(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Alice';
        $user->username = 'alice';
        $user->status   = 'developer';

        $address          = new CmsAddress();
        $address->country = 'Germany';
        $address->city    = 'Berlin';
        $address->zip     = '12345';

        $user->address = $address; // inverse side
        $address->user = $user; // owning side!

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $dql  = 'SELECT PARTIAL u.{id, name} FROM ' . CmsUser::class . ' u WHERE u.username = ?1';
        $user = $this->_em->createQuery($dql)->setParameter(1, 'alice')->getSingleResult();

        $partialKeys = array_keys($this->_em->getUnitOfWork()->getOriginalEntityData($user));
        $this->assertContains('id', $partialKeys);
        $this->assertContains('name', $partialKeys);
        $this->assertNotContains('username', $partialKeys);
        $this->assertNotContains('status', $partialKeys);

        // Modify the partially-loaded field before triggering full initialization.
        $user->name = 'Bob';
        // Accessing an uninitialized field triggers the lazy ghost initializer.
        // The previously set 'name' must NOT be overwritten by the full DB load.
        $user->username = 'bob';

        $this->assertEquals('Bob', $user->name);

        // After full initialization originalEntityData must include all fields.
        $fullKeys = array_keys($this->_em->getUnitOfWork()->getOriginalEntityData($user));
        $this->assertContains('username', $fullKeys);
        $this->assertContains('status', $fullKeys);
        // The snapshot for 'name' must still reflect the original DB value ('Alice'),
        // not the user-modified value, so the changeset is computed correctly.
        $originalData = $this->_em->getUnitOfWork()->getOriginalEntityData($user);
        $this->assertTrue(array_key_exists('name', $originalData));
        $this->assertEquals('Alice', $originalData['name']);
    }

    public function testPartialObjectLazyInitDoesNotOverwriteChangedProperty(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Alice';
        $user->username = 'alice';
        $user->status   = 'developer';

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $dql  = 'SELECT PARTIAL u.{id, name} FROM ' . CmsUser::class . ' u WHERE u.username = ?1';
        $user = $this->_em->createQuery($dql)->setParameter(1, 'alice')->getSingleResult();

        // Change the partially-loaded property before lazy init fires.
        $user->name = 'Bob';

        // Writing to a loaded property must NOT trigger lazy init —
        // 'name' was already loaded by the partial query, so the ghost
        // should remain uninitialized at this point.
        self::assertTrue($this->_em->getUnitOfWork()->isUninitializedObject($user));

        // Reading a non-loaded property triggers the lazy ghost initializer.
        // The full DB load must NOT overwrite the in-memory change to 'name'.
        $username = $user->username;

        self::assertEquals('Bob', $user->name);
        self::assertEquals('alice', $username);
    }
}
