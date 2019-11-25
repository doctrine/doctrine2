<?php

declare(strict_types=1);

namespace Doctrine\Tests\Functional\Ticket;

use Doctrine\Tests\OrmFunctionalTestCase;
use function array_keys;

/**
 * @group GH-7661
 */
class GH7661Test extends OrmFunctionalTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp() : void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH7661User::class,
            GH7661Event::class,
            GH7661Participant::class,
        ]);

        $u1 = new GH7661User();
        $u2 = new GH7661User();
        $e  = new GH7661Event();
        $this->_em->persist($u1);
        $this->_em->persist($u2);
        $this->_em->persist($e);
        $this->_em->persist(new GH7661Participant($u1, $e));
        $this->_em->persist(new GH7661Participant($u2, $e));
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testIndexByAssociation() : void
    {
        $e    = $this->_em->find(GH7661Event::class, 1);
        $keys = $e->participants->getKeys();
        self::assertEquals([1, 2], $keys);

        $participants = $this->_em->createQuery('SELECT p FROM ' . GH7661Participant::class . ' p INDEX BY p.user')->getResult();
        $keys         = array_keys($participants);
        self::assertEquals([1, 2], $keys);
    }
}

/**
 * @Entity
 */
class GH7661User
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/**
 * @Entity
 */
class GH7661Event
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
    /** @OneToMany(targetEntity=GH7661Participant::class, mappedBy="event", indexBy="user_id") */
    public $participants;
}

/**
 * @Entity
 */
class GH7661Participant
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
    /** @ManyToOne(targetEntity=GH7661User::class) */
    public $user;
    /** @ManyToOne(targetEntity=GH7661Event::class) */
    public $event;

    public function __construct(GH7661User $user, GH7661Event $event)
    {
        $this->user  = $user;
        $this->event = $event;
    }
}
