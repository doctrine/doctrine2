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
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Tests\OrmFunctionalTestCase;

use function get_class;

class DDC422Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC422Guest::class),
                $this->_em->getClassMetadata(DDC422Customer::class),
                $this->_em->getClassMetadata(DDC422Contact::class),
            ]
        );
    }

    /**
     * @group DDC-422
     */
    public function testIssue(): void
    {
        $customer = new DDC422Customer();
        $this->_em->persist($customer);
        $this->_em->flush();
        $this->_em->clear();

        $customer = $this->_em->find(get_class($customer), $customer->id);

        $this->assertInstanceOf(PersistentCollection::class, $customer->contacts);
        $this->assertFalse($customer->contacts->isInitialized());
        $contact = new DDC422Contact();
        $customer->contacts->add($contact);
        $this->assertTrue($customer->contacts->isDirty());
        $this->assertFalse($customer->contacts->isInitialized());
        $this->_em->flush();

        $this->assertEquals(1, $this->_em->getConnection()->fetchColumn('select count(*) from ddc422_customers_contacts'));
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"guest" = "DDC422Guest", "customer" = "DDC422Customer"})
 */
class DDC422Guest
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/** @Entity */
class DDC422Customer extends DDC422Guest
{
    /**
     * @var Collection<int, DDC422Contact>
     * @ManyToMany(targetEntity="DDC422Contact", cascade={"persist","remove"})
     * @JoinTable(name="ddc422_customers_contacts",
     *      joinColumns={@JoinColumn(name="customer_id", referencedColumnName="id", onDelete="cascade" )},
     *      inverseJoinColumns={@JoinColumn(name="contact_id", referencedColumnName="id", onDelete="cascade" )}
     *  )
     */
    public $contacts;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
    }
}

/** @Entity */
class DDC422Contact
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}
