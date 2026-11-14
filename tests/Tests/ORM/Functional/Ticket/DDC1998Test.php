<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use Stringable;

#[Group('DDC-1998')]
class DDC1998Test extends OrmFunctionalTestCase
{
    public function testSqlConversionAsIdentifier(): void
    {
        self::registerDbalType('ddc1998', DDC1998Type::class);

        $this->createSchemaForModels(DDC1998Entity::class);

        $entity     = new DDC1998Entity();
        $entity->id = new DDC1998Id('foo');

        $this->_em->persist($entity);
        $this->_em->flush();

        $entity->num++;

        $this->_em->flush();

        $this->_em->remove($entity);
        $this->_em->flush();
        $this->_em->clear();

        $found = $this->_em->find(DDC1998Entity::class, $entity->id);
        self::assertNull($found);

        $found = $this->_em->find(DDC1998Entity::class, 'foo');
        self::assertNull($found);

        self::assertCount(0, $this->_em->getRepository(DDC1998Entity::class)->findAll());
    }
}

#[Entity]
class DDC1998Entity
{
    /** @var string */
    #[Id]
    #[Column(type: 'ddc1998', length: 255)]
    public $id;

    /** @var int */
    #[Column(type: 'integer')]
    public $num = 0;
}

class DDC1998Type extends StringType
{
    final public const string NAME = 'ddc1998';

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): string
    {
        return (string) $value;
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): DDC1998Id
    {
        return new DDC1998Id($value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}

class DDC1998Id implements Stringable
{
    public function __construct(private string $val)
    {
    }

    public function __toString(): string
    {
        return $this->val;
    }
}
