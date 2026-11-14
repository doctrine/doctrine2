<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\CustomIdGenerator;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Version;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('GH-5804')]
final class GH5804Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(GH5804Type::NAME, GH5804Type::class);

        $this->createSchemaForModels(GH5804Article::class);
    }

    public function testTextColumnSaveAndRetrieve2(): void
    {
        $firstArticle       = new GH5804Article();
        $firstArticle->text = 'Max';
        $this->_em->persist($firstArticle);
        $this->_em->flush();

        self::assertSame(1, $firstArticle->version);

        $firstArticle->text = 'Moritz';
        $this->_em->persist($firstArticle);
        $this->_em->flush();

        self::assertSame(2, $firstArticle->version);
    }
}

final class GH5804Generator extends AbstractIdGenerator
{
    public function generateId(EntityManagerInterface $em, object|null $entity): string
    {
        return 'test5804';
    }
}

final class GH5804Type extends Type
{
    final public const string NAME = 'GH5804Type';

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): string|null
    {
        if (empty($value)) {
            return null;
        }

        return 'testGh5804DbValue';
    }
}

#[Entity]
class GH5804Article
{
    /** @var string */
    #[Id]
    #[Column(type: 'GH5804Type', length: 255)]
    #[GeneratedValue(strategy: 'CUSTOM')]
    #[CustomIdGenerator(class: GH5804Generator::class)]
    public $id;

    /** @var int */
    #[Version]
    #[Column(type: 'integer')]
    public $version;

    /** @var string */
    #[Column(type: 'text')]
    public $text;
}
