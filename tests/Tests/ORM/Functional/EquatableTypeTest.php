<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use DateTime;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\EquatableType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

use function interface_exists;

class EquatableTypeTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! interface_exists(EquatableType::class)) {
            self::markTestSkipped('Requires a doctrine/dbal version that provides ' . EquatableType::class);
        }

        $type = new class extends Type implements EquatableType {
            public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
            {
                return $platform->getIntegerTypeDeclarationSQL($column);
            }

            public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): int|null
            {
                return $value?->value;
            }

            public function convertToPHPValue(mixed $value, AbstractPlatform $platform): EquatableValueObject|null
            {
                return $value === null ? null : new EquatableValueObject((int) $value);
            }

            public function valuesAreEqual(object $a, object $b, AbstractPlatform $platform): bool
            {
                return $a instanceof EquatableValueObject
                    && $b instanceof EquatableValueObject
                    && $a->value === $b->value;
            }
        };

        $registry = Type::getTypeRegistry();
        if ($registry->has('equatable_value_object')) {
            $registry->override('equatable_value_object', $type);
        } else {
            $registry->register('equatable_value_object', $type);
        }

        $this->createSchemaForModels(
            EquatableTypeDateEntity::class,
            EquatableTypeDateOnlyEntity::class,
            EquatableTypeValueObjectEntity::class,
        );
    }

    public function testEqualDateDoesNotProduceChangeSet(): void
    {
        $entity       = new EquatableTypeDateEntity();
        $entity->date = new DateTime('2020-01-01 10:00:00');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(EquatableTypeDateEntity::class, $id);
        self::assertInstanceOf(EquatableTypeDateEntity::class, $entity);

        // Replacing the date with an equal instance is not a change.
        $entity->date = new DateTime('2020-01-01 10:00:00');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayNotHasKey('date', $uow->getEntityChangeSet($entity));
    }

    public function testDifferentDateProducesChangeSet(): void
    {
        $entity       = new EquatableTypeDateEntity();
        $entity->date = new DateTime('2020-01-01 10:00:00');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(EquatableTypeDateEntity::class, $id);
        self::assertInstanceOf(EquatableTypeDateEntity::class, $entity);

        // Replacing the date with a different instance is a change.
        $entity->date = new DateTime('2020-01-01 11:00:00');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayHasKey('date', $uow->getEntityChangeSet($entity));
    }

    public function testSameDateDifferentTimeDoesNotProduceChangeSet(): void
    {
        $entity       = new EquatableTypeDateOnlyEntity();
        $entity->date = new DateTime('2020-01-01 10:00:00');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(EquatableTypeDateOnlyEntity::class, $id);
        self::assertInstanceOf(EquatableTypeDateOnlyEntity::class, $entity);

        // The date type stores only the day, so a different time of day on the
        // same day is not a change.
        $entity->date = new DateTime('2020-01-01 23:00:00');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayNotHasKey('date', $uow->getEntityChangeSet($entity));
    }

    public function testDifferentDateOnlyProducesChangeSet(): void
    {
        $entity       = new EquatableTypeDateOnlyEntity();
        $entity->date = new DateTime('2020-01-01 10:00:00');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(EquatableTypeDateOnlyEntity::class, $id);
        self::assertInstanceOf(EquatableTypeDateOnlyEntity::class, $entity);

        $entity->date = new DateTime('2020-01-02 10:00:00');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayHasKey('date', $uow->getEntityChangeSet($entity));
    }

    public function testEqualValueObjectDoesNotProduceChangeSet(): void
    {
        $entity        = new EquatableTypeValueObjectEntity();
        $entity->value = new EquatableValueObject(10);

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(EquatableTypeValueObjectEntity::class, $id);
        self::assertInstanceOf(EquatableTypeValueObjectEntity::class, $entity);

        // Replacing the value object with an equal one is not a change.
        $entity->value = new EquatableValueObject(10);

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayNotHasKey('value', $uow->getEntityChangeSet($entity));
    }

    public function testDifferentValueObjectProducesChangeSet(): void
    {
        $entity        = new EquatableTypeValueObjectEntity();
        $entity->value = new EquatableValueObject(10);

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(EquatableTypeValueObjectEntity::class, $id);
        self::assertInstanceOf(EquatableTypeValueObjectEntity::class, $entity);

        // Replacing the value object with a different one is a change.
        $entity->value = new EquatableValueObject(12);

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayHasKey('value', $uow->getEntityChangeSet($entity));
    }
}

#[ORM\Entity]
class EquatableTypeDateEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\Column(type: 'datetime')]
    public DateTime $date;
}

#[ORM\Entity]
class EquatableTypeDateOnlyEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\Column(type: 'date')]
    public DateTime $date;
}

#[ORM\Entity]
class EquatableTypeValueObjectEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\Column(type: 'equatable_value_object')]
    public EquatableValueObject $value;
}

final class EquatableValueObject
{
    public function __construct(public readonly int $value)
    {
    }
}
