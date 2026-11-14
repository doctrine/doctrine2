<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\DbalTypes\CustomIdObject;
use Doctrine\Tests\DbalTypes\CustomIdObjectType;
use Doctrine\Tests\Models\CustomType\CustomIdObjectTypeChild;
use Doctrine\Tests\Models\CustomType\CustomIdObjectTypeParent;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

class CustomIdObjectTypeTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        self::registerDbalType(CustomIdObjectType::NAME, CustomIdObjectType::class);

        $this->useModelSet('custom_id_object_type');

        parent::setUp();
    }

    public function testFindByCustomIdObject(): void
    {
        $parent = new CustomIdObjectTypeParent(new CustomIdObject('foo'));

        $this->_em->persist($parent);
        $this->_em->flush();

        $result = $this->_em->find(CustomIdObjectTypeParent::class, $parent->id);

        self::assertSame($parent, $result);
    }

    #[Group('DDC-3622')]
    #[Group('1336')]
    public function testFetchJoinCustomIdObject(): void
    {
        $parent = new CustomIdObjectTypeParent(new CustomIdObject('foo'));

        $parent->children->add(new CustomIdObjectTypeChild(new CustomIdObject('bar'), $parent));

        $this->_em->persist($parent);
        $this->_em->flush();

        $result = $this
            ->_em
            ->createQuery(
                'SELECT parent, children FROM '
                . CustomIdObjectTypeParent::class
                . ' parent LEFT JOIN parent.children children',
            )
            ->getResult();

        self::assertCount(1, $result);
        self::assertSame($parent, $result[0]);
    }

    #[Group('DDC-3622')]
    #[Group('1336')]
    public function testFetchJoinWhereCustomIdObject(): void
    {
        $parent = new CustomIdObjectTypeParent(new CustomIdObject('foo'));

        $parent->children->add(new CustomIdObjectTypeChild(new CustomIdObject('bar'), $parent));

        $this->_em->persist($parent);
        $this->_em->flush();

        // note: hydration is willingly broken in this example:
        $result = $this
            ->_em
            ->createQuery(
                'SELECT parent, children FROM '
                . CustomIdObjectTypeParent::class
                . ' parent LEFT JOIN parent.children children '
                . 'WHERE children.id = ?1',
            )
            ->setParameter(1, $parent->children->first()->id)
            ->getResult();

        self::assertCount(1, $result);
        self::assertSame($parent, $result[0]);
    }
}
