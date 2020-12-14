<?php

namespace Doctrine\Tests\Annotation;

use Doctrine\ORM\Annotation\AssociationOverride as NewAssociationOverride;
use Doctrine\ORM\Mapping\AssociationOverride as LegacyAssociationOverride;
use PHPUnit\Framework\Error\Deprecated;
use PHPUnit\Framework\TestCase;

class AssociationOverrideTest extends TestCase
{
    public function test_annotation_can_be_loaded()
    {
        $newOverride = new NewAssociationOverride();

        self::assertInstanceOf(NewAssociationOverride::class, $newOverride);
    }

    public function test_deprecated_annotation_can_be_loaded()
    {
        $this->expectException(Deprecated::class);

        $legacyOverride = new LegacyAssociationOverride();

        self::assertInstanceOf(LegacyAssociationOverride::class, $legacyOverride);
    }
}
