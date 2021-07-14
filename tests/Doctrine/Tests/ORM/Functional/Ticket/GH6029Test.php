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
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\Tests\OrmFunctionalTestCase;

use function sprintf;

final class GH6029Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema(
            [
                GH6029User::class,
                GH6029Group::class,
                GH6029Group2::class,
                GH6029Product::class,
                GH6029Feature::class,
            ]
        );
    }

    /**
     * Verifies that when wrong entity is persisted via relationship field, the error message does not correctly state
     * the expected class name.
     *
     * @group GH-6029
     */
    public function testManyToManyAssociation(): void
    {
        $user = new GH6029User();
        $user->groups->add(new GH6029Group2());

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Expected value of type "%s" for association field "%s#$groups", got "%s" instead.',
                GH6029Group::class,
                GH6029User::class,
                GH6029Group2::class
            )
        );

        $this->_em->persist($user);
        $this->_em->flush();
    }

    /**
     * Verifies that when wrong entity is persisted via relationship field, the error message does not correctly state
     * the expected class name.
     *
     * @group GH-6029
     */
    public function testOneToManyAssociation(): void
    {
        $product = new GH6029Product();
        $product->features->add(new GH6029Group2());

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Expected value of type "%s" for association field "%s#$features", got "%s" instead.',
                GH6029Feature::class,
                GH6029Product::class,
                GH6029Group2::class
            )
        );

        $this->_em->persist($product);
        $this->_em->flush();
    }
}

/** @Entity */
class GH6029User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int, GH6029Group>
     * @ManyToMany(targetEntity=GH6029Group::class, cascade={"all"})
     */
    public $groups;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }
}

/** @Entity */
class GH6029Group
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
class GH6029Group2
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
class GH6029Product
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int,GH6029Feature>
     * @OneToMany(targetEntity=GH6029Feature::class, mappedBy="product", cascade={"all"})
     */
    public $features;

    public function __construct()
    {
        $this->features = new ArrayCollection();
    }
}

/** @Entity */
class GH6029Feature
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var GH6029Product
     * @ManyToOne(targetEntity=GH6029Product::class, inversedBy="features")
     * @JoinColumn(name="product_id", referencedColumnName="id")
     */
    public $product;
}
