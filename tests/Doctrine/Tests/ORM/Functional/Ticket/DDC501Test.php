<?php
declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\OrmFunctionalTestCase;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;

/**
 * ----------------- !! NOTE !! --------------------
 * To reproduce the manyToMany-Bug it's necessary
 * to cascade "merge" on cmUser::groups
 * -------------------------------------------------
 *
 * @PHP-Version 5.3.2
 * @PHPUnit-Version 3.4.11
 *
 * @author markus
 */
class DDC501Test extends OrmFunctionalTestCase
{
    protected function setUp()
    {
        $this->useModelSet('cms');
        parent::setUp();
    }

    public function testMergeUnitializedManyToManyAndOneToManyCollections()
    {
        // Create User
        $user = $this->createAndPersistUser();
        $this->em->flush();

        self::assertTrue($this->em->contains($user));
        $this->em->clear();
        self::assertFalse($this->em->contains($user));

        unset($user);

        // Reload User from DB *without* any associations (i.e. an uninitialized PersistantCollection)
        $userReloaded = $this->loadUserFromEntityManager();

        self::assertTrue($this->em->contains($userReloaded));
        $this->em->clear();
        self::assertFalse($this->em->contains($userReloaded));

        // freeze and unfreeze
        $userClone = unserialize(serialize($userReloaded));
        self::assertInstanceOf(CmsUser::class, $userClone);

        // detached user can't know about his phonenumbers
        self::assertEquals(0, count($userClone->getPhonenumbers()));
        self::assertFalse($userClone->getPhonenumbers()->isInitialized(), "User::phonenumbers should not be marked initialized.");

        // detached user can't know about his groups either
        self::assertEquals(0, count($userClone->getGroups()));
        self::assertFalse($userClone->getGroups()->isInitialized(), "User::groups should not be marked initialized.");

        // Merge back and flush
        $userClone = $this->em->merge($userClone);

        // Back in managed world I would expect to have my phonenumbers back but they aren't!
	// Remember I didn't touch (and probably didn't need) them at all while in detached mode.
        self::assertEquals(4, count($userClone->getPhonenumbers()), 'Phonenumbers are not available anymore');

        // This works fine as long as cmUser::groups doesn't cascade "merge"
        self::assertEquals(2, count($userClone->getGroups()));

        $this->em->flush();
        $this->em->clear();

        self::assertFalse($this->em->contains($userClone));

        // Reload user from DB
        $userFromEntityManager = $this->loadUserFromEntityManager();

        //Strange: Now the phonenumbers are back again
        self::assertEquals(4, count($userFromEntityManager->getPhonenumbers()));

        // This works fine as long as cmUser::groups doesn't cascade "merge"
        // Otherwise group memberships are physically deleted now!
        self::assertEquals(2, count($userClone->getGroups()));
    }

    protected function createAndPersistUser()
    {
        $user = new CmsUser();
        $user->name = 'Luka';
        $user->username = 'lukacho';
        $user->status = 'developer';

        foreach([1111,2222,3333,4444] as $number) {
            $phone = new CmsPhonenumber;
            $phone->phonenumber = $number;
            $user->addPhonenumber($phone);
        }

        foreach(['Moshers', 'Headbangers'] as $groupName) {
            $group = new CmsGroup;
            $group->setName($groupName);
            $user->addGroup($group);
        }

        $this->em->persist($user);

        return $user;
    }

    /**
     * @return Doctrine\Tests\Models\CMS\CmsUser
     */
    protected function loadUserFromEntityManager()
    {
        return $this->em
                ->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name like :name')
                ->setParameter('name', 'Luka')
                ->getSingleResult();
    }

}
