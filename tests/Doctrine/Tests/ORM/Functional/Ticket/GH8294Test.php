<?php

/**
 * @group GH-8294
 */
class GH8294Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    /**
     * @group GH8294
     */
    public function testPersitingEntityWithInvalidTableName()
    {
        $entity = new \Doctrine\Tests\Models\GH8294\GH8294Entity();

        $this->expectException(Doctrine\ORM\Mapping\MappingException::class);
        $this->expectExceptionMessage("Invalid table name for class 'Doctrine\Tests\Models\GH8294\GH8294Entity' expected 'string' got 'array'.");
        $this->_em->persist($entity);
    }
}
