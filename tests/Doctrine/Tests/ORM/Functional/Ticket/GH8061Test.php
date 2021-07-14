<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmTestCase;

use function sprintf;

/**
 * @group GH8061
 */
final class GH8061Test extends OrmTestCase
{
    public static function setUpBeforeClass(): void
    {
        Type::addType('GH8061Type', GH8061Type::class);
    }

    public function testConvertToPHPValueSQLForNewObjectExpression(): void
    {
        $dql           = 'SELECT NEW ' . GH8061Class::class . '(e.field) FROM ' . GH8061Entity::class . ' e';
        $entityManager = $this->getTestEntityManager();
        $query         = $entityManager->createQuery($dql);

        self::assertMatchesRegularExpression('/SELECT DatabaseFunction\(\w+\.field\) AS /', $query->getSQL());
    }
}

/**
 * @Entity
 */
final class GH8061Entity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var mixed
     * @Column(type="GH8061Type")
     */
    public $field;
}

final class GH8061Type extends Type
{
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform): string
    {
        return $platform->getVarcharTypeDeclarationSQL($fieldDeclaration);
    }

    public function getName(): string
    {
        return 'GH8061';
    }

    public function canRequireSQLConversion(): bool
    {
        return true;
    }

    public function convertToPHPValueSQL($sqlExpr, $platform): string
    {
        return sprintf('DatabaseFunction(%s)', $sqlExpr);
    }
}

final class GH8061Class
{
    /** @var string */
    public $field;

    public function __construct(string $field)
    {
        $this->field = $field;
    }
}
