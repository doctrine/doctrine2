<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

use function assert;
use function serialize;
use function unserialize;

final class AssociationMappingTest extends TestCase
{
    public function testItSurvivesSerialization(): void
    {
        $mapping = new MyAssociationMapping(
            fieldName: 'foo',
            sourceEntity: self::class,
            targetEntity: self::class,
        );

        $mapping->cascade           = ['persist'];
        $mapping->fetch             = ClassMetadata::FETCH_EAGER;
        $mapping->inherited         = self::class;
        $mapping->declared          = self::class;
        $mapping->cache             = ['usage' => ClassMetadata::CACHE_USAGE_READ_ONLY];
        $mapping->id                = true;
        $mapping->isOnDeleteCascade = true;
        $mapping->originalClass     = self::class;
        $mapping->originalField     = 'foo';
        $mapping->orphanRemoval     = true;
        $mapping->unique            = true;

        $resurrectedMapping = unserialize(serialize($mapping));
        assert($resurrectedMapping instanceof AssociationMapping);

        self::assertSame(['persist'], $resurrectedMapping->cascade);
        self::assertSame(ClassMetadata::FETCH_EAGER, $resurrectedMapping->fetch);
        self::assertSame(self::class, $resurrectedMapping->inherited);
        self::assertSame(self::class, $resurrectedMapping->declared);
        self::assertSame(['usage' => ClassMetadata::CACHE_USAGE_READ_ONLY], $resurrectedMapping->cache);
        self::assertTrue($resurrectedMapping->id);
        self::assertTrue($resurrectedMapping->isOnDeleteCascade);
        self::assertSame(self::class, $resurrectedMapping->originalClass);
        self::assertSame('foo', $resurrectedMapping->originalField);
        self::assertTrue($resurrectedMapping->orphanRemoval);
        self::assertTrue($resurrectedMapping->unique);
    }
}

class MyAssociationMapping extends AssociationMapping
{
}
