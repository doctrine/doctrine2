<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use Stringable;

use function assert;

#[Group('GH-5887')]
class GH5887Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(GH5887CustomIdObjectType::NAME, GH5887CustomIdObjectType::class);

        $this->createSchemaForModels(GH5887Cart::class, GH5887Customer::class);
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

        self::assertInstanceOf(GH5887Cart::class, $customer->getCart());
    }
}

#[Entity]
class GH5887Cart
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'NONE')]
    private int|null $id = null;

    /**
     * One Cart has One Customer.
     */
    #[OneToOne(targetEntity: 'GH5887Customer', inversedBy: 'cart')]
    #[JoinColumn(name: 'customer_id', referencedColumnName: 'id')]
    private GH5887Customer|null $customer = null;

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

#[Entity]
class GH5887Customer
{
    #[Id]
    #[Column(type: 'GH5887CustomIdObject', length: 255)]
    #[GeneratedValue(strategy: 'NONE')]
    private GH5887CustomIdObject|null $id = null;

    /**
     * One Customer has One Cart.
     */
    #[OneToOne(targetEntity: 'GH5887Cart', mappedBy: 'customer')]
    private GH5887Cart|null $cart = null;

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

class GH5887CustomIdObject implements Stringable
{
    public function __construct(private int $id)
    {
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
    final public const string NAME = 'GH5887CustomIdObject';

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        return $value->getId();
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): GH5887CustomIdObject
    {
        return new GH5887CustomIdObject((int) $value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
