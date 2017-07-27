<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\Models\Quote\Address;
use Doctrine\Tests\Models\Quote\Group;
use Doctrine\Tests\Models\Quote\Phone;
use Doctrine\Tests\Models\Quote\User;

/**
 * @group DDC-1845
 * @group DDC-1843
 */
class DDC1843Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();

        try {
            $this->schemaTool->createSchema(
                [
                $this->em->getClassMetadata(User::class),
                $this->em->getClassMetadata(Group::class),
                $this->em->getClassMetadata(Phone::class),
                $this->em->getClassMetadata(Address::class),
                ]
            );
        } catch(\Exception $e) {
        }
    }

    public function testCreateRetrieveUpdateDelete()
    {

        $e1 = new Group('Parent Bar 1');
        $e2 = new Group('Parent Foo 2');

        $this->em->persist($e1);
        $this->em->persist($e2);
        $this->em->flush();

        $e3 = new Group('Bar 3', $e1);
        $e4 = new Group('Foo 4', $e2);

        // Create
        $this->em->persist($e3);
        $this->em->persist($e4);
        $this->em->flush();
        $this->em->clear();

        $e1Id   = $e1->id;
        $e2Id   = $e2->id;
        $e3Id   = $e3->id;
        $e4Id   = $e4->id;

        // Retrieve
        $e1     = $this->em->find(Group::class, $e1Id);
        $e2     = $this->em->find(Group::class, $e2Id);
        $e3     = $this->em->find(Group::class, $e3Id);
        $e4     = $this->em->find(Group::class, $e4Id);

        self::assertInstanceOf(Group::class, $e1);
        self::assertInstanceOf(Group::class, $e2);
        self::assertInstanceOf(Group::class, $e3);
        self::assertInstanceOf(Group::class, $e4);

        self::assertEquals($e1Id, $e1->id);
        self::assertEquals($e2Id, $e2->id);
        self::assertEquals($e3Id, $e3->id);
        self::assertEquals($e4Id, $e4->id);


        self::assertEquals('Parent Bar 1', $e1->name);
        self::assertEquals('Parent Foo 2', $e2->name);
        self::assertEquals('Bar 3', $e3->name);
        self::assertEquals('Foo 4', $e4->name);

        $e1->name = 'Parent Bar 11';
        $e2->name = 'Parent Foo 22';
        $e3->name = 'Bar 33';
        $e4->name = 'Foo 44';

        // Update
        $this->em->persist($e1);
        $this->em->persist($e2);
        $this->em->persist($e3);
        $this->em->persist($e4);
        $this->em->flush();

        self::assertEquals('Parent Bar 11', $e1->name);
        self::assertEquals('Parent Foo 22', $e2->name);
        self::assertEquals('Bar 33', $e3->name);
        self::assertEquals('Foo 44', $e4->name);

        self::assertInstanceOf(Group::class, $e1);
        self::assertInstanceOf(Group::class, $e2);
        self::assertInstanceOf(Group::class, $e3);
        self::assertInstanceOf(Group::class, $e4);

        self::assertEquals($e1Id, $e1->id);
        self::assertEquals($e2Id, $e2->id);
        self::assertEquals($e3Id, $e3->id);
        self::assertEquals($e4Id, $e4->id);

        self::assertEquals('Parent Bar 11', $e1->name);
        self::assertEquals('Parent Foo 22', $e2->name);
        self::assertEquals('Bar 33', $e3->name);
        self::assertEquals('Foo 44', $e4->name);

        // Delete
        $this->em->remove($e4);
        $this->em->remove($e3);
        $this->em->remove($e2);
        $this->em->remove($e1);

        $this->em->flush();
        $this->em->clear();


        self::assertInstanceOf(Group::class, $e1);
        self::assertInstanceOf(Group::class, $e2);
        self::assertInstanceOf(Group::class, $e3);
        self::assertInstanceOf(Group::class, $e4);

        // Retrieve
        $e1     = $this->em->find(Group::class, $e1Id);
        $e2     = $this->em->find(Group::class, $e2Id);
        $e3     = $this->em->find(Group::class, $e3Id);
        $e4     = $this->em->find(Group::class, $e4Id);

        self::assertNull($e1);
        self::assertNull($e2);
        self::assertNull($e3);
        self::assertNull($e4);
    }

}
