<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\JoinTableMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\MappingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function assert;
use function serialize;
use function unserialize;

final class ManyToManyOwningSideMappingTest extends TestCase
{
    public function testItSurvivesSerialization(): void
    {
        $mapping = new ManyToManyOwningSideMapping(
            fieldName: 'foo',
            sourceEntity: self::class,
            targetEntity: self::class,
        );

        $mapping->joinTable                  = new JoinTableMapping('bar');
        $mapping->joinTableColumns           = ['foo', 'bar'];
        $mapping->relationToSourceKeyColumns = ['foo' => 'bar'];
        $mapping->relationToTargetKeyColumns = ['bar' => 'baz'];

        $resurrectedMapping = unserialize(serialize($mapping));
        assert($resurrectedMapping instanceof ManyToManyOwningSideMapping);

        self::assertSame($resurrectedMapping->joinTable->name, 'bar');
        self::assertSame(['foo', 'bar'], $resurrectedMapping->joinTableColumns);
        self::assertSame(['foo' => 'bar'], $resurrectedMapping->relationToSourceKeyColumns);
        self::assertSame(['bar' => 'baz'], $resurrectedMapping->relationToTargetKeyColumns);
    }

    /** @param array<string,mixed> $mappingArray */
    #[DataProvider('mappingsProvider')]
    public function testNullableDefaults(
        bool $expectException,
        bool $expectedValue,
        array $mappingArray,
    ): void {
        $namingStrategy = new DefaultNamingStrategy();
        if ($expectException) {
            $this->expectException(
                MappingException::class,
            );
        }

        $mapping = ManyToManyOwningSideMapping::fromMappingArrayAndNamingStrategy(
            $mappingArray,
            $namingStrategy,
        );

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            self::assertSame($expectedValue, $joinColumn->nullable);
        }
    }

    /** @return iterable<string, array{bool, bool, array<string,mixed>}> */
    public static function mappingsProvider(): iterable
    {
        yield 'defaults to false' => [
            false,
            false,
            [
                'fieldName' => 'foo',
                'sourceEntity' => self::class,
                'targetEntity' => self::class,
                'isOwningSide' => true,
                'joinTable' => [
                    'name' => 'bar',
                    'joinColumns' => [
                        ['name' => 'bar_id', 'referencedColumnName' => 'id'],
                    ],
                    'inverseJoinColumns' => [
                        ['name' => 'foo_id', 'referencedColumnName' => 'id'],
                    ],
                ],
            ],
        ];

        yield 'explicitly marked as nullable' => [
            true,
            false, // user's intent is ignored at the ORM level
            [
                'fieldName' => 'foo',
                'sourceEntity' => self::class,
                'targetEntity' => self::class,
                'isOwningSide' => true,
                'joinTable' => [
                    'name' => 'bar',
                    'joinColumns' => [
                        ['name' => 'bar_id', 'referencedColumnName' => 'id', 'nullable' => true],
                    ],
                    'inverseJoinColumns' => [
                        ['name' => 'foo_id', 'referencedColumnName' => 'id'],
                    ],
                ],
                'id' => true,
            ],
        ];

        yield 'explicitly marked as nullable (inverse column)' => [
            true,
            false, // user's intent is ignored at the ORM level
            [
                'fieldName' => 'foo',
                'sourceEntity' => self::class,
                'targetEntity' => self::class,
                'isOwningSide' => true,
                'joinTable' => [
                    'name' => 'bar',
                    'joinColumns' => [
                        ['name' => 'bar_id', 'referencedColumnName' => 'id'],
                    ],
                    'inverseJoinColumns' => [
                        ['name' => 'foo_id', 'referencedColumnName' => 'id', 'nullable' => true],
                    ],
                ],
                'id' => true,
            ],
        ];

        yield 'explicitly marked as not nullable' => [
            true,
            false,
            [
                'fieldName' => 'foo',
                'sourceEntity' => self::class,
                'targetEntity' => self::class,
                'isOwningSide' => true,
                'joinTable' => [
                    'name' => 'bar',
                    'joinColumns' => [
                        ['name' => 'bar_id', 'referencedColumnName' => 'id', 'nullable' => false],
                    ],
                    'inverseJoinColumns' => [
                        ['name' => 'foo_id', 'referencedColumnName' => 'id'],
                    ],
                ],
                'id' => true,
            ],
        ];

        yield 'explicitly marked as not nullable (inverse column)' => [
            true,
            false,
            [
                'fieldName' => 'foo',
                'sourceEntity' => self::class,
                'targetEntity' => self::class,
                'isOwningSide' => true,
                'joinTable' => [
                    'name' => 'bar',
                    'joinColumns' => [
                        ['name' => 'bar_id', 'referencedColumnName' => 'id'],
                    ],
                    'inverseJoinColumns' => [
                        ['name' => 'foo_id', 'referencedColumnName' => 'id', 'nullable' => false],
                    ],
                ],
                'id' => true,
            ],
        ];
    }
}
