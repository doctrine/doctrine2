<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Internal\UnitOfWork;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Internal\UnitOfWork\UniqueTupleKey;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function serialize;
use function spl_object_id;

#[CoversClass(UniqueTupleKey::class)]
final class UniqueTupleKeyTest extends TestCase
{
    /** @return iterable<string, array{string, mixed, string|null}> */
    public static function provideFieldValues(): iterable
    {
        yield 'string' => ['f', 'v', serialize('v')];
        yield 'integer' => ['i', 42, serialize(42)];
        yield 'boolean as database value' => ['b', true, serialize(1)];
        yield 'NULL never collides' => ['f', null, null];
        yield 'failing conversion' => ['dt', 'not a date', null];
        yield 'non-scalar conversion result' => ['f', new stdClass(), null];
    }

    #[DataProvider('provideFieldValues')]
    public function testFieldComponent(string $field, mixed $value, string|null $expectedKey): void
    {
        $entity           = new UtkItem();
        $entity->{$field} = $value;

        self::assertSame($expectedKey, $this->key($entity, [[$field, false]], null));
    }

    public function testCandidateSideIsReadFromSnapshot(): void
    {
        $entity    = new UtkItem();
        $entity->f = 'current';

        self::assertSame(serialize('snapshot'), $this->key($entity, [['f', false]], ['f' => 'snapshot']));
        self::assertNull($this->key($entity, [['f', false]], []));
    }

    public function testAssociationComponentUsesReferencedEntityIdentity(): void
    {
        $ref         = new UtkRef();
        $entity      = new UtkItem();
        $entity->ref = $ref;
        $entity->f   = 'v';

        self::assertSame('o' . spl_object_id($ref) . ' ' . serialize('v'), $this->key($entity, [['ref', true], ['f', false]], null));
        self::assertNull($this->key(new UtkItem(), [['ref', true]], null));
    }

    /**
     * @param list<array{string, bool}> $declaration
     * @param array<string, mixed>|null $snapshot
     */
    private function key(UtkItem $entity, array $declaration, array|null $snapshot): string|null
    {
        $metadata = new ClassMetadata(UtkItem::class);

        foreach (['f' => 'string', 'i' => 'integer', 'b' => 'boolean', 'dt' => 'datetime'] as $fieldName => $type) {
            $metadata->fieldMappings[$fieldName]     = FieldMapping::fromMappingArray([
                'type' => $type,
                'fieldName' => $fieldName,
                'columnName' => $fieldName,
                'unique' => true,
            ]);
            $metadata->fieldNames[$fieldName]        = $fieldName;
            $metadata->propertyAccessors[$fieldName] = PropertyAccessorFactory::createPropertyAccessor(UtkItem::class, $fieldName);
        }

        $metadata->associationMappings['ref'] = ManyToOneAssociationMapping::fromMappingArray([
            'fieldName' => 'ref',
            'sourceEntity' => UtkItem::class,
            'targetEntity' => UtkRef::class,
            'joinColumns' => [['name' => 'ref_id', 'referencedColumnName' => 'id', 'unique' => true]],
        ]);
        $metadata->propertyAccessors['ref']   = PropertyAccessorFactory::createPropertyAccessor(UtkItem::class, 'ref');

        return (new UniqueTupleKey(new SQLitePlatform()))->forDeclaration($metadata, $entity, $declaration, $snapshot);
    }
}

class UtkItem
{
    public mixed $f         = null;
    public mixed $i         = null;
    public mixed $b         = null;
    public mixed $dt        = null;
    public UtkRef|null $ref = null;
}

class UtkRef
{
}
