<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

class DDC3785Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Type::addType('ddc3785_asset_id', DDC3785AssetIdType::class);

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC3785Asset::class),
                    $this->_em->getClassMetadata(DDC3785AssetId::class),
                    $this->_em->getClassMetadata(DDC3785Attribute::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    /**
     * @group DDC-3785
     */
    public function testOwningValueObjectIdIsCorrectlyTransformedWhenRemovingOrphanedChildEntities(): void
    {
        $id = new DDC3785AssetId('919609ba-57d9-4a13-be1d-d202521e858a');

        $attributes = [
            $attribute1 = new DDC3785Attribute('foo1', 'bar1'),
            $attribute2 = new DDC3785Attribute('foo2', 'bar2'),
        ];

        $this->_em->persist($asset = new DDC3785Asset($id, $attributes));
        $this->_em->flush();

        $asset->getAttributes()
              ->removeElement($attribute1);

        $idToBeRemoved = $attribute1->id;

        $this->_em->persist($asset);
        $this->_em->flush();

        self::assertNull($this->_em->find(DDC3785Attribute::class, $idToBeRemoved));
        self::assertNotNull($this->_em->find(DDC3785Attribute::class, $attribute2->id));
    }
}

/**
 * @Entity
 * @Table(name="asset")
 */
class DDC3785Asset
{
    /**
     * @var DDC3785AssetId
     * @Id
     * @GeneratedValue(strategy="NONE")
     * @Column(type="ddc3785_asset_id")
     */
    private $id;

    /**
     * @psalm-var Collection<int, DDC3785Attribute>
     * @ManyToMany(targetEntity="DDC3785Attribute", cascade={"persist"}, orphanRemoval=true)
     * @JoinTable(name="asset_attributes",
     *      joinColumns={@JoinColumn(name="asset_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="attribute_id", referencedColumnName="id")}
     *      )
     */
    private $attributes;

    /**
     * @psalm-param list<DDC3785Attribute> $attributes
     */
    public function __construct(DDC3785AssetId $id, $attributes = [])
    {
        $this->id         = $id;
        $this->attributes = new ArrayCollection();

        foreach ($attributes as $attribute) {
            $this->attributes->add($attribute);
        }
    }

    public function getId(): DDC3785AssetId
    {
        return $this->id;
    }

    /**
     * @psalm-return Collection<int, DDC3785Attribute>
     */
    public function getAttributes()
    {
        return $this->attributes;
    }
}

/**
 * @Entity
 * @Table(name="attribute")
 */
class DDC3785Attribute
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    private $name;

    /**
     * @var string
     * @Column(type="string")
     */
    private $value;

    public function __construct(string $name, string $value)
    {
        $this->name  = $name;
        $this->value = $value;
    }
}

/** @Embeddable */
class DDC3785AssetId
{
    /**
     * @var string
     * @Column(type = "guid")
     */
    private $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}

class DDC3785AssetIdType extends Type
{
    /**
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getGuidTypeDeclarationSQL($fieldDeclaration);
    }

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        return (string) $value;
    }

    /**
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        return new DDC3785AssetId($value);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'ddc3785_asset_id';
    }
}
