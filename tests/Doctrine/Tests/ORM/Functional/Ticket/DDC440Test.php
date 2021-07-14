<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

class DDC440Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC440Phone::class),
                    $this->_em->getClassMetadata(DDC440Client::class),
                ]
            );
        } catch (Exception $e) {
            // Swallow all exceptions. We do not test the schema tool here.
        }
    }

    /**
     * @group DDC-440
     */
    public function testOriginalEntityDataEmptyWhenProxyLoadedFromTwoAssociations(): void
    {
        /* The key of the problem is that the first phone is fetched via two association, mainPhone and phones.
         *
         * You will notice that the original_entity_datas are not loaded for the first phone. (They are for the second)
         *
         * In the Client entity definition, if you define the mainPhone relation after the phones relation, both assertions pass.
         * (for the sake or this test, I defined the mainPhone relation before the phones relation)
         *
         */

        //Initialize some data
        $client = new DDC440Client();
        $client->setName('Client1');

        $phone = new DDC440Phone();
        $phone->setId(1);
        $phone->setNumber('418 111-1111');
        $phone->setClient($client);

        $phone2 = new DDC440Phone();
        $phone->setId(2);
        $phone2->setNumber('418 222-2222');
        $phone2->setClient($client);

        $client->setMainPhone($phone);

        $this->_em->persist($client);
        $this->_em->flush();
        $id = $client->getId();
        $this->_em->clear();

        $uw           = $this->_em->getUnitOfWork();
        $client       = $this->_em->find(DDC440Client::class, $id);
        $clientPhones = $client->getPhones();

        $p1 = $clientPhones[1];
        $p2 = $clientPhones[2];

        // Test the first phone.  The assertion actually failed because original entity data is not set properly.
        // This was because it is also set as MainPhone and that one is created as a proxy, not the
        // original object when the find on Client is called. However loading proxies did not work correctly.
        $this->assertInstanceOf(DDC440Phone::class, $p1);
        $originalData = $uw->getOriginalEntityData($p1);
        $this->assertEquals($phone->getNumber(), $originalData['number']);

        //If you comment out previous test, this one should pass
        $this->assertInstanceOf(DDC440Phone::class, $p2);
        $originalData = $uw->getOriginalEntityData($p2);
        $this->assertEquals($phone2->getNumber(), $originalData['number']);
    }
}

/**
 * @Entity
 * @Table(name="phone")
 */
class DDC440Phone
{
    /**
     * @var int
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var DDC440Client
     * @ManyToOne(targetEntity="DDC440Client",inversedBy="phones")
     * @JoinColumns({
     *   @JoinColumn(name="client_id", referencedColumnName="id")
     * })
     */
    protected $client;

    /**
     * @var string
     * @Column(name="phonenumber", type="string")
     */
    protected $number;

    public function setNumber(string $value): void
    {
        $this->number = $value;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setClient(DDC440Client $value, bool $updateInverse = true): void
    {
        $this->client = $value;
        if ($updateInverse) {
            $value->addPhone($this);
        }
    }

    public function getClient(): DDC440Client
    {
        return $this->client;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $value): void
    {
        $this->id = $value;
    }
}

/**
 * @Entity
 * @Table(name="client")
 */
class DDC440Client
{
    /**
     * @var int
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var DDC440Phone
     * @OneToOne(targetEntity="DDC440Phone", fetch="EAGER")
     * @JoinColumns({
     *   @JoinColumn(name="main_phone_id", referencedColumnName="id",onDelete="SET NULL")
     * })
     */
    protected $mainPhone;

    /**
     * @psalm-var Collection<int, DDC440Phone>
     * @OneToMany(targetEntity="DDC440Phone", mappedBy="client", cascade={"persist", "remove"}, fetch="EAGER", indexBy="id")
     * @OrderBy({"number"="ASC"})
     */
    protected $phones;

    /**
     * @var string
     * @Column(name="name", type="string")
     */
    protected $name;

    public function __construct()
    {
    }

    public function setName(string $value): void
    {
        $this->name = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addPhone(DDC440Phone $value): void
    {
        $this->phones[] = $value;
        $value->setClient($this, false);
    }

    /**
     * @psalm-return Collection<int, DDC440Phone>
     */
    public function getPhones(): Collection
    {
        return $this->phones;
    }

    public function setMainPhone(DDC440Phone $value): void
    {
        $this->mainPhone = $value;
    }

    public function getMainPhone(): DDC440Phone
    {
        return $this->mainPhone;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId($value): void
    {
        $this->id = $value;
    }
}
