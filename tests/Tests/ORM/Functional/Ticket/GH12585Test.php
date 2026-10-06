<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\PersistentCollection;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;

use function sprintf;

final class GH12585Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();

        $user           = new CmsUser();
        $user->username = 'jwage';
        $user->name     = 'Jonathan';
        $user->status   = 'developer';

        $phonenumber              = new CmsPhonenumber();
        $phonenumber->phonenumber = '12345';
        $user->addPhonenumber($phonenumber);

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testRepeatedSelectAliasKeepsCollectionFetchJoined(): void
    {
        $users = $this->_em
            ->createQuery('SELECT u, p, p FROM ' . CmsUser::class . ' u LEFT JOIN u.phonenumbers p')
            ->getResult();

        self::assertCount(1, $users);
        self::assertInstanceOf(PersistentCollection::class, $users[0]->phonenumbers);
        self::assertTrue($users[0]->phonenumbers->isInitialized());
        self::assertCount(1, $users[0]->phonenumbers);
    }

    public function testRepeatedSelectAliasIsDropped(): void
    {
        $dql = 'SELECT u, p%s FROM ' . CmsUser::class . ' u LEFT JOIN u.phonenumbers p';

        self::assertSame(
            $this->_em->createQuery(sprintf($dql, ''))->getSQL(),
            $this->_em->createQuery(sprintf($dql, ', p'))->getSQL(),
        );
    }

    public function testRepeatedSelectAliasInMixedResult(): void
    {
        $result = $this->_em
            ->createQuery('SELECT u, p, p, UPPER(u.name) AS nm FROM ' . CmsUser::class . ' u LEFT JOIN u.phonenumbers p')
            ->getResult();

        self::assertCount(1, $result);
        self::assertSame('JONATHAN', $result[0]['nm']);
        self::assertTrue($result[0][0]->phonenumbers->isInitialized());
        self::assertCount(1, $result[0][0]->phonenumbers);
    }
}
