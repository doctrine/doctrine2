<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('GH-9109')]
class GH9109Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(GH9109User::class, GH9109Product::class);
    }

    public function testIssue(): void
    {
        $userFirstName = 'GH9109Test';
        $userLastName  = 'UserGH9109';
        $productTitle  = 'Test product';

        $userRepository = $this->_em->getRepository(GH9109User::class);

        $user = new GH9109User();
        $user->setFirstName($userFirstName);
        $user->setLastName($userLastName);

        $product = new GH9109Product();
        $product->setTitle($productTitle);

        $this->_em->persist($user);
        $this->_em->persist($product);
        $this->_em->flush();

        $product->addBuyer($user);

        $this->_em->persist($product);
        $this->_em->flush();

        $this->_em->clear();

        $persistedProduct = $this->_em->find(GH9109Product::class, $product->getId());

        // assert Product was persisted
        self::assertInstanceOf(GH9109Product::class, $persistedProduct);
        self::assertEquals($productTitle, $persistedProduct->getTitle());

        // assert Product has a Buyer
        $count = $persistedProduct->getBuyers()->count();
        self::assertEquals(1, $count);

        // assert NOT QUOTED will WORK with findOneBy
        $user = $userRepository->findOneBy(['lastName' => $userLastName]);
        self::assertInstanceOf(GH9109User::class, $user);
        self::assertEquals($userLastName, $user->getLastName());

        // assert NOT QUOTED will WORK with Criteria
        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('lastName', $userLastName));
        $user = $persistedProduct->getBuyers()->matching($criteria)->first();
        self::assertInstanceOf(GH9109User::class, $user);
        self::assertEquals($userLastName, $user->getLastName());

        // assert QUOTED will WORK with findOneBy
        $user = $userRepository->findOneBy(['firstName' => $userFirstName]);
        self::assertInstanceOf(GH9109User::class, $user);
        self::assertEquals($userFirstName, $user->getFirstName());

        // assert QUOTED will WORK with Criteria
        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('firstName', $userFirstName));
        $user = $persistedProduct->getBuyers()->matching($criteria)->first();
        self::assertInstanceOf(GH9109User::class, $user);
        self::assertEquals($userFirstName, $user->getFirstName());
    }
}

#[Entity]
class GH9109Product
{
    #[Column(name: '`id`', type: 'integer')]
    #[Id]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[Column(name: '`title`', type: 'string', length: 255)]
    private string|null $title = null;

    /**
     * @var Collection|GH9109User[]
     * @phpstan-var Collection<int, GH9109User>
     */
    #[ManyToMany(targetEntity: 'GH9109User')]
    private $buyers;

    public function __construct()
    {
        $this->buyers = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @phpstan-return Collection<int, GH9109User> */
    public function getBuyers(): Collection
    {
        return $this->buyers;
    }

    public function addBuyer(GH9109User $buyer): void
    {
        $this->buyers[] = $buyer;
    }
}

#[Entity]
class GH9109User
{
    #[Column(name: '`id`', type: 'integer')]
    #[Id]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[Column(name: '`first_name`', type: 'string', length: 255)]
    private string|null $firstName = null;

    #[Column(name: 'last_name', type: 'string', length: 255)]
    private string|null $lastName = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }
}
