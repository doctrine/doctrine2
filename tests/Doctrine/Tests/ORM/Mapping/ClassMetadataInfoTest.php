<?php

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use PHPUnit\Framework\TestCase;

class ClassMetadataInfoTest extends TestCase
{
    public function testExtendingClassWithOldSignatureStillWorks() : void
    {
        $object = new class () extends ClassMetadataInfoTest {
            public function whatever(ClassMetadataInfo $cm) : bool
            {
                return true;
            }
        };
        $this->assertTrue($object->whatever(new ClassMetadata('MyEntity')));
    }

    /**
     * @runInSeparateProcess
     * @group legacy
     * @expectedDeprecation Doctrine\ORM\Mapping\ClassMetadataInfo is deprecated since doctrine/orm 2.x and will be removed in 3.0. Use Doctrine\ORM\Mapping\ClassMetadata instead.
     */
    public function testTheClassIsDeprecated() : void
    {
        $this->assertTrue(class_exists(ClassMetadataInfo::class));
    }


    public function whatever(ClassMetadata $cm) : bool
    {
        return true;
    }
}
