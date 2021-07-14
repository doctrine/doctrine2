<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\Tests\Models\ECommerce\ECommerceCart;
use Doctrine\Tests\OrmTestCase;

class SchemaValidatorTest extends OrmTestCase
{
    /** @var EntityManager */
    private $em = null;

    /** @var SchemaValidator */
    private $validator = null;

    protected function setUp(): void
    {
        $this->em        = $this->getTestEntityManager();
        $this->validator = new SchemaValidator($this->em);
    }

    /**
     * @dataProvider modelSetProvider
     */
    public function testCmsModelSet(string $path): void
    {
        $this->em->getConfiguration()
                 ->getMetadataDriverImpl()
                 ->addPaths([$path]);

        self::assertEmpty($this->validator->validateMapping());
    }

    public function modelSetProvider(): array
    {
        return [
            'cms'        => [__DIR__ . '/../../Models/CMS'],
            'company'    => [__DIR__ . '/../../Models/Company'],
            'ecommerce'  => [__DIR__ . '/../../Models/ECommerce'],
            'forum'      => [__DIR__ . '/../../Models/Forum'],
            'navigation' => [__DIR__ . '/../../Models/Navigation'],
            'routing'    => [__DIR__ . '/../../Models/Routing'],
        ];
    }

    /**
     * @group DDC-1439
     */
    public function testInvalidManyToManyJoinColumnSchema(): void
    {
        $class1 = $this->em->getClassMetadata(InvalidEntity1::class);
        $class2 = $this->em->getClassMetadata(InvalidEntity2::class);

        $ce = $this->validator->validateClass($class1);

        $this->assertEquals(
            [
                "The inverse join columns of the many-to-many table 'Entity1Entity2' have to contain to ALL identifier columns of the target entity 'Doctrine\Tests\ORM\Tools\InvalidEntity2', however 'key4' are missing.",
                "The join columns of the many-to-many table 'Entity1Entity2' have to contain to ALL identifier columns of the source entity 'Doctrine\Tests\ORM\Tools\InvalidEntity1', however 'key2' are missing.",
            ],
            $ce
        );
    }

    /**
     * @group DDC-1439
     */
    public function testInvalidToOneJoinColumnSchema(): void
    {
        $class1 = $this->em->getClassMetadata(InvalidEntity1::class);
        $class2 = $this->em->getClassMetadata(InvalidEntity2::class);

        $ce = $this->validator->validateClass($class2);

        $this->assertEquals(
            [
                "The referenced column name 'id' has to be a primary key column on the target entity class 'Doctrine\Tests\ORM\Tools\InvalidEntity1'.",
                "The join columns of the association 'assoc' have to match to ALL identifier columns of the target entity 'Doctrine\Tests\ORM\Tools\InvalidEntity1', however 'key1, key2' are missing.",
            ],
            $ce
        );
    }

    /**
     * @group DDC-1587
     */
    public function testValidOneToOneAsIdentifierSchema(): void
    {
        $class1 = $this->em->getClassMetadata(DDC1587ValidEntity2::class);
        $class2 = $this->em->getClassMetadata(DDC1587ValidEntity1::class);

        $ce = $this->validator->validateClass($class1);

        $this->assertEquals([], $ce);
    }

    /**
     * @group DDC-1649
     */
    public function testInvalidTripleAssociationAsKeyMapping(): void
    {
        $classThree = $this->em->getClassMetadata(DDC1649Three::class);
        $ce         = $this->validator->validateClass($classThree);

        $this->assertEquals(
            [
                "Cannot map association 'Doctrine\Tests\ORM\Tools\DDC1649Three#two as identifier, because the target entity 'Doctrine\Tests\ORM\Tools\DDC1649Two' also maps an association as identifier.",
                "The referenced column name 'id' has to be a primary key column on the target entity class 'Doctrine\Tests\ORM\Tools\DDC1649Two'.",
            ],
            $ce
        );
    }

    /**
     * @group DDC-3274
     */
    public function testInvalidBiDirectionalRelationMappingMissingInversedByAttribute(): void
    {
        $class = $this->em->getClassMetadata(DDC3274One::class);
        $ce    = $this->validator->validateClass($class);

        $this->assertEquals(
            [
                'The field Doctrine\Tests\ORM\Tools\DDC3274One#two is on the inverse side of a bi-directional ' .
                'relationship, but the specified mappedBy association on the target-entity ' .
                "Doctrine\Tests\ORM\Tools\DDC3274Two#one does not contain the required 'inversedBy=\"two\"' attribute.",
            ],
            $ce
        );
    }

    /**
     * @group DDC-3322
     */
    public function testInvalidOrderByInvalidField(): void
    {
        $class = $this->em->getClassMetadata(DDC3322One::class);
        $ce    = $this->validator->validateClass($class);

        $this->assertEquals(
            [
                'The association Doctrine\Tests\ORM\Tools\DDC3322One#invalidAssoc is ordered by a foreign field ' .
                'invalidField that is not a field on the target entity Doctrine\Tests\ORM\Tools\DDC3322ValidEntity1.',
            ],
            $ce
        );
    }

