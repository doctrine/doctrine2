<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function sprintf;

#[Group('DDC-2224')]
class DDC2224Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType('DDC2224Type', DDC2224Type::class);
    }

    public function testIssue(): Query
    {
        $dql   = 'SELECT e FROM ' . __NAMESPACE__ . '\DDC2224Entity e WHERE e.field = :field';
        $query = $this->_em->createQuery($dql);
        $query->setQueryCache(new ArrayAdapter());

        $query->setParameter('field', 'test', 'DDC2224Type');
        self::assertStringEndsWith('.field = FUNCTION(?)', $query->getSQL());

        return $query;
    }

    #[Depends('testIssue')]
    public function testCacheMissWhenTypeChanges(Query $query): void
    {
        $query->setParameter('field', 'test', 'string');
        self::assertStringEndsWith('.field = ?', $query->getSQL());
    }
}

class DDC2224Type extends Type
{
    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function getName(): string
    {
        return 'DDC2224Type';
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValueSQL($sqlExpr, AbstractPlatform $platform): string
    {
        return sprintf('FUNCTION(%s)', $sqlExpr);
    }
}

#[Entity]
class DDC2224Entity
{
    /** @var int */
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public $id;

    /** @var mixed */
    #[Column(type: 'DDC2224Type', length: 255)]
    public $field;
}
