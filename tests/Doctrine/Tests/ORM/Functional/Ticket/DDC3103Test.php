<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @group DDC-3103
 */
class DDC3103Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    /**
     * @covers \Doctrine\ORM\Mapping\ClassMetadata::__sleep
     */
    public function testIssue()
    {
        $this->markTestSkipped('Embeddables are ommitted for now');

        $classMetadata = new ClassMetadata(DDC3103ArticleId::class);

        $this->createAnnotationDriver()->loadMetadataForClass(DDC3103ArticleId::class, $classMetadata);

        self::assertTrue(
            $classMetadata->isEmbeddedClass,
            'The isEmbeddedClass property should be true from the mapping data.'
        );

        self::assertTrue(
            unserialize(serialize($classMetadata))->isEmbeddedClass,
            'The isEmbeddedClass property should still be true after serialization and unserialization.'
        );
    }
}

/**
 * @ORM\Embeddable
 */
class DDC3103ArticleId
{
    /**
     * @var string
     * @ORM\Column(name="name", type="string", length=255)
     */
    protected $nameValue;
}
