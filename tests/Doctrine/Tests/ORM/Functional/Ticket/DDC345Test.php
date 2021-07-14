<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\PreUpdate;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC345Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC345User::class),
                $this->_em->getClassMetadata(DDC345Group::class),
                $this->_em->getClassMetadata(DDC345Membership::class),
            ]
        );
    }

    public function testTwoIterateHydrations(): void
    {
        // Create User
        $user       = new DDC345User();
        $user->name = 'Test User';
        $this->_em->persist($user); // $em->flush() does not change much here

        // Create Group
        $group       = new DDC345Group();
        $group->name = 'Test Group';
        $this->_em->persist($group); // $em->flush() does not change much here

        $membership        = new DDC345Membership();
        $membership->group = $group;
        $membership->user  = $user;
        $membership->state = 'active';

        //$this->_em->persist($membership); // COMMENT OUT TO SEE BUG
        /*
        This should be not necessary, but without, its PrePersist is called twice,
        $membership seems to be persisted twice, but all properties but the
        ones set by LifecycleCallbacks are deleted.
        */

        $user->memberships->add($membership);
        $group->memberships->add($membership);

        $this->_em->flush();

        $this->assertEquals(1, $membership->prePersistCallCount);
        $this->assertEquals(0, $membership->preUpdateCallCount);
        $this->assertInstanceOf('DateTime', $membership->updated);
    }
}

/**
 * @Entity
 */
class DDC345User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @psalm-var Collection<int, DDC345Membership>
     * @OneToMany(targetEntity="DDC345Membership", mappedBy="user", cascade={"persist"})
     */
    public $memberships;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
    }
}

/**
 * @Entity
 */
class DDC345Group
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @psalm-var Collection<int, DDC345Membership>
     * @OneToMany(targetEntity="DDC345Membership", mappedBy="group", cascade={"persist"})
     */
    public $memberships;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
    }
}

/**
 * @Entity
 * @HasLifecycleCallbacks
 * @Table(name="ddc345_memberships", uniqueConstraints={
 *      @UniqueConstraint(name="ddc345_memship_fks", columns={"user_id","group_id"})
 * })
 */
class DDC345Membership
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC345User
     * @OneToOne(targetEntity="DDC345User", inversedBy="memberships")
     * @JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
     */
    public $user;

    /**
     * @var DDC345Group
     * @OneToOne(targetEntity="DDC345Group", inversedBy="memberships")
     * @JoinColumn(name="group_id", referencedColumnName="id", nullable=false)
     */
    public $group;

    /**
     * @var string
     * @Column(type="string")
     */
    public $state;

    /**
     * @var DateTime
     * @Column(type="datetime")
     */
    public $updated;

    /** @var int */
    public $prePersistCallCount = 0;

    /** @var int */
    public $preUpdateCallCount = 0;

    /** @PrePersist */
    public function doStuffOnPrePersist(): void
    {
        //echo "***** PrePersist\n";
        ++$this->prePersistCallCount;
        $this->updated = new DateTime();
    }

    /** @PreUpdate */
    public function doStuffOnPreUpdate(): void
    {
        //echo "***** PreUpdate\n";
        ++$this->preUpdateCallCount;
        $this->updated = new DateTime();
    }
}
