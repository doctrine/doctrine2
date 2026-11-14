<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\DbalTypes\NegativeToPositiveType;
use Doctrine\Tests\DbalTypes\UpperCaseStringType;
use Doctrine\Tests\Models\CustomType\CustomTypeChild;
use Doctrine\Tests\Models\CustomType\CustomTypeParent;
use Doctrine\Tests\Models\CustomType\CustomTypeUpperCase;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

class TypeValueSqlTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        self::registerDbalType(UpperCaseStringType::NAME, UpperCaseStringType::class);

        self::registerDbalType(NegativeToPositiveType::NAME, NegativeToPositiveType::class);

        $this->useModelSet('customtype');

        parent::setUp();
    }

    public function testUpperCaseStringType(): void
    {
        $entity                  = new CustomTypeUpperCase();
        $entity->lowerCaseString = 'foo';

        $this->_em->persist($entity);
        $this->_em->flush();

        $id = $entity->id;

        $this->_em->clear();

        $entity = $this->_em->find(CustomTypeUpperCase::class, $id);

        self::assertEquals('foo', $entity->lowerCaseString, 'Entity holds lowercase string');
        self::assertEquals('FOO', $this->_em->getConnection()->fetchOne('select lowerCaseString from customtype_uppercases where id=' . $entity->id . ''), 'Database holds uppercase string');
    }

    #[Group('DDC-1642')]
    public function testUpperCaseStringTypeWhenColumnNameIsDefined(): void
    {
        $entity                       = new CustomTypeUpperCase();
        $entity->lowerCaseString      = 'Some Value';
        $entity->namedLowerCaseString = 'foo';

        $this->_em->persist($entity);
        $this->_em->flush();

        $id = $entity->id;

        $this->_em->clear();

        $entity = $this->_em->find(CustomTypeUpperCase::class, $id);
        self::assertEquals('foo', $entity->namedLowerCaseString, 'Entity holds lowercase string');
        self::assertEquals('FOO', $this->_em->getConnection()->fetchOne('select named_lower_case_string from customtype_uppercases where id=' . $entity->id . ''), 'Database holds uppercase string');

        $entity->namedLowerCaseString = 'bar';

        $this->_em->persist($entity);
        $this->_em->flush();

        $id = $entity->id;

        $this->_em->clear();

        $entity = $this->_em->find(CustomTypeUpperCase::class, $id);
        self::assertEquals('bar', $entity->namedLowerCaseString, 'Entity holds lowercase string');
        self::assertEquals('BAR', $this->_em->getConnection()->fetchOne('select named_lower_case_string from customtype_uppercases where id=' . $entity->id . ''), 'Database holds uppercase string');
    }

    public function testTypeValueSqlWithAssociations(): void
    {
        $parent                = new CustomTypeParent();
        $parent->customInteger = -1;
        $parent->child         = new CustomTypeChild();

        $friend1 = new CustomTypeParent();
        $friend2 = new CustomTypeParent();

        $parent->addMyFriend($friend1);
        $parent->addMyFriend($friend2);

        $this->_em->persist($parent);
        $this->_em->persist($friend1);
        $this->_em->persist($friend2);
        $this->_em->flush();

        $parentId = $parent->id;

        $this->_em->clear();

        $entity = $this->_em->find(CustomTypeParent::class, $parentId);

        self::assertTrue($entity->customInteger < 0, 'Fetched customInteger negative');
        self::assertEquals(1, $this->_em->getConnection()->fetchOne('select customInteger from customtype_parents where id=' . $entity->id . ''), 'Database has stored customInteger positive');

        self::assertNotNull($parent->child, 'Child attached');
        self::assertCount(2, $entity->getMyFriends(), '2 friends attached');
    }

    public function testSelectDQL(): void
    {
        $parent                = new CustomTypeParent();
        $parent->customInteger = -1;
        $parent->child         = new CustomTypeChild();

        $this->_em->persist($parent);
        $this->_em->flush();

        $parentId = $parent->id;

        $this->_em->clear();

        $query = $this->_em->createQuery('SELECT p, p.customInteger, c from Doctrine\Tests\Models\CustomType\CustomTypeParent p JOIN p.child c where p.id = ' . $parentId);

        $result = $query->getResult();

        self::assertCount(1, $result);
        self::assertInstanceOf(CustomTypeParent::class, $result[0][0]);
        self::assertEquals(-1, $result[0][0]->customInteger);

        self::assertEquals(-1, $result[0]['customInteger']);

        self::assertEquals('foo', $result[0][0]->child->lowerCaseString);
    }
}
