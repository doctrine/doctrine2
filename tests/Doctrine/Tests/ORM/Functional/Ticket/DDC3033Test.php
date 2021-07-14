<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\PostUpdate;
use Doctrine\ORM\Mapping\PreUpdate;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function get_class;

/**
 * @group DDC-3033
 */
class DDC3033Test extends OrmFunctionalTestCase
{
    public function testIssue(): void
    {
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC3033User::class),
                $this->_em->getClassMetadata(DDC3033Product::class),
            ]
        );

        $user       = new DDC3033User();
        $user->name = 'Test User';
        $this->_em->persist($user);

        $user2       = new DDC3033User();
        $user2->name = 'Test User 2';
        $this->_em->persist($user2);

        $product           = new DDC3033Product();
        $product->title    = 'Test product';
        $product->buyers[] = $user;

        $this->_em->persist($product);
        $this->_em->flush();

        $product->title    = 'Test Change title';
        $product->buyers[] = $user2;

        $this->_em->persist($product);
        $this->_em->flush();

        $expect = [
            'title' => [
                0 => 'Test product',
                1 => 'Test Change title',
            ],
        ];

        $this->assertEquals($expect, $product->changeSet);
    }
}

/**
 * @Table
 * @Entity @HasLifecycleCallbacks
 */
class DDC3033Product
{
    /** @psalm-var array<string, array{mixed, mixed}> */
    public $changeSet = [];

    /**
     * @var int $id
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string $title
     * @Column(name="title", type="string", length=255)
     */
    public $title;

    /**
     * @var Collection<int, DDC3033User>
     * @ManyToMany(targetEntity="DDC3033User")
     * @JoinTable(
     *   name="user_purchases_3033",
     *   joinColumns={@JoinColumn(name="product_id", referencedColumnName="id")},
     *   inverseJoinColumns={@JoinColumn(name="user_id", referencedColumnName="id")}
     * )
     */
    public $buyers;

    /**
     * Default constructor
     */
    public function __construct()
    {
        $this->buyers = new ArrayCollection();
    }

    /**
     * @PreUpdate
     */
    public function preUpdate(LifecycleEventArgs $eventArgs): void
    {
    }

    /**
     * @PostUpdate
     */
    public function postUpdate(LifecycleEventArgs $eventArgs): void
    {
        $em            = $eventArgs->getEntityManager();
        $uow           = $em->getUnitOfWork();
        $entity        = $eventArgs->getEntity();
        $classMetadata = $em->getClassMetadata(get_class($entity));

        $uow->computeChangeSet($classMetadata, $entity);
        $this->changeSet = $uow->getEntityChangeSet($entity);
    }
}

/**
 * @Table
 * @Entity @HasLifecycleCallbacks
 */
class DDC3033User
{
    /**
     * @var int
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(name="title", type="string", length=255)
     */
    public $name;
}
