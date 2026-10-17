<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Internal\UnitOfWork;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Internal\UnitOfWork\UniqueTupleKey;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use Doctrine\Tests\DbalTypes\EarlyDeletionThrowingType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function serialize;
use function spl_object_id;

#[CoversClass(UniqueTupleKey::class)]
#[Group('#6776')]
final class UniqueTupleKeyTest extends TestCase
{
    #[DataProvider('providePlatforms')]
    public function testConvertsFieldComponentPerPlatform(AbstractPlatform $platform): void
    {
        $class = $this->itemMetadata(['f' => 'string', 'i' => 'integer', 'b' => 'boolean']);

        $entity    = new UtkItem();
        $entity->f = 'v';
        $entity->i = 42;
        $entity->b = true;

        $uniqueTupleKey = new UniqueTupleKey($platform);

        self::assertSame(serialize('v'), $uniqueTupleKey->forDeclaration($class, $entity, [['f', false]], null));
        self::assertSame(serialize(42), $uniqueTupleKey->forDeclaration($class, $entity, [['i', false]], null));
        // The database representation of a boolean is the integer 1 on
        // every platform's prepared-statement path.
        self::assertSame(serialize(1), $uniqueTupleKey->forDeclaration($class, $entity, [['b', false]], null));
    }

    #[DataProvider('providePlatforms')]
    public function testConvertsAssociationComponentPerPlatform(AbstractPlatform $platform): void
    {
        $class = $this->associationMetadata();
        $ref   = new UtkRef();

        $candidate = new UtkItem();

        // An association component compares by referenced-entity
        // identity, in memory and without I/O.
        $key = (new UniqueTupleKey($platform))->forDeclaration($class, $candidate, [['ref', true]], ['ref' => $ref]);

        self::assertSame('o' . spl_object_id($ref), $key);
    }

    #[DataProvider('providePlatforms')]
    public function testCandidateSideIsReadFromSnapshot(AbstractPlatform $platform): void
    {
        $class = $this->itemMetadata(['f' => 'string']);

        $entity    = new UtkItem();
        $entity->f = 'current-value';

        $uniqueTupleKey = new UniqueTupleKey($platform);

        self::assertSame(
            serialize('snapshot-value'),
            $uniqueTupleKey->forDeclaration($class, $entity, [['f', false]], ['f' => 'snapshot-value']),
        );

        // A component missing from the snapshot is not comparable.
        self::assertNull($uniqueTupleKey->forDeclaration($class, $entity, [['f', false]], []));
    }

    #[DataProvider('providePlatforms')]
    public function testNullAndNonConvertibleComponentsYieldNull(AbstractPlatform $platform): void
    {
        if (! Type::hasType(EarlyDeletionThrowingType::NAME)) {
            Type::addType(EarlyDeletionThrowingType::NAME, EarlyDeletionThrowingType::class);
        }

        $stringClass   = $this->itemMetadata(['f' => 'string', 'm' => 'string']);
        $throwingClass = $this->itemMetadata(['m' => EarlyDeletionThrowingType::NAME]);

        $uniqueTupleKey = new UniqueTupleKey($platform);

        // NULL never conflicts in a unique constraint: the key is null.
        self::assertNull($uniqueTupleKey->forDeclaration($stringClass, new UtkItem(), [['f', false]], null));

        // A converter that throws floors the comparison: the key is
        // null and the failure never escapes.
        $throwing    = new UtkItem();
        $throwing->m = 'anything';
        self::assertNull($uniqueTupleKey->forDeclaration($throwingClass, $throwing, [['m', false]], null));

        // A non-scalar conversion result is not comparable either.
        $opaque    = new UtkItem();
        $opaque->m = static fn (): string => 'unreachable';
        self::assertNull($uniqueTupleKey->forDeclaration($stringClass, $opaque, [['m', false]], null));
    }

    /** @return array<string, array{AbstractPlatform}> */
    public static function providePlatforms(): array
    {
        return [
            'sqlite' => [new SQLitePlatform()],
            'mysql' => [new MySQLPlatform()],
            'mariadb' => [new MariaDBPlatform()],
            'postgresql' => [new PostgreSQLPlatform()],
        ];
    }

    /** @param array<string, string> $fields field name to DBAL type, declared unique */
    private function itemMetadata(array $fields): ClassMetadata
    {
        $metadata = new ClassMetadata(UtkItem::class);

        foreach ($fields as $fieldName => $type) {
            $metadata->fieldMappings[$fieldName]     = FieldMapping::fromMappingArray([
                'type' => $type,
                'fieldName' => $fieldName,
                'columnName' => $fieldName,
                'unique' => true,
            ]);
            $metadata->fieldNames[$fieldName]        = $fieldName;
            $metadata->propertyAccessors[$fieldName] = PropertyAccessorFactory::createPropertyAccessor(UtkItem::class, $fieldName);
        }

        return $metadata;
    }

    private function associationMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(UtkItem::class);

        $metadata->associationMappings['ref'] = ManyToOneAssociationMapping::fromMappingArray([
            'fieldName' => 'ref',
            'sourceEntity' => UtkItem::class,
            'targetEntity' => UtkRef::class,
            'joinColumns' => [
                [
                    'name' => 'ref_id',
                    'referencedColumnName' => 'id',
                    'unique' => true,
                ],
            ],
        ]);
        $metadata->propertyAccessors['ref']   = PropertyAccessorFactory::createPropertyAccessor(UtkItem::class, 'ref');

        return $metadata;
    }
}

class UtkItem
{
    public string|null $f   = null;
    public int|null $i      = null;
    public bool|null $b     = null;
    public mixed $m         = null;
    public UtkRef|null $ref = null;
}

class UtkRef
{
}
