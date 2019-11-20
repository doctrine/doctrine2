<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Tests\Models\CMS\CmsEmployee;

/**
 * @group 7052
 */
class Issue7052Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->_schemaTool->createSchema(array(
            $this->_em->getClassMetadata(__NAMESPACE__ . '\\Issue7052Child'),
            $this->_em->getClassMetadata(__NAMESPACE__ . '\\Issue7052Parent'),
        ));
    }

    public function testIssue()
    {
        $parent       = new Issue7052Parent();
        $parent->name = "parent test";

        $childA         = new Issue7052Child();
        $childA->parent = $parent;

        $childB         = new Issue7052Child();
        $childB->parent = $parent;

        $this->_em->persist($parent);
        $this->_em->persist($childA);
        $this->_em->persist($childB);
        $this->_em->flush();
        $this->_em->clear();

        $childAFromDb = $this->_em->find('Doctrine\Tests\ORM\Functional\Ticket\Issue7052Child', $childA->id);

        $this->_em->clear();

        $childBFromDb = $this->_em->find('Doctrine\Tests\ORM\Functional\Ticket\Issue7052Child', $childB->id);

        $parentFromChildB = $childBFromDb->parent;

        self::assertNotSame($childA, $childAFromDb);
        self::assertNotNull($childAFromDb->parent->name, "Unable to get parent name on second loaded child after EM cleared.");
    }
}

/**
 * @Entity
 */
class Issue7052Child
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
    */
    public $id;

    /**
     * @ManyToOne(targetEntity=Issue7052Parent::class, fetch="LAZY")
     */
    public $parent;
}

/**
 * @Entity
 */
class Issue7052Parent
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @Column
     */
    public $name;
}
