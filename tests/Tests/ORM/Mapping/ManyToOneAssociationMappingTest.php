<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\MappingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function assert;
use function serialize;
use function unserialize;

final class ManyToOneAssociationMappingTest extends TestCase
{
    public function testItSurvivesSerialization(): void
    {
        $mapping = new ManyToOneAssociationMapping(
            fieldName: 'foo',
            sourceEntity: self::class,
            targetEntity: self::class,
        );

        $mapping->joinColumns              = [new JoinColumnMapping('foo_id', 'id')];
        $mapping->joinColumnFieldNames     = ['foo' => 'bar'];
        $mapping->sourceToTargetKeyColumns = ['foo' => 'bar'];
        $mapping->targetToSourceKeyColumns = ['bar' => 'foo'];

        $resurrectedMapping = unserialize(serialize($mapping));
        assert($resurrectedMapping instanceof ManyToOneAssociationMapping);

        self::assertCount(1, $resurrectedMapping->joinColumns);
        self::assertSame(['foo' => 'bar'], $resurrectedMapping->joinColumnFieldNames);
        self::assertSame(['foo' => 'bar'], $resurrectedMapping->sourceToTargetKeyColumns);
        self::assertSame(['bar' => 'foo'], $resurrectedMapping->targetToSourceKeyColumns);
    }

    /** @param array<string, mixed> $mappingArray */
    #[DataProvider('mappingsProvider')]
    public function testNullableDefaults(
        bool $expectException,
        bool $expectedValue,
        array $mappingArray,
    ): void {
        $namingStrategy = new DefaultNamingStrategy();
        if ($expectException) {
            $this->expectException(MappingException::class);
        }

        $mapping = ManyToOneAssociationMapping::fromMappingArrayAndName(
            $mappingArray,
            $namingStrategy,
            self::class,
            null,
            false,
        );

        foreach ($mapping->joinColumns as $joinColumn) {
            self::assertSame($expectedValue, $joinColumn->nullable);
        }
    }

    /** @return iterable<string, array{bool, bool, array<string, mixed>}> */
    public static function mappingsProvider(): iterable
    {
        yield 'not part of the identifier' => [
            false,
            true,
            [
                'fieldName' => 'foo',
                'sourceEntity' => self::class,
                'targetEntity' => self::class,
                'isOwningSide' => true,
                'joinColumns' => [
                    ['name' => 'foo_id', 'referencedColumnName' => 'id'],
                ],
                'id' => false,
            ],
        ];

        yield 'part of the identifier' => [
            false,
            false,
            [
                'fieldName' => 'foo',
                'sourceEntity' => self::class,
                'targetEntity' => self::class,
                'isOwningSide' => true,
                'joinColumns' => [
                    ['name' => 'foo_id', 'referencedColumnName' => 'id'],
                ],
                'id' => true,
            ],
        ];

        yield 'part of the identifier, but explicitly marked as nullable' => [
            true,
            false, // user's intent is ignored at the ORM level
            [
                'fieldName' => 'foo',
                'sourceEntity' => self::class,
                'targetEntity' => self::class,
                'isOwningSide' => true,
                'joinColumns' => [
                    ['name' => 'foo_id', 'referencedColumnName' => 'id', 'nullable' => true],
                ],
                'id' => true,
            ],
        ];

        yield 'part of the identifier, but explicitly marked as not nullable' => [
            true,
            false,
            [
                'fieldName' => 'foo',
                'sourceEntity' => self::class,
                'targetEntity' => self::class,
                'isOwningSide' => true,
                'joinColumns' => [
                    ['name' => 'foo_id', 'referencedColumnName' => 'id', 'nullable' => true],
                ],
                'id' => true,
            ],
        ];
    }
}
