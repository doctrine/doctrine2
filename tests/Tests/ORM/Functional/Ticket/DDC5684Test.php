<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types as DBALTypes;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use Stringable;

/**
 * This test verifies that custom post-insert identifiers respect type conversion semantics.
 * The generated identifier must be converted via DBAL types before populating the entity
 * identifier field.
 */
#[Group('5935')]
#[Group('5684')]
#[Group('6020')]
#[Group('6152')]
class DDC5684Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(DDC5684ObjectIdType::class, DDC5684ObjectIdType::class);

        $this->createSchemaForModels(DDC5684Object::class);
    }

    public function testAutoIncrementIdWithCustomType(): void
    {
        $object = new DDC5684Object();
        $this->_em->persist($object);
        $this->_em->flush();

        self::assertInstanceOf(DDC5684ObjectId::class, $object->id);
    }

    public function testFetchObjectWithAutoIncrementedCustomType(): void
    {
        $object = new DDC5684Object();
        $this->_em->persist($object);
        $this->_em->flush();
        $this->_em->clear();

        $rawId  = $object->id->value;
        $object = $this->_em->find(DDC5684Object::class, new DDC5684ObjectId($rawId));

        self::assertInstanceOf(DDC5684ObjectId::class, $object->id);
        self::assertEquals($rawId, $object->id->value);
    }
}

class DDC5684ObjectIdType extends DBALTypes\Type
{
    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): DDC5684ObjectId
    {
        return new DDC5684ObjectId($value);
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        return $value->value;
    }

    public function getName(): string
    {
        return self::class;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }
}

class DDC5684ObjectId implements Stringable
{
    public function __construct(public mixed $value)
    {
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}

#[Table(name: 'ticket_5684_objects')]
#[Entity]
class DDC5684Object
{
    /** @var DDC5684ObjectIdType */
    #[Id]
    #[Column(type: DDC5684ObjectIdType::class)]
    #[GeneratedValue(strategy: 'AUTO')]
    public $id;
}
