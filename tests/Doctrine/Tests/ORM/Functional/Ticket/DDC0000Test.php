<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\OrmFunctionalTestCase;
use Doctrine\ORM\Event\LifecycleEventArgs;

/**
 * Functional tests for cascade remove with orphanRemoval.
 *
 * @author Lallement Thomas <thomas.lallement@9online.fr>
 */
class DDC0000Test extends OrmFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->setUpEntitySchema(array(
            'Doctrine\Tests\ORM\Functional\Ticket\User',
            'Doctrine\Tests\ORM\Functional\Ticket\Role',
            'Doctrine\Tests\ORM\Functional\Ticket\ActionLog',
        ));
    }

    public function testIssueCascadeRemoveOrphanRemoval()
    {
        $user = new User();
        $role = new Role();

        $user->addRole($role);

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->find('Doctrine\Tests\ORM\Functional\Ticket\User', $user->id);
        $role = $user->roles->get(0);

        $this->assertEquals(1, count($user->roles));

        $user->roles->removeElement($role);
        //$this->_em->remove($role);

        $this->assertEquals(0, count($user->roles));

        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->find('Doctrine\Tests\ORM\Functional\Ticket\User', $user->id);

        $this->assertEquals(0, count($user->roles));

        $actionLog = $this->_em->find('Doctrine\Tests\ORM\Functional\Ticket\ActionLog', 1);

        $this->assertEquals('1 - remove', $actionLog->id.' - '.$actionLog->action);
    }
}

/**
 * @Entity @Table(name="ddc0000_role") @HasLifecycleCallbacks
 */
class Role
{
    /**
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @ManyToOne(targetEntity="User", inversedBy="roles")
     */
    public $user;

    /**
     * @PreRemove
     */
    public function preRemove(LifecycleEventArgs $eventArgs)
    {
        $em = $eventArgs->getEntityManager();

        $actionLog = new ActionLog();
        $actionLog->action = 'remove';
        $em->persist($actionLog);
    }
}

/**
 * @Entity @Table(name="ddc0000_user")
 */
class User
{
    public $changeSet = array();

    /**
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @OneToMany(targetEntity="Role", mappedBy="user", cascade={"all"}, orphanRemoval=true)
     */
    public $roles;

    public function addRole(Role $role)
    {
        $this->roles[] = $role;
        $role->user = $this;
    }
}

/**
 * @Entity @Table(name="ddc0000_action_log")
 */
class ActionLog
{
    /**
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
    */
    public $id;

    /**
     * @Column(name="action", type="string", length=255, nullable=true)
     */
    public $action;
}
