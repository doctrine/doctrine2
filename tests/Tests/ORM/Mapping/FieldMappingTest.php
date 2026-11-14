<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use PHPUnit\Framework\TestCase;

use function assert;
use function serialize;
use function unserialize;

final class FieldMappingTest extends TestCase
{
    public function testItSurvivesSerialization(): void
    {
        $mapping = new FieldMapping(
            type: 'string',
            fieldName: 'id',
            columnName: 'id',
        );

        $mapping->length           = 255;
        $mapping->id               = true;
        $mapping->idPosition       = 2;
        $mapping->nullable         = true;
        $mapping->notInsertable    = true;
        $mapping->notUpdatable     = true;
        $mapping->columnDefinition = 'VARCHAR(255)';
        $mapping->generated        = ClassMetadata::GENERATOR_TYPE_AUTO;
        $mapping->enumType         = 'MyEnum';
        $mapping->precision        = 10;
        $mapping->scale            = 2;
        $mapping->unique           = true;
        $mapping->index            = true;
        $mapping->inherited        = self::class;
        $mapping->originalClass    = self::class;
        $mapping->originalField    = 'id';
        $mapping->quoted           = true;
        $mapping->declared         = self::class;
        $mapping->declaredField    = 'id';
        $mapping->options          = ['foo' => 'bar'];
        $mapping->version          = true;

        $resurrectedMapping = unserialize(serialize($mapping));
        assert($resurrectedMapping instanceof FieldMapping);

        self::assertSame(255, $resurrectedMapping->length);
        self::assertTrue($resurrectedMapping->id);
        self::assertSame(2, $resurrectedMapping->idPosition);
        self::assertTrue($resurrectedMapping->nullable);
        self::assertTrue($resurrectedMapping->notInsertable);
        self::assertTrue($resurrectedMapping->notUpdatable);
        self::assertSame('VARCHAR(255)', $resurrectedMapping->columnDefinition);
        self::assertSame(ClassMetadata::GENERATOR_TYPE_AUTO, $resurrectedMapping->generated);
        self::assertSame('MyEnum', $resurrectedMapping->enumType);
        self::assertSame(10, $resurrectedMapping->precision);
        self::assertSame(2, $resurrectedMapping->scale);
        self::assertTrue($resurrectedMapping->unique);
        self::assertTrue($resurrectedMapping->index);
        self::assertSame(self::class, $resurrectedMapping->inherited);
        self::assertSame(self::class, $resurrectedMapping->originalClass);
        self::assertSame('id', $resurrectedMapping->originalField);
        self::assertTrue($resurrectedMapping->quoted);
        self::assertSame(self::class, $resurrectedMapping->declared);
        self::assertSame('id', $resurrectedMapping->declaredField);
        self::assertSame(['foo' => 'bar'], $resurrectedMapping->options);
        self::assertTrue($resurrectedMapping->version);
    }
}
