<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\Tests\OrmTestCase;

class ResolveTargetEntityListenerTest extends OrmTestCase
{
    /** @var EntityManager */
    private $em;

    /** @var ResolveTargetEntityListener */
    private $listener;

    /** @var ClassMetadataFactory */
    private $factory;

    protected function setUp(): void
    {
        $annotationDriver = $this->createAnnotationDriver();

        $this->em = $this->getTestEntityManager();
        $this->em->getConfiguration()->setMetadataDriverImpl($annotationDriver);
        $this->factory  = $this->em->getMetadataFactory();
        $this->listener = new ResolveTargetEntityListener();
    }

    /**
     * @group DDC-1544
     */
    public function testResolveTargetEntityListenerCanResolveTargetEntity(): void
    {
        $evm = $this->em->getEventManager();
        $this->listener->addResolveTargetEntity(ResolveTarget::class, ResolveTargetEntity::class, []);
        $this->listener->addResolveTargetEntity(Target::class, TargetEntity::class, []);
        $evm->addEventSubscriber($this->listener);

        $cm   = $this->factory->getMetadataFor(ResolveTargetEntity::class);
        $meta = $cm->associationMappings;

        $this->assertSame(TargetEntity::class, $meta['manyToMany']['targetEntity']);
        $this->assertSame(ResolveTargetEntity::class, $meta['manyToOne']['targetEntity']);
        $this->assertSame(ResolveTargetEntity::class, $meta['oneToMany']['targetEntity']);
        $this->assertSame(TargetEntity::class, $meta['oneToOne']['targetEntity']);

        $this->assertSame($cm, $this->factory->getMetadataFor(ResolveTarget::class));
    }

    /**
     * @group DDC-3385
     * @group 1181
     * @group 385
     */
    public function testResolveTargetEntityListenerCanRetrieveTargetEntityByInterfaceName(): void
    {
        $this->listener->addResolveTargetEntity(ResolveTarget::class, ResolveTargetEntity::class, []);

        $this->em->getEventManager()->addEventSubscriber($this->listener);

        $cm = $this->factory->getMetadataFor(ResolveTarget::class);

        $this->assertSame($this->factory->getMetadataFor(ResolveTargetEntity::class), $cm);
    }

    /**
     * @group DDC-2109
     */
    public function testAssertTableColumnsAreNotAddedInManyToMany(): void
    {
        $evm = $this->em->getEventManager();
        $this->listener->addResolveTargetEntity(ResolveTarget::class, ResolveTargetEntity::class, []);
        $this->listener->addResolveTargetEntity(Target::class, TargetEntity::class, []);

        $evm->addEventListener(Events::loadClassMetadata, $this->listener);
        $cm   = $this->factory->getMetadataFor(ResolveTargetEntity::class);
        $meta = $cm->associationMappings['manyToMany'];

        $this->assertSame(TargetEntity::class, $meta['targetEntity']);
        $this->assertEquals(['resolvetargetentity_id', 'target_id'], $meta['joinTableColumns']);
    }

    /**
     * @group 1572
     * @group functional
     * @coversNothing
     */
    public function testDoesResolveTargetEntitiesInDQLAlsoWithInterfaces(): void
    {
        $evm = $this->em->getEventManager();
        $this->listener->addResolveTargetEntity(ResolveTarget::class, ResolveTargetEntity::class, []);

        $evm->addEventSubscriber($this->listener);

        $this->assertStringMatchesFormat(
            'SELECT%AFROM ResolveTargetEntity%A',
            $this
                ->em
                ->createQuery('SELECT f FROM Doctrine\Tests\ORM\Tools\ResolveTarget f')
                ->getSQL()
        );
    }
}

interface ResolveTarget
{
    public function getId(): int;
}

interface Target extends ResolveTarget
{
}

/**
 * @Entity
 */
class ResolveTargetEntity implements ResolveTarget
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @psalm-var Collection<int, Target>
     * @ManyToMany(targetEntity="Doctrine\Tests\ORM\Tools\Target")
     */
    private $manyToMany;

    /**
     * @var ResolveTarget
     * @ManyToOne(targetEntity="Doctrine\Tests\ORM\Tools\ResolveTarget", inversedBy="oneToMany")
     */
    private $manyToOne;

    /**
     * @psalm-var Collection<int, ResolveTarget>
     * @OneToMany(targetEntity="Doctrine\Tests\ORM\Tools\ResolveTarget", mappedBy="manyToOne")
     */
    private $oneToMany;

    /**
     * @var Target
     * @OneToOne(targetEntity="Doctrine\Tests\ORM\Tools\Target")
     * @JoinColumn(name="target_entity_id", referencedColumnName="id")
     */
    private $oneToOne;

    public function getId(): int
    {
        return $this->id;
    }
}

/**
 * @Entity
 */
class TargetEntity implements Target
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    public function getId(): int
    {
        return $this->id;
    }
}
