<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\Proxy\Proxy;

/**
 * @group DDC-2306
 */
class DDC2306Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        parent::setUp();

        $this->schemaTool->createSchema(
            [
            $this->em->getClassMetadata(DDC2306Zone::class),
            $this->em->getClassMetadata(DDC2306User::class),
            $this->em->getClassMetadata(DDC2306Address::class),
            $this->em->getClassMetadata(DDC2306UserAddress::class),
            ]
        );
    }

    /**
     * Verifies that when eager loading is triggered, proxies are kept managed.
     *
     * The problem resides in the refresh hint passed to {@see \Doctrine\ORM\UnitOfWork::createEntity},
     * which, as of DDC-1734, causes the proxy to be marked as un-managed.
     * The check against the identity map only uses the identifier hash and the passed in class name, and
     * does not take into account the fact that the set refresh hint may be for an entity of a different
     * type from the one passed to {@see \Doctrine\ORM\UnitOfWork::createEntity}
     *
     * As a result, a refresh requested for an entity `Foo` with identifier `123` may cause a proxy
     * of type `Bar` with identifier `123` to be marked as un-managed.
     */
    public function testIssue()
    {
        $zone          = new DDC2306Zone();
        $user          = new DDC2306User;
        $address       = new DDC2306Address;
        $userAddress   = new DDC2306UserAddress($user, $address);
        $user->zone    = $zone;
        $address->zone = $zone;

        $this->em->persist($zone);
        $this->em->persist($user);
        $this->em->persist($address);
        $this->em->persist($userAddress);
        $this->em->flush();
        $this->em->clear();

        /* @var $address DDC2306Address */
        $address = $this->em->find(DDC2306Address::class, $address->id);
        /* @var $user DDC2306User|Proxy */
        $user    = $address->users->first()->user;

        self::assertInstanceOf(Proxy::class, $user);
        self::assertInstanceOf(DDC2306User::class, $user);

        $userId = $user->id;

        self::assertNotNull($userId);

        $user->__load();

        self::assertEquals(
            $userId,
            $user->id,
            'As of DDC-1734, the identifier is NULL for un-managed proxies. The identifier should be an integer here'
        );
    }
}

/** @ORM\Entity */
class DDC2306Zone
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue */
    public $id;
}

/**
 * @ORM\Entity
 */
class DDC2306User
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue */
    public $id;

    /**
     * @var DDC2306UserAddress[]|\Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="DDC2306UserAddress", mappedBy="user")
     */
    public $addresses;

    /** @ORM\ManyToOne(targetEntity="DDC2306Zone", fetch="EAGER") */
    public $zone;

    /** Constructor */
    public function __construct() {
        $this->addresses = new ArrayCollection();
    }
}

/** @ORM\Entity */
class DDC2306Address
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue */
    public $id;

    /**
     * @var DDC2306UserAddress[]|\Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="DDC2306UserAddress", mappedBy="address", orphanRemoval=true)
     */
    public $users;

    /** @ORM\ManyToOne(targetEntity="DDC2306Zone", fetch="EAGER") */
    public $zone;

    /** Constructor */
    public function __construct() {
        $this->users = new ArrayCollection();
    }
}

/** @ORM\Entity */
class DDC2306UserAddress
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue */
    public $id;

    /** @ORM\ManyToOne(targetEntity="DDC2306User") */
    public $user;

    /** @ORM\ManyToOne(targetEntity="DDC2306Address", fetch="LAZY") */
    public $address;

    /** Constructor */
    public function __construct(DDC2306User $user, DDC2306Address $address)
    {
        $this->user    = $user;
        $this->address = $address;

        $user->addresses->add($this);
        $address->users->add($this);
    }
}
