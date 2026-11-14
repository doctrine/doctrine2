<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

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
use PHPUnit\Framework\Attributes\Group;

use function str_replace;

/**
 * Functional tests for the Class Table Inheritance mapping strategy with custom id object types.
 */
#[Group('GH5988')]
final class GH5988Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(GH5988CustomIdObjectHashType::class, GH5988CustomIdObjectHashType::class);

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
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): string
    {
        return $value->id . '_test';
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): CustomIdObject
    {
        return new CustomIdObject(str_replace('_test', '', $value));
    }

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function getName(): string
    {
        return self::class;
    }
}

#[Table]
#[Entity]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'type', type: 'string')]
#[DiscriminatorMap(['child' => GH5988CustomIdObjectTypeChild::class])]
abstract class GH5988CustomIdObjectTypeParent
{
    /** @var CustomIdObject */
    #[Id]
    #[Column(type: 'Doctrine\Tests\ORM\Functional\Ticket\GH5988CustomIdObjectHashType', length: 255)]
    public $id;
}


#[Table]
#[Entity]
class GH5988CustomIdObjectTypeChild extends GH5988CustomIdObjectTypeParent
{
    public function __construct(CustomIdObject $id, public string $name)
    {
        $this->id = $id;
    }
}
