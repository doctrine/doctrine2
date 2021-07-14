<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Id;

use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\ORMException;
use Doctrine\Tests\OrmTestCase;

/**
 * AssignedGeneratorTest
 */
class AssignedGeneratorTest extends OrmTestCase
{
    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var AssignedGenerator */
    private $assignedGen;

    protected function setUp(): void
    {
        $this->entityManager = $this->getTestEntityManager();
        $this->assignedGen   = new AssignedGenerator();
    }

    /**
     * @dataProvider entitiesWithoutId
     */
    public function testThrowsExceptionIfIdNotAssigned($entity): void
    {
        $this->expectException(ORMException::class);

        $this->assignedGen->generate($this->entityManager, $entity);
    }

    public function entitiesWithoutId(): array
    {
        return [
            'single'    => [new AssignedSingleIdEntity()],
            'composite' => [new AssignedCompositeIdEntity()],
        ];
    }

    public function testCorrectIdGeneration(): void
    {
        $entity       = new AssignedSingleIdEntity();
        $entity->myId = 1;
        $id           = $this->assignedGen->generate($this->entityManager, $entity);
        $this->assertEquals(['myId' => 1], $id);

        $entity        = new AssignedCompositeIdEntity();
        $entity->myId2 = 2;
        $entity->myId1 = 4;
        $id            = $this->assignedGen->generate($this->entityManager, $entity);
        $this->assertEquals(['myId1' => 4, 'myId2' => 2], $id);
    }
}

/** @Entity */
class AssignedSingleIdEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $myId;
}

/** @Entity */
class AssignedCompositeIdEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $myId1;

    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $myId2;
}
