<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use Stringable;

#[Group('DDC-2579')]
class DDC2579Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(DDC2579Type::NAME, DDC2579Type::class);

        $this->createSchemaForModels(
            DDC2579Entity::class,
            DDC2579EntityAssoc::class,
            DDC2579AssocAssoc::class,
        );
    }

    public function testIssue(): void
    {
        $id         = new DDC2579Id('foo');
        $assoc      = new DDC2579AssocAssoc($id);
        $assocAssoc = new DDC2579EntityAssoc($assoc);
        $entity     = new DDC2579Entity($assocAssoc);
        $repository = $this->_em->getRepository(DDC2579Entity::class);

        $this->_em->persist($assoc);
        $this->_em->persist($assocAssoc);
        $this->_em->persist($entity);
        $this->_em->flush();

        $entity->value++;

        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        $id       = $entity->id;
        $value    = $entity->value;
        $criteria = ['assoc' => $assoc, 'id' => $id];
        $entity   = $repository->findOneBy($criteria);

        self::assertInstanceOf(DDC2579Entity::class, $entity);
        self::assertEquals($value, $entity->value);

        $this->_em->remove($entity);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($repository->findOneBy($criteria));
        self::assertCount(0, $repository->findAll());
    }
}

#[Entity]
class DDC2579Entity
{
    /** @var DDC2579Id */
    #[Id]
    #[Column(type: 'ddc2579', length: 255)]
    public $id;

    /** @var DDC2579EntityAssoc */
    #[Id]
    #[ManyToOne(targetEntity: 'DDC2579EntityAssoc')]
    #[JoinColumn(name: 'relation_id', referencedColumnName: 'association_id')]
    public $assoc;

    public function __construct(
        DDC2579EntityAssoc $assoc,
        #[Column(type: 'integer')]
        public int $value = 0,
    ) {
        $this->id    = $assoc->assocAssoc->associationId;
        $this->assoc = $assoc;
    }
}

#[Entity]
class DDC2579EntityAssoc
{
    public function __construct(
        /** @var DDC2579AssocAssoc */
        #[Id]
        #[ManyToOne(targetEntity: 'DDC2579AssocAssoc')]
        #[JoinColumn(name: 'association_id', referencedColumnName: 'associationId')]
        public $assocAssoc,
    ) {
    }
}

#[Entity]
class DDC2579AssocAssoc
{
    public function __construct(
        #[Id]
        #[Column(type: 'ddc2579', length: 255)]
        public DDC2579Id $associationId,
    ) {
    }
}


class DDC2579Type extends StringType
{
    final public const string NAME = 'ddc2579';

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
    public function convertToPHPValue($value, AbstractPlatform $platform): DDC2579Id
    {
        return new DDC2579Id($value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}

class DDC2579Id implements Stringable
{
    public function __construct(private string $val)
    {
    }

    public function __toString(): string
    {
        return $this->val;
    }
}
