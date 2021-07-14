<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Tools\Console\MetadataFilter;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\Tests\OrmTestCase;

use function count;

/**
 * Tests for {@see \Doctrine\ORM\Tools\Console\MetadataFilter}
 *
 * @covers \Doctrine\ORM\Tools\Console\MetadataFilter
 */
class MetadataFilterTest extends OrmTestCase
{
    /** @var DisconnectedClassMetadataFactory */
    private $cmf;

    protected function setUp(): void
    {
        parent::setUp();

        $driver = $this->createAnnotationDriver();
        $em     = $this->getTestEntityManager();

        $em->getConfiguration()->setMetadataDriverImpl($driver);

        $this->cmf = new DisconnectedClassMetadataFactory();
        $this->cmf->setEntityManager($em);
    }

    public function testFilterWithEmptyArray(): void
    {
        $originalMetadatas = [
            $metadataAaa = $this->cmf->getMetadataFor(MetadataFilterTestEntityAaa::class),
            $metadataBbb = $this->cmf->getMetadataFor(MetadataFilterTestEntityBbb::class),
        ];

        $metadatas = $originalMetadatas;
        $metadatas = MetadataFilter::filter($metadatas, []);

        $this->assertContains($metadataAaa, $metadatas);
        $this->assertContains($metadataBbb, $metadatas);
        $this->assertCount(count($originalMetadatas), $metadatas);
    }

    public function testFilterWithString(): void
    {
        $originalMetadatas = [
            $metadataAaa = $this->cmf->getMetadataFor(MetadataFilterTestEntityAaa::class),
            $metadataBbb = $this->cmf->getMetadataFor(MetadataFilterTestEntityBbb::class),
            $metadataCcc = $this->cmf->getMetadataFor(MetadataFilterTestEntityCcc::class),
        ];

        $metadatas = $originalMetadatas;
        $metadatas = MetadataFilter::filter($metadatas, 'MetadataFilterTestEntityAaa');

        $this->assertContains($metadataAaa, $metadatas);
        $this->assertNotContains($metadataBbb, $metadatas);
        $this->assertNotContains($metadataCcc, $metadatas);
        $this->assertCount(1, $metadatas);

        $metadatas = $originalMetadatas;
        $metadatas = MetadataFilter::filter($metadatas, 'MetadataFilterTestEntityBbb');

        $this->assertNotContains($metadataAaa, $metadatas);
        $this->assertContains($metadataBbb, $metadatas);
        $this->assertNotContains($metadataCcc, $metadatas);
        $this->assertCount(1, $metadatas);

        $metadatas = $originalMetadatas;
        $metadatas = MetadataFilter::filter($metadatas, 'MetadataFilterTestEntityCcc');

        $this->assertNotContains($metadataAaa, $metadatas);
        $this->assertNotContains($metadataBbb, $metadatas);
        $this->assertContains($metadataCcc, $metadatas);
        $this->assertCount(1, $metadatas);
    }

    public function testFilterWithString2(): void
    {
        $originalMetadatas = [
            $metadataFoo    = $this->cmf->getMetadataFor(MetadataFilterTestEntityFoo::class),
            $metadataFooBar = $this->cmf->getMetadataFor(MetadataFilterTestEntityFooBar::class),
            $metadataBar    = $this->cmf->getMetadataFor(MetadataFilterTestEntityBar::class),
        ];

        $metadatas = $originalMetadatas;
        $metadatas = MetadataFilter::filter($metadatas, 'MetadataFilterTestEntityFoo');

        $this->assertContains($metadataFoo, $metadatas);
        $this->assertContains($metadataFooBar, $metadatas);
        $this->assertNotContains($metadataBar, $metadatas);
        $this->assertCount(2, $metadatas);
    }

    public function testFilterWithArray(): void
    {
        $originalMetadatas = [
            $metadataAaa = $this->cmf->getMetadataFor(MetadataFilterTestEntityAaa::class),
            $metadataBbb = $this->cmf->getMetadataFor(MetadataFilterTestEntityBbb::class),
            $metadataCcc = $this->cmf->getMetadataFor(MetadataFilterTestEntityCcc::class),
        ];

        $metadatas = $originalMetadatas;
        $metadatas = MetadataFilter::filter($metadatas, [
            'MetadataFilterTestEntityAaa',
            'MetadataFilterTestEntityCcc',
        ]);

        $this->assertContains($metadataAaa, $metadatas);
        $this->assertNotContains($metadataBbb, $metadatas);
        $this->assertContains($metadataCcc, $metadatas);
        $this->assertCount(2, $metadatas);
    }

    public function testFilterWithRegex(): void
    {
        $originalMetadatas = [
            $metadataFoo    = $this->cmf->getMetadataFor(MetadataFilterTestEntityFoo::class),
            $metadataFooBar = $this->cmf->getMetadataFor(MetadataFilterTestEntityFooBar::class),
            $metadataBar    = $this->cmf->getMetadataFor(MetadataFilterTestEntityBar::class),
        ];

        $metadatas = $originalMetadatas;
        $metadatas = MetadataFilter::filter($metadatas, 'Foo$');

        $this->assertContains($metadataFoo, $metadatas);
        $this->assertNotContains($metadataFooBar, $metadatas);
        $this->assertNotContains($metadataBar, $metadatas);
        $this->assertCount(1, $metadatas);

        $metadatas = $originalMetadatas;
        $metadatas = MetadataFilter::filter($metadatas, 'Bar$');

        $this->assertNotContains($metadataFoo, $metadatas);
        $this->assertContains($metadataFooBar, $metadatas);
        $this->assertContains($metadataBar, $metadatas);
        $this->assertCount(2, $metadatas);
    }
}

/** @Entity */
class MetadataFilterTestEntityAaa
{
    /**
     * @var int
     * @Id
     * @Column
     */
    protected $id;
}

/** @Entity */
class MetadataFilterTestEntityBbb
{
    /**
     * @var int
     * @Id
     * @Column
     */
    protected $id;
}

/** @Entity */
class MetadataFilterTestEntityCcc
{
    /**
     * @var int
     * @Id
     * @Column
     */
    protected $id;
}

/** @Entity */
class MetadataFilterTestEntityFoo
{
    /**
     * @var int
     * @Id
     * @Column
     */
    protected $id;
}

/** @Entity */
class MetadataFilterTestEntityBar
{
    /**
     * @var int
     * @Id
     * @Column
     */
    protected $id;
}

/** @Entity */
class MetadataFilterTestEntityFooBar
{
    /**
     * @var int
     * @Id
     * @Column
     */
    protected $id;
}
