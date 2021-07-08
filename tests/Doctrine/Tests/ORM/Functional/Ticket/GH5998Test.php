<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\LockMode;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group GH-5998
 */
class GH5998Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(GH5998JTI::class),
            $this->_em->getClassMetadata(GH5998JTIChild::class),
            $this->_em->getClassMetadata(GH5998STI::class),
            $this->_em->getClassMetadata(GH5998Basic::class),
            $this->_em->getClassMetadata(GH5998Related::class),
        ]);
    }

    /**
     * Verifies that MappedSuperclasses work within an inheritance hierarchy.
     */
    public function testIssue(): void
    {
        // Test JTI
        $this->classTests(GH5998JTIChild::class);
        // Test STI
        $this->classTests(GH5998STIChild::class);
        // Test Basic
        $this->classTests(GH5998Basic::class);
    }

    private function classTests($className): void
    {
        // Test insert
        $child      = new $className('Sam', 0, 1);
        $child->rel = new GH5998Related();
        $this->_em->persist($child);
        $this->_em->persist($child->rel);
        $this->_em->flush();
        $this->_em->clear();

        // Test find by rel
        $child = $this->_em->getRepository($className)->findOneBy(['rel' => $child->rel]);
        self::assertNotNull($child);
        $this->_em->clear();

        // Test query by id with fetch join
        $child = $this->_em->createQuery('SELECT t, r FROM ' . $className . ' t JOIN t.rel r WHERE t.id = 1')->getOneOrNullResult();
        self::assertNotNull($child);

        // Test lock and update
        $this->_em->transactional(static function ($em) use ($child): void {
            $em->lock($child, LockMode::NONE);
            $child->firstName = 'Bob';
            $child->status    = 0;
        });
        $this->_em->clear();
        $child = $this->_em->getRepository($className)->find(1);
        self::assertEquals($child->firstName, 'Bob');
        self::assertEquals($child->status, 0);

        // Test delete
        $this->_em->remove($child);
        $this->_em->flush();
        $child = $this->_em->getRepository($className)->find(1);
        self::assertNull($child);
    }
}

/**
 * @MappedSuperclass
 */
class GH5998Common
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     * @var int
     */
    public $id;
    /**
     * @ManyToOne(targetEntity=GH5998Related::class)
     * @JoinColumn(name="related_id", referencedColumnName="id")
     * @var GH5998Related
     */
    public $rel;
    /**
     * @Version
     * @Column(type="integer")
     * @var int
     */
    public $version;

    /** @var mixed */
    public $other;
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorMap({"child" = GH5998JTIChild::class})
 */
abstract class GH5998JTI extends GH5998Common
{
    /**
     * @Column(type="string", length=255)
     * @var string
     */
    public $firstName;
}

/**
 * @MappedSuperclass
 */
class GH5998JTICommon extends GH5998JTI
{
    /**
     * @Column(type="integer")
     * @var int
     */
    public $status;
}

/**
 * @Entity
 */
class GH5998JTIChild extends GH5998JTICommon
{
    /**
     * @Column(type="integer")
     * @var int
     */
    public $type;

    public function __construct(string $firstName, int $type, int $status)
    {
        $this->firstName = $firstName;
        $this->type      = $type;
        $this->status    = $status;
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({"child" = GH5998STIChild::class})
 */
abstract class GH5998STI extends GH5998Common
{
    /**
     * @Column(type="string", length=255)
     * @var string
     */
    public $firstName;
}

/**
 * @MappedSuperclass
 */
class GH5998STICommon extends GH5998STI
{
    /**
     * @Column(type="integer")
     * @var int
     */
    public $status;
}

/**
 * @Entity
 */
class GH5998STIChild extends GH5998STICommon
{
    /**
     * @Column(type="integer")
     * @var int
     */
    public $type;

    public function __construct(string $firstName, int $type, int $status)
    {
        $this->firstName = $firstName;
        $this->type      = $type;
        $this->status    = $status;
    }
}

/**
 * @Entity
 */
class GH5998Basic extends GH5998Common
{
    /**
     * @Column(type="string", length=255)
     * @var string
     */
    public $firstName;

    /**
     * @Column(type="integer")
     * @var int
     */
    public $status;

    /**
     * @Column(type="integer")
     * @var int
     */
    public $type;

    public function __construct(string $firstName, int $type, int $status)
    {
        $this->firstName = $firstName;
        $this->type      = $type;
        $this->status    = $status;
    }
}

/**
 * @Entity
 */
class GH5998Related
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     * @var int
     */
    public $id;
}
