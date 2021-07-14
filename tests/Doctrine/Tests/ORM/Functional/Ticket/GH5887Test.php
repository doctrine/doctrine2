<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

/**
 * @group GH-5887
 */
class GH5887Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Type::addType(GH5887CustomIdObjectType::NAME, GH5887CustomIdObjectType::class);

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(GH5887Cart::class),
                $this->_em->getClassMetadata(GH5887Customer::class),
            ]
        );
    }

    public function testLazyLoadsForeignEntitiesInOneToOneRelationWhileHavingCustomIdObject(): void
    {
        $customerId = new GH5887CustomIdObject(1);
        $customer   = new GH5887Customer();
        $customer->setId($customerId);

        $cartId = 2;
        $cart   = new GH5887Cart();
        $cart->setId($cartId);
        $cart->setCustomer($customer);

        $this->_em->persist($customer);
        $this->_em->persist($cart);
        $this->_em->flush();

        // Clearing cached entities
        $this->_em->clear();

        $customerRepository = $this->_em->getRepository(GH5887Customer::class);
        $customer           = $customerRepository->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $customerId->getId())
            ->getQuery()
            ->getOneOrNullResult();
        assert($customer instanceof GH5887Customer);

        $this->assertInstanceOf(GH5887Cart::class, $customer->getCart());
    }
}

/**
 * @Entity
 */
class GH5887Cart
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="NONE")
     */
    private $id;

    /**
     * One Cart has One Customer.
     *
     * @var GH5887Customer
     * @OneToOne(targetEntity="GH5887Customer", inversedBy="cart")
     * @JoinColumn(name="customer_id", referencedColumnName="id")
     */
    private $customer;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getCustomer(): GH5887Customer
    {
        return $this->customer;
    }

    public function setCustomer(GH5887Customer $customer): void
    {
        if ($this->customer !== $customer) {
            $this->customer = $customer;
            $customer->setCart($this);
        }
    }
}

/**
 * @Entity
 */
class GH5887Customer
{
    /**
     * @var GH5887CustomIdObject
     * @Id
     * @Column(type="GH5887CustomIdObject")
     * @GeneratedValue(strategy="NONE")
     */
    private $id;

    /**
     * One Customer has One Cart.
     *
     * @var GH5887Cart
     * @OneToOne(targetEntity="GH5887Cart", mappedBy="customer")
     */
    private $cart;

    public function getId(): GH5887CustomIdObject
    {
        return $this->id;
    }

    public function setId(GH5887CustomIdObject $id): void
    {
        $this->id = $id;
    }

    public function getCart(): GH5887Cart
    {
        return $this->cart;
    }

    public function setCart(GH5887Cart $cart): void
    {
        if ($this->cart !== $cart) {
            $this->cart = $cart;
            $cart->setCustomer($this);
        }
    }
}

class GH5887CustomIdObject
{
    /** @var int */
    private $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return 'non existing id';
    }
}

class GH5887CustomIdObjectType extends StringType
{
    public const NAME = 'GH5887CustomIdObject';

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        return $value->getId();
    }

    /**
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        return new GH5887CustomIdObject((int) $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }
}