    /**
     * @group DDC-3322
     */
    public function testInvalidOrderByCollectionValuedAssociation(): void
    {
        $class = $this->em->getClassMetadata(DDC3322Two::class);
        $ce    = $this->validator->validateClass($class);

        $this->assertEquals(
            [
                'The association Doctrine\Tests\ORM\Tools\DDC3322Two#invalidAssoc is ordered by a field oneToMany ' .
                'on Doctrine\Tests\ORM\Tools\DDC3322ValidEntity1 that is a collection-valued association.',
            ],
            $ce
        );
    }

    /**
     * @group DDC-3322
     */
    public function testInvalidOrderByAssociationInverseSide(): void
    {
        $class = $this->em->getClassMetadata(DDC3322Three::class);
        $ce    = $this->validator->validateClass($class);

        $this->assertEquals(
            [
                'The association Doctrine\Tests\ORM\Tools\DDC3322Three#invalidAssoc is ordered by a field oneToOneInverse ' .
                'on Doctrine\Tests\ORM\Tools\DDC3322ValidEntity1 that is the inverse side of an association.',
            ],
            $ce
        );
    }

    /**
     * @group 8052
     */
    public function testInvalidAssociationInsideEmbeddable(): void
    {
        $class = $this->em->getClassMetadata(EmbeddableWithAssociation::class);
        $ce    = $this->validator->validateClass($class);

        $this->assertEquals(
            ["Embeddable 'Doctrine\Tests\ORM\Tools\EmbeddableWithAssociation' does not support associations"],
            $ce
        );
    }
}

/**
 * @Entity
 */
class InvalidEntity1
{
    /**
     * @var mixed
     * @Id
     * @Column
     */
    protected $key1;

    /**
     * @var mixed
     * @Id
     * @Column
     */
    protected $key2;

    /**
     * @var ArrayCollection
     * @ManyToMany (targetEntity="InvalidEntity2")
     * @JoinTable (name="Entity1Entity2",
     *      joinColumns={@JoinColumn(name="key1", referencedColumnName="key1")},
     *      inverseJoinColumns={@JoinColumn(name="key3", referencedColumnName="key3")}
     * )
     */
    protected $entity2;
}

/**
 * @Entity
 */
class InvalidEntity2
{
    /**
     * @var mixed
     * @Id
     * @Column
     */
    protected $key3;

    /**
     * @var mixed
     * @Id
     * @Column
     */
    protected $key4;

    /**
     * @var InvalidEntity1
     * @ManyToOne(targetEntity="InvalidEntity1")
     */
    protected $assoc;
}

/**
 * @Entity(repositoryClass="Entity\Repository\Agent")
 * @Table(name="agent")
 */
class DDC1587ValidEntity1
{
    /**
     * @var int
     * @Id @GeneratedValue
     * @Column(name="pk", type="integer")
     */
    private $pk;

    /**
     * @var string
     * @Column(name="name", type="string", length=32)
     */
    private $name;

    /**
     * @var Identifier
     * @OneToOne(targetEntity="DDC1587ValidEntity2", cascade={"all"}, mappedBy="agent")
     * @JoinColumn(name="pk", referencedColumnName="pk_agent")
     */
    private $identifier;
}

/**
 * @Entity
 * @Table
 */
class DDC1587ValidEntity2
{
    /**
     * @var DDC1587ValidEntity1
     * @Id
     * @OneToOne(targetEntity="DDC1587ValidEntity1", inversedBy="identifier")
     * @JoinColumn(name="pk_agent", referencedColumnName="pk", nullable=false)
     */
    private $agent;

    /**
     * @var string
     * @Column(name="num", type="string", length=16, nullable=true)
     */
    private $num;
}

/**
 * @Entity
 */
class DDC1649One
{
    /**
     * @var mixed
     * @Id
     * @Column
     * @GeneratedValue
     */
    public $id;
}

/**
 * @Entity
 */
class DDC1649Two
{
    /**
     * @var DDC1649One
     * @Id @ManyToOne(targetEntity="DDC1649One")@JoinColumn(name="id", referencedColumnName="id")
     */
    public $one;
}

/**
 * @Entity
 */
class DDC1649Three
{
    /**
     * @var DDC1649Two
     * @Id
     * @ManyToOne(targetEntity="DDC1649Two")
     * @JoinColumn(name="id", referencedColumnName="id")
     */
    private $two;
}

/**
 * @Entity
 */
class DDC3274One
{
    /**
     * @var mixed
     * @Id
     * @Column
     * @GeneratedValue
     */
    private $id;

    /**
     * @var ArrayCollection
     * @OneToMany(targetEntity="DDC3274Two", mappedBy="one")
     */
    private $two;
}

/**
 * @Entity
 */
class DDC3274Two
{
    /**
     * @var DDC3274One
     * @Id
     * @ManyToOne(targetEntity="DDC3274One")
     */
    private $one;
}

/**
 * @Entity
 */
class DDC3322ValidEntity1
{
    /**
     * @var mixed
     * @Id
     * @Column
     * @GeneratedValue
     */
    private $id;

