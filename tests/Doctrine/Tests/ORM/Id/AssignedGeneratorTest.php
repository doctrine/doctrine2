<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Id;

use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\ORMException;
use Doctrine\Tests\OrmTestCase;

/**
 * AssignedGeneratorTest
 */
class AssignedGeneratorTest extends OrmTestCase
{
    private $_em;
    private $_assignedGen;

    protected function setUp(): void
    {
        $this->_em          = $this->_getTestEntityManager();
        $this->_assignedGen = new AssignedGenerator();
    }

    /**
     * @dataProvider entitiesWithoutId
     */
    public function testThrowsExceptionIfIdNotAssigned($entity): void
    {
        $this->expectException(ORMException::class);

        $this->_assignedGen->generate($this->_em, $entity);
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
        $id           = $this->_assignedGen->generate($this->_em, $entity);
        $this->assertEquals(['myId' => 1], $id);

        $entity        = new AssignedCompositeIdEntity();
        $entity->myId2 = 2;
        $entity->myId1 = 4;
        $id            = $this->_assignedGen->generate($this->_em, $entity);
        $this->assertEquals(['myId1' => 4, 'myId2' => 2], $id);
    }
}

/** @Entity */
class AssignedSingleIdEntity
{
    /** @Id @Column(type="integer") */
    public $myId;
}

/** @Entity */
class AssignedCompositeIdEntity
{
    /** @Id @Column(type="integer") */
    public $myId1;
    /** @Id @Column(type="integer") */
    public $myId2;
}
