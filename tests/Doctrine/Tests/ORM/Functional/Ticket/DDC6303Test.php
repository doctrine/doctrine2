<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Persistence\Mapping\MappingException;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Tools\ToolsException;
use Doctrine\Tests\OrmFunctionalTestCase;
use function array_keys;
use function array_walk;
use function count;

/**
 * @group #6303
 */
class DDC6303Test extends OrmFunctionalTestCase
{
    public function setUp() : void
    {
        parent::setUp();

        try {
            $this->schemaTool->createSchema([
                $this->em->getClassMetadata(DDC6303BaseClass::class),
                $this->em->getClassMetadata(DDC6303ChildA::class),
                $this->em->getClassMetadata(DDC6303ChildB::class),
            ]);
        } catch (ToolsException $ignored) {
        }
    }

    public function testMixedTypeHydratedCorrectlyInJoinedInheritance() : void
    {
        // DDC6303ChildA and DDC6303ChildB have an inheritance from DDC6303BaseClass,
        // but one has a string originalData and the second has an array, since the fields
        // are mapped differently
        $this->assertHydratedEntitiesSameToPersistedOnes([
            'a' => new DDC6303ChildA('a', 'authorized'),
            'b' => new DDC6303ChildB('b', ['accepted', 'authorized']),
        ]);
    }

    public function testEmptyValuesInJoinedInheritance() : void
    {
        $this->assertHydratedEntitiesSameToPersistedOnes([
            'stringEmpty' => new DDC6303ChildA('stringEmpty', ''),
            'stringZero'  => new DDC6303ChildA('stringZero', 0),
            'arrayEmpty'  => new DDC6303ChildB('arrayEmpty', []),
        ]);
    }

    /**
     * @param DDC6303BaseClass[] $persistedEntities indexed by identifier
     *
     * @throws MappingException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function assertHydratedEntitiesSameToPersistedOnes(array $persistedEntities) : void
    {
        array_walk($persistedEntities, [$this->em, 'persist']);

        $this->em->flush();
        $this->em->clear();

        /** @var DDC6303BaseClass[] $entities */
        $entities = $this
            ->em
            ->getRepository(DDC6303BaseClass::class)
            ->createQueryBuilder('p')
            ->where('p.id IN(:ids)')
            ->setParameter('ids', array_keys($persistedEntities))
            ->getQuery()->getResult();

        self::assertCount(count($persistedEntities), $entities);

        foreach ($entities as $entity) {
            self::assertEquals($entity, $persistedEntities[$entity->id]);
        }
    }
}

/**
 * @ORM\Entity
 * @ORM\Table
 * @ORM\InheritanceType("JOINED")
 * @ORM\DiscriminatorColumn(name="discr", type="string")
 * @ORM\DiscriminatorMap({
 *      DDC6303ChildB::class = DDC6303ChildB::class,
 *      DDC6303ChildA::class = DDC6303ChildA::class,
 * })
 *
 * Note: discriminator map order *IS IMPORTANT* for this test
 */
abstract class DDC6303BaseClass
{
    /** @ORM\Id @ORM\Column(type="string") @ORM\GeneratedValue(strategy="NONE") */
    public $id;
}

/** @ORM\Entity @ORM\Table */
class DDC6303ChildA extends DDC6303BaseClass
{
    /** @ORM\Column(type="string") */
    private $originalData;

    public function __construct(string $id, $originalData)
    {
        $this->id           = $id;
        $this->originalData = $originalData;
    }
}

/** @ORM\Entity @ORM\Table */
class DDC6303ChildB extends DDC6303BaseClass
{
    /** @ORM\Column(type="simple_array", nullable=true) */
    private $originalData;

    public function __construct(string $id, array $originalData)
    {
        $this->id           = $id;
        $this->originalData = $originalData;
    }
}
