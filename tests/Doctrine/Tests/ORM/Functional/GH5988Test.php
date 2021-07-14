<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type as DBALType;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\DbalTypes\CustomIdObject;
use Doctrine\Tests\OrmFunctionalTestCase;

use function str_replace;

/**
 * Functional tests for the Class Table Inheritance mapping strategy with custom id object types.
 *
 * @group GH5988
 */
final class GH5988Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! DBALType::hasType(GH5988CustomIdObjectHashType::class)) {
            DBALType::addType(GH5988CustomIdObjectHashType::class, GH5988CustomIdObjectHashType::class);
        }

        $this->setUpEntitySchema([GH5988CustomIdObjectTypeParent::class, GH5988CustomIdObjectTypeChild::class]);
    }

    public function testDelete(): void
    {
        $object = new GH5988CustomIdObjectTypeChild(new CustomIdObject('foo'), 'Test');

        $this->_em->persist($object);
        $this->_em->flush();

        $id = $object->id;

        $object2 = $this->_em->find(GH5988CustomIdObjectTypeChild::class, $id);

        $this->_em->remove($object2);
        $this->_em->flush();

        self::assertNull($this->_em->find(GH5988CustomIdObjectTypeChild::class, $id));
    }
}


class GH5988CustomIdObjectHashType extends DBALType
{
    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        return $value->id . '_test';
    }

    /**
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        return new CustomIdObject(str_replace('_test', '', $value));
    }

    /**
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getVarcharTypeDeclarationSQL($fieldDeclaration);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::class;
    }
}

/**
 * @Entity
 * @Table
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="type", type="string")
 * @DiscriminatorMap({"child" = GH5988CustomIdObjectTypeChild::class})
 */
abstract class GH5988CustomIdObjectTypeParent
{
    /**
     * @Id
     * @Column(type="Doctrine\Tests\ORM\Functional\GH5988CustomIdObjectHashType")
     * @var CustomIdObject
     */
    public $id;
}


/**
 * @Entity
 * @Table
 */
class GH5988CustomIdObjectTypeChild extends GH5988CustomIdObjectTypeParent
{
    /** @var string */
    public $name;

    public function __construct(CustomIdObject $id, string $name)
    {
        $this->id   = $id;
        $this->name = $name;
    }
}