    /**
     * @var DDC3322One
     * @ManyToOne(targetEntity="DDC3322One", inversedBy="validAssoc")
     */
    private $oneValid;

    /**
     * @var DDC3322One
     * @ManyToOne(targetEntity="DDC3322One", inversedBy="invalidAssoc")
     */
    private $oneInvalid;

    /**
     * @var DDC3322Two
     * @ManyToOne(targetEntity="DDC3322Two", inversedBy="validAssoc")
     */
    private $twoValid;

    /**
     * @var DDC3322Two
     * @ManyToOne(targetEntity="DDC3322Two", inversedBy="invalidAssoc")
     */
    private $twoInvalid;

    /**
     * @var DDC3322Three
     * @ManyToOne(targetEntity="DDC3322Three", inversedBy="validAssoc")
     */
    private $threeValid;

    /**
     * @var DDC3322Three
     * @ManyToOne(targetEntity="DDC3322Three", inversedBy="invalidAssoc")
     */
    private $threeInvalid;

    /**
     * @var DDC3322ValidEntity2
     * @OneToMany(targetEntity="DDC3322ValidEntity2", mappedBy="manyToOne")
     */
    private $oneToMany;

    /**
     * @var DDC3322ValidEntity2
     * @ManyToOne(targetEntity="DDC3322ValidEntity2", inversedBy="oneToMany")
     */
    private $manyToOne;

    /**
     * @var DDC3322ValidEntity2
     * @OneToOne(targetEntity="DDC3322ValidEntity2", mappedBy="oneToOneOwning")
     */
    private $oneToOneInverse;

    /**
     * @var DDC3322ValidEntity2
     * @OneToOne(targetEntity="DDC3322ValidEntity2", inversedBy="oneToOneInverse")
     */
    private $oneToOneOwning;
}

/**
 * @Entity
 */
class DDC3322ValidEntity2
{
    /**
     * @var int
     * @Id
     * @Column
     * @GeneratedValue
     */
    private $id;

    /**
     * @var DDC3322ValidEntity1
     * @ManyToOne(targetEntity="DDC3322ValidEntity1", inversedBy="oneToMany")
     */
    private $manyToOne;

    /**
     * @var DDC3322ValidEntity1
     * @OneToMany(targetEntity="DDC3322ValidEntity1", mappedBy="manyToOne")
     */
    private $oneToMany;

    /**
     * @var DDC3322ValidEntity1
     * @OneToOne(targetEntity="DDC3322ValidEntity1", inversedBy="oneToOneInverse")
     */
    private $oneToOneOwning;

    /**
     * @var DDC3322ValidEntity1
     * @OneToOne(targetEntity="DDC3322ValidEntity1", mappedBy="oneToOneOwning")
     */
    private $oneToOneInverse;
}

/**
 * @Entity
 */
class DDC3322One
{
    /**
     * @var int
     * @Id
     * @Column
     * @GeneratedValue
     */
    private $id;

    /**
     * @psalm-var Collection<int, DDC3322ValidEntity1>
     * @OneToMany(targetEntity="DDC3322ValidEntity1", mappedBy="oneValid")
     * @OrderBy({"id" = "ASC"})
     */
    private $validAssoc;

    /**
     * @psalm-var Collection<int, DDC3322ValidEntity1>
     * @OneToMany(targetEntity="DDC3322ValidEntity1", mappedBy="oneInvalid")
     * @OrderBy({"invalidField" = "ASC"})
     */
    private $invalidAssoc;
}

/**
 * @Entity
 */
class DDC3322Two
{
    /**
     * @var int
     * @Id
     * @Column
     * @GeneratedValue
     */
    private $id;

    /**
     * @psalm-var Collection<int, DDC3322ValidEntity1>
     * @OneToMany(targetEntity="DDC3322ValidEntity1", mappedBy="twoValid")
     * @OrderBy({"manyToOne" = "ASC"})
     */
    private $validAssoc;

    /**
     * @psalm-var Collection<int, DDC3322ValidEntity1>
     * @OneToMany(targetEntity="DDC3322ValidEntity1", mappedBy="twoInvalid")
     * @OrderBy({"oneToMany" = "ASC"})
     */
    private $invalidAssoc;
}

/**
 * @Entity
 */
class DDC3322Three
{
    /**
     * @var int
     * @Id
     * @Column
     * @GeneratedValue
     */
    private $id;

    /**
     * @var DDC3322ValidEntity1
     * @OneToMany(targetEntity="DDC3322ValidEntity1", mappedBy="threeValid")
     * @OrderBy({"oneToOneOwning" = "ASC"})
     */
    private $validAssoc;

    /**
     * @psalm-var Collection<int, DDC3322ValidEntity1>
     * @OneToMany(targetEntity="DDC3322ValidEntity1", mappedBy="threeInvalid")
     * @OrderBy({"oneToOneInverse" = "ASC"})
     */
    private $invalidAssoc;
}

/**
 * @Embeddable
 */
class EmbeddableWithAssociation
{
    /**
     * @var ECommerceCart
     * @OneToOne(targetEntity="Doctrine\Tests\Models\ECommerce\ECommerceCart")
     */
    private $cart;
}
