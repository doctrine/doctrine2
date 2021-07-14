<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\CustomIdGenerator;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group GH-5804
 */
final class GH5804Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Type::addType(GH5804Type::NAME, GH5804Type::class);

        $this->_schemaTool->createSchema(
            [$this->_em->getClassMetadata(GH5804Article::class)]
        );
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
    /**
     * {@inheritdoc}
     */
    public function generate(EntityManager $em, $entity)
    {
        return 'test5804';
    }
}

final class GH5804Type extends Type
{
    public const NAME = 'GH5804Type';

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getVarcharTypeDeclarationSQL($fieldDeclaration);
    }

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        if (empty($value)) {
            return null;
        }

        return 'testGh5804DbValue';
    }
}

/**
 * @Entity
 */
class GH5804Article
{
    /**
     * @var string
     * @Id
     * @Column(type="GH5804Type")
     * @GeneratedValue(strategy="CUSTOM")
     * @CustomIdGenerator(class=\Doctrine\Tests\ORM\Functional\Ticket\GH5804Generator::class)
     */
    public $id;

    /**
     * @var int
     * @Version
     * @Column(type="integer")
     */
    public $version;

    /**
     * @var string
     * @Column(type="text")
     */
    public $text;
}
