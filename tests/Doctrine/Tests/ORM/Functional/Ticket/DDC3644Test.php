<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Functional tests for orphan removal with one to many association.
 */
class DDC3644Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema(
            [
                DDC3644User::class,
                DDC3644Address::class,
                DDC3644Animal::class,
                DDC3644Pet::class,
            ]
        );
    }

    /**
     * @group DDC-3644
     */
    public function testIssueWithRegularEntity(): void
    {
        // Define initial dataset
        $current   = new DDC3644Address('Sao Paulo, SP, Brazil');
        $previous  = new DDC3644Address('Rio de Janeiro, RJ, Brazil');
        $initial   = new DDC3644Address('Sao Carlos, SP, Brazil');
        $addresses = new ArrayCollection([$current, $previous, $initial]);
        $user      = new DDC3644User();

        $user->name = 'Guilherme Blanco';
        $user->setAddresses($addresses);

        $this->_em->persist($user);
        $this->_em->persist($current);
        $this->_em->persist($previous);
        $this->_em->persist($initial);

        $this->_em->flush();

        $userId = $user->id;
        unset($current, $previous, $initial, $addresses, $user);

        $this->_em->clear();

        // Replace entire collection (this should trigger OneToManyPersister::remove())
        $current   = new DDC3644Address('Toronto, ON, Canada');
        $addresses = new ArrayCollection([$current]);
        $user      = $this->_em->find(DDC3644User::class, $userId);

        $user->setAddresses($addresses);

        $this->_em->persist($user);
        $this->_em->persist($current);

        $this->_em->flush();
        $this->_em->clear();

        // We should only have 1 item in the collection list now
        $user = $this->_em->find(DDC3644User::class, $userId);

        $this->assertCount(1, $user->addresses);

        // We should only have 1 item in the addresses table too
        $repository = $this->_em->getRepository(DDC3644Address::class);
        $addresses  = $repository->findAll();

        $this->assertCount(1, $addresses);
    }

    /**
     * @group DDC-3644
     */
    public function testIssueWithJoinedEntity(): void
    {
        // Define initial dataset
        $actual = new DDC3644Pet('Catharina');
        $past   = new DDC3644Pet('Nanny');
        $pets   = new ArrayCollection([$actual, $past]);
        $user   = new DDC3644User();

        $user->name = 'Guilherme Blanco';
        $user->setPets($pets);

        $this->_em->persist($user);
        $this->_em->persist($actual);
        $this->_em->persist($past);

        $this->_em->flush();

        $userId = $user->id;
        unset($actual, $past, $pets, $user);

        $this->_em->clear();

        // Replace entire collection (this should trigger OneToManyPersister::remove())
        $actual = new DDC3644Pet('Valentina');
        $pets   = new ArrayCollection([$actual]);
        $user   = $this->_em->find(DDC3644User::class, $userId);

        $user->setPets($pets);

        $this->_em->persist($user);
        $this->_em->persist($actual);

        $this->_em->flush();
        $this->_em->clear();

        // We should only have 1 item in the collection list now
        $user = $this->_em->find(DDC3644User::class, $userId);

        $this->assertCount(1, $user->pets);

        // We should only have 1 item in the pets table too
        $repository = $this->_em->getRepository(DDC3644Pet::class);
        $pets       = $repository->findAll();

        $this->assertCount(1, $pets);
    }
}

/**
 * @Entity
 */
class DDC3644User
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer", name="hash_id")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @psalm-var Collection<int, DDC3644Address>
     * @OneToMany(targetEntity="DDC3644Address", mappedBy="user", orphanRemoval=true)
     */
    public $addresses = [];

    /**
     * @psalm-var Collection<int, DDC3644Pet>
     * @OneToMany(targetEntity="DDC3644Pet", mappedBy="owner", orphanRemoval=true)
     */
    public $pets = [];

    public function setAddresses(Collection $addresses): void
    {
        $this->addresses = $addresses;

        $addresses->map(function ($address): void {
            $address->user = $this;
        });
    }

    public function setPets(Collection $pets): void
    {
        $this->pets = $pets;

        $pets->map(function ($pet): void {
            $pet->owner = $this;
        });
    }
}

/**
 * @Entity
 */
class DDC3644Address
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var DDC3644User
     * @ManyToOne(targetEntity="DDC3644User", inversedBy="addresses")
     * @JoinColumn(referencedColumnName="hash_id")
     */
    public $user;

    /**
     * @var string
     * @Column(type="string")
     */
    public $address;

    public function __construct($address)
    {
        $this->address = $address;
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discriminator", type="string")
 * @DiscriminatorMap({"pet" = "DDC3644Pet"})
 */
abstract class DDC3644Animal
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    public function __construct($name)
    {
        $this->name = $name;
    }
}

/**
 * @Entity
 */
class DDC3644Pet extends DDC3644Animal
{
    /**
     * @var DDC3644User
     * @ManyToOne(targetEntity="DDC3644User", inversedBy="pets")
     * @JoinColumn(referencedColumnName="hash_id")
     */
    public $owner;
}
