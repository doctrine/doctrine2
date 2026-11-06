<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InverseJoinColumn;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use Stringable;

class DDC3785Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType('ddc3785_asset_id', DDC3785AssetIdType::class);

        $this->createSchemaForModels(
            DDC3785Asset::class,
            DDC3785Attribute::class,
        );
    }

    #[Group('DDC-3785')]
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

#[Table(name: 'asset')]
#[Entity]
class DDC3785Asset
{
    /** @phpstan-var Collection<int, DDC3785Attribute> */
    #[JoinTable(name: 'asset_attributes')]
    #[JoinColumn(name: 'asset_id', referencedColumnName: 'id')]
    #[InverseJoinColumn(name: 'attribute_id', referencedColumnName: 'id')]
    #[ManyToMany(targetEntity: 'DDC3785Attribute', cascade: ['persist'], orphanRemoval: true)]
    private $attributes;

    /** @phpstan-param list<DDC3785Attribute> $attributes */
    public function __construct(
        #[Id]
        #[GeneratedValue(strategy: 'NONE')]
        #[Column(type: 'ddc3785_asset_id')]
        private DDC3785AssetId $id,
        $attributes = [],
    ) {
        $this->attributes = new ArrayCollection();

        foreach ($attributes as $attribute) {
            $this->attributes->add($attribute);
        }
    }

    public function getId(): DDC3785AssetId
    {
        return $this->id;
    }

    /** @phpstan-return Collection<int, DDC3785Attribute> */
    public function getAttributes()
    {
        return $this->attributes;
    }
}

#[Table(name: 'attribute')]
#[Entity]
class DDC3785Attribute
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    public function __construct(
        #[Column(type: 'string', length: 255)]
        private string $name,
        #[Column(type: 'string', length: 255)]
        private string $value,
    ) {
    }
}

class DDC3785AssetId implements Stringable
{
    public function __construct(
        private string $id,
    ) {
    }

    public function __toString(): string
    {
        return $this->id;
    }
}

class DDC3785AssetIdType extends Type
{
    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): string
    {
        return (string) $value;
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): DDC3785AssetId
    {
        return new DDC3785AssetId($value);
    }

    public function getName(): string
    {
        return 'ddc3785_asset_id';
    }
}
