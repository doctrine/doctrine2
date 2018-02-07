<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\Models\Company\CompanyPerson;

class DDC163Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    protected function setUp()
    {
        $this->useModelSet('company');
        parent::setUp();
    }

    /**
     * @group DDC-163
     */
    public function testQueryWithOrConditionUsingTwoRelationOnSameEntity()
    {
        $p1 = new CompanyPerson;
        $p1->setName('p1');

        $p2 = new CompanyPerson;
        $p2->setName('p2');

        $p3 = new CompanyPerson;
        $p3->setName('p3');

        $p4 = new CompanyPerson;
        $p4->setName('p4');

        $p1->setSpouse($p3);
        $p1->addFriend($p2);
        $p2->addFriend($p3);

        $p3->addFriend($p4);

        $this->em->persist($p1);
        $this->em->persist($p2);
        $this->em->persist($p3);
        $this->em->persist($p4);

        $this->em->flush();
        $this->em->clear();

        $dql = 'SELECT PARTIAL person.{id,name}, PARTIAL spouse.{id,name}, PARTIAL friend.{id,name}
            FROM  Doctrine\Tests\Models\Company\CompanyPerson person
            LEFT JOIN person.spouse spouse
            LEFT JOIN person.friends friend
            LEFT JOIN spouse.friends spouse_friend
            LEFT JOIN friend.friends friend_friend
            WHERE person.name=:name AND (spouse_friend.name=:name2 OR friend_friend.name=:name2)';

        $q = $this->em->createQuery($dql);
        $q->setParameter('name', "p1");
        $q->setParameter('name2', "p4");
        $result = $q->getScalarResult();

        self::assertEquals('p3', $result[0]['spouse_name']);
        self::assertEquals('p1', $result[0]['person_name']);
        self::assertEquals('p2', $result[0]['friend_name']);
    }
}
