<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Proxy\Proxy;
use Doctrine\ORM\Mapping\ChangeTrackingPolicy;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Tools\ToolsException;
use Doctrine\Persistence\NotifyPropertyChanged;
use Doctrine\Persistence\PropertyChangedListener;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

/**
 * @group DDC-2230
 */
class DDC2230Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC2230User::class),
                    $this->_em->getClassMetadata(DDC2230Address::class),
                ]
            );
        } catch (ToolsException $e) {
        }
    }

    public function testNotifyTrackingNotCalledOnUninitializedProxies(): void
    {
        $insertedUser          = new DDC2230User();
        $insertedUser->address = new DDC2230Address();

        $this->_em->persist($insertedUser);
        $this->_em->persist($insertedUser->address);
        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->find(DDC2230User::class, $insertedUser->id);

        $this->_em->clear();

        $mergedUser = $this->_em->merge($user);

        $address = $mergedUser->address;
        assert($address instanceof Proxy);

        $this->assertInstanceOf(Proxy::class, $address);
        $this->assertFalse($address->__isInitialized());
    }

    public function testNotifyTrackingCalledOnProxyInitialization(): void
    {
        $insertedAddress = new DDC2230Address();

        $this->_em->persist($insertedAddress);
        $this->_em->flush();
        $this->_em->clear();

        $addressProxy = $this->_em->getReference(DDC2230Address::class, $insertedAddress->id);
        assert($addressProxy instanceof Proxy || $addressProxy instanceof DDC2230Address);

        $this->assertFalse($addressProxy->__isInitialized());
        $this->assertNull($addressProxy->listener);

        $addressProxy->__load();

        $this->assertSame($this->_em->getUnitOfWork(), $addressProxy->listener);
    }
}

/** @Entity */
class DDC2230User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var DDC2230Address
     * @OneToOne(targetEntity="DDC2230Address")
     */
    public $address;
}

/**
 * @Entity
 * @ChangeTrackingPolicy("NOTIFY")
 */
class DDC2230Address implements NotifyPropertyChanged
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /** @var \Doctrine\Common\PropertyChangedListener */
    public $listener;

    /** {@inheritDoc} */
    public function addPropertyChangedListener(PropertyChangedListener $listener)
    {
        $this->listener = $listener;
    }
}
