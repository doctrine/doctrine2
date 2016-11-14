<?php

namespace Doctrine\Tests\ORM\Id;

use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\ORMException;
use Doctrine\Tests\OrmTestCase;

/**
 * AssignedGeneratorTest
 *
 * @author robo
 */
class AssignedGeneratorTest extends OrmTestCase
{
    private $_em;
    private $_assignedGen;

    protected function setUp()
    {
        $this->_em = $this->_getTestEntityManager();
        $this->_assignedGen = new AssignedGenerator;
    }

    public function testThrowsExceptionIfIdNotAssigned()
    {
        try {
            $entity = new AssignedSingleIdEntity;
            $this->_assignedGen->generateId($this->_em, $entity);
            $this->fail('Assigned generator did not throw exception even though ID was missing.');
        } catch (ORMException $expected) {}

        try {
            $entity = new AssignedCompositeIdEntity;
            $this->_assignedGen->generateId($this->_em, $entity);
            $this->fail('Assigned generator did not throw exception even though ID was missing.');
        } catch (ORMException $expected) {}
    }

    public function testCorrectIdGeneration()
    {
        $entity = new AssignedSingleIdEntity;
        $entity->myId = 1;
        $id = $this->_assignedGen->generateId($this->_em, $entity);
        $this->assertEquals(['myId' => 1], $id);

        $entity = new AssignedCompositeIdEntity;
        $entity->myId2 = 2;
        $entity->myId1 = 4;
        $id = $this->_assignedGen->generateId($this->_em, $entity);
        $this->assertEquals(['myId1' => 4, 'myId2' => 2], $id);
    }
}

/** @Entity */
class AssignedSingleIdEntity {
    /** @Id @Column(type="integer") */
    public $myId;
}

/** @Entity */
class AssignedCompositeIdEntity {
    /** @Id @Column(type="integer") */
    public $myId1;
    /** @Id @Column(type="integer") */
    public $myId2;
}
