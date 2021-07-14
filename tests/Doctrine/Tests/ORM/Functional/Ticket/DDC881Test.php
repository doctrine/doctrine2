<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function count;

class DDC881Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC881User::class),
                    $this->_em->getClassMetadata(DDC881Phonenumber::class),
                    $this->_em->getClassMetadata(DDC881Phonecall::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    /**
     * @group DDC-117
     * @group DDC-881
     */
    public function testIssue(): void
    {
        /* Create two test users: albert and alfons */
        $albert = new DDC881User();
        $albert->setName('albert');
        $this->_em->persist($albert);

        $alfons = new DDC881User();
        $alfons->setName('alfons');
        $this->_em->persist($alfons);

        $this->_em->flush();

        /* Assign two phone numbers to each user */
        $phoneAlbert1 = new DDC881PhoneNumber();
        $phoneAlbert1->setUser($albert);
        $phoneAlbert1->setId(1);
        $phoneAlbert1->setPhoneNumber('albert home: 012345');
        $this->_em->persist($phoneAlbert1);

        $phoneAlbert2 = new DDC881PhoneNumber();
        $phoneAlbert2->setUser($albert);
        $phoneAlbert2->setId(2);
        $phoneAlbert2->setPhoneNumber('albert mobile: 67890');
        $this->_em->persist($phoneAlbert2);

        $phoneAlfons1 = new DDC881PhoneNumber();
        $phoneAlfons1->setId(1);
        $phoneAlfons1->setUser($alfons);
        $phoneAlfons1->setPhoneNumber('alfons home: 012345');
        $this->_em->persist($phoneAlfons1);

        $phoneAlfons2 = new DDC881PhoneNumber();
        $phoneAlfons2->setId(2);
        $phoneAlfons2->setUser($alfons);
        $phoneAlfons2->setPhoneNumber('alfons mobile: 67890');
        $this->_em->persist($phoneAlfons2);

        /* We call alfons and albert once on their mobile numbers */
        $call1 = new DDC881PhoneCall();
        $call1->setPhoneNumber($phoneAlfons2);
        $this->_em->persist($call1);

        $call2 = new DDC881PhoneCall();
        $call2->setPhoneNumber($phoneAlbert2);
        $this->_em->persist($call2);

        $this->_em->flush();
        $this->_em->clear();

        // fetch-join that foreign-key/primary-key entity association
        $dql   = 'SELECT c, p FROM ' . DDC881PhoneCall::class . ' c JOIN c.phonenumber p';
        $calls = $this->_em->createQuery($dql)->getResult();

        $this->assertEquals(2, count($calls));
        $this->assertNotInstanceOf(Proxy::class, $calls[0]->getPhoneNumber());
        $this->assertNotInstanceOf(Proxy::class, $calls[1]->getPhoneNumber());

        $dql     = 'SELECT p, c FROM ' . DDC881PhoneNumber::class . ' p JOIN p.calls c';
        $numbers = $this->_em->createQuery($dql)->getResult();

        $this->assertEquals(2, count($numbers));
        $this->assertInstanceOf(PersistentCollection::class, $numbers[0]->getCalls());
        $this->assertTrue($numbers[0]->getCalls()->isInitialized());
    }
}

/**
 * @Entity
 */
class DDC881User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string")
     */
    private $name;

    /**
     * @psalm-var Collection<int, DDC881PhoneNumber>
     * @OneToMany(targetEntity="DDC881PhoneNumber",mappedBy="id")
     */
    private $phoneNumbers;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}

/**
 * @Entity
 */
class DDC881PhoneNumber
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    private $id;

    /**
     * @var DDC881User
     * @Id
     * @ManyToOne(targetEntity="DDC881User",cascade={"all"})
     */
    private $user;

    /**
     * @var string
     * @Column(type="string")
     */
    private $phonenumber;

    /**
     * @psalm-var Collection<int, DDC881PhoneCall>
     * @OneToMany(targetEntity="DDC881PhoneCall", mappedBy="phonenumber")
     */
    private $calls;

    public function __construct()
    {
        $this->calls = new ArrayCollection();
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function setUser(DDC881User $user): void
    {
        $this->user = $user;
    }

    public function setPhoneNumber(string $phoneNumber): void
    {
        $this->phonenumber = $phoneNumber;
    }

    /**
     * @psalm-var Collection<int, DDC881PhoneCall>
     */
    public function getCalls(): Collection
    {
        return $this->calls;
    }
}

/**
 * @Entity
 */
class DDC881PhoneCall
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var DDC881PhoneNumber
     * @ManyToOne(targetEntity="DDC881PhoneNumber", inversedBy="calls", cascade={"all"})
     * @JoinColumns({
     *  @JoinColumn(name="phonenumber_id", referencedColumnName="id"),
     *  @JoinColumn(name="user_id", referencedColumnName="user_id")
     * })
     */
    private $phonenumber;

    /**
     * @var string
     * @Column(type="string",nullable=true)
     */
    private $callDate;

    public function setPhoneNumber(DDC881PhoneNumber $phoneNumber): void
    {
        $this->phonenumber = $phoneNumber;
    }

    public function getPhoneNumber(): DDC881PhoneNumber
    {
        return $this->phonenumber;
    }
}
