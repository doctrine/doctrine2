<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\DBAL\Types\Type as DBALType;
use Doctrine\Tests\DbalTypes\NegativeToPositiveType;
use Doctrine\Tests\DbalTypes\UpperCaseStringType;
use Doctrine\Tests\Models\CustomType\CustomTypeChild;
use Doctrine\Tests\Models\CustomType\CustomTypeParent;
use Doctrine\Tests\Models\CustomType\CustomTypeUpperCase;
use Doctrine\Tests\OrmFunctionalTestCase;

class TypeValueSqlTest extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        if (DBALType::hasType(UpperCaseStringType::NAME)) {
            DBALType::overrideType(UpperCaseStringType::NAME, UpperCaseStringType::class);
        } else {
            DBALType::addType(UpperCaseStringType::NAME, UpperCaseStringType::class);
        }

        if (DBALType::hasType(NegativeToPositiveType::NAME)) {
            DBALType::overrideType(NegativeToPositiveType::NAME, NegativeToPositiveType::class);
        } else {
            DBALType::addType(NegativeToPositiveType::NAME, NegativeToPositiveType::class);
        }

        $this->useModelSet('customtype');
        parent::setUp();
    }

    public function testUpperCaseStringType()
    {
        $entity = new CustomTypeUpperCase();
        $entity->lowerCaseString = 'foo';

        $this->_em->persist($entity);
        $this->_em->flush();

        $id = $entity->id;

        $this->_em->clear();

        $entity = $this->_em->find('\Doctrine\Tests\Models\CustomType\CustomTypeUpperCase', $id);

        $this->assertEquals('foo', $entity->lowerCaseString, 'Entity holds lowercase string');
        $this->assertEquals('FOO', $this->_em->getConnection()->fetchOne("select lowerCaseString from customtype_uppercases where id=".$entity->id.""), 'Database holds uppercase string');
    }

    /**
     * @group DDC-1642
     */
    public function testUpperCaseStringTypeWhenColumnNameIsDefined()
    {

        $entity = new CustomTypeUpperCase();
        $entity->lowerCaseString        = 'Some Value';
        $entity->namedLowerCaseString   = 'foo';

        $this->_em->persist($entity);
        $this->_em->flush();

        $id = $entity->id;

        $this->_em->clear();

        $entity = $this->_em->find('\Doctrine\Tests\Models\CustomType\CustomTypeUpperCase', $id);
        $this->assertEquals('foo', $entity->namedLowerCaseString, 'Entity holds lowercase string');
        $this->assertEquals('FOO', $this->_em->getConnection()->fetchOne("select named_lower_case_string from customtype_uppercases where id=".$entity->id.""), 'Database holds uppercase string');


        $entity->namedLowerCaseString   = 'bar';

        $this->_em->persist($entity);
        $this->_em->flush();

        $id = $entity->id;

        $this->_em->clear();


        $entity = $this->_em->find('\Doctrine\Tests\Models\CustomType\CustomTypeUpperCase', $id);
        $this->assertEquals('bar', $entity->namedLowerCaseString, 'Entity holds lowercase string');
        $this->assertEquals('BAR', $this->_em->getConnection()->fetchOne("select named_lower_case_string from customtype_uppercases where id=".$entity->id.""), 'Database holds uppercase string');
    }

    public function testTypeValueSqlWithAssociations()
    {
        $parent = new CustomTypeParent();
        $parent->customInteger = -1;
        $parent->child = new CustomTypeChild();

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

        $this->assertTrue($entity->customInteger < 0, 'Fetched customInteger negative');
        $this->assertEquals(1, $this->_em->getConnection()->fetchOne("select customInteger from customtype_parents where id=".$entity->id.""), 'Database has stored customInteger positive');

        $this->assertNotNull($parent->child, 'Child attached');
        $this->assertCount(2, $entity->getMyFriends(), '2 friends attached');
    }

    public function testSelectDQL()
    {
        $parent = new CustomTypeParent();
        $parent->customInteger = -1;
        $parent->child = new CustomTypeChild();

        $this->_em->persist($parent);
        $this->_em->flush();

        $parentId = $parent->id;

        $this->_em->clear();

        $query = $this->_em->createQuery("SELECT p, p.customInteger, c from Doctrine\Tests\Models\CustomType\CustomTypeParent p JOIN p.child c where p.id = " . $parentId);

        $result = $query->getResult();

        $this->assertEquals(1, count($result));
        $this->assertInstanceOf(CustomTypeParent::class, $result[0][0]);
        $this->assertEquals(-1, $result[0][0]->customInteger);

        $this->assertEquals(-1, $result[0]['customInteger']);

        $this->assertEquals('foo', $result[0][0]->child->lowerCaseString);
    }
}
