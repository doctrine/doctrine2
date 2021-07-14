<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function sprintf;

/**
 * @group DDC-2224
 */
class DDC2224Test extends OrmFunctionalTestCase
{
    public static function setUpBeforeClass(): void
    {
        Type::addType('DDC2224Type', DDC2224Type::class);
    }

    public function testIssue(): Query
    {
        $dql   = 'SELECT e FROM ' . __NAMESPACE__ . '\DDC2224Entity e WHERE e.field = :field';
        $query = $this->_em->createQuery($dql);
        $query->setQueryCacheDriver(DoctrineProvider::wrap(new ArrayAdapter()));

        $query->setParameter('field', 'test', 'DDC2224Type');
        $this->assertStringEndsWith('.field = FUNCTION(?)', $query->getSQL());

        return $query;
    }

    /**
     * @depends testIssue
     */
    public function testCacheMissWhenTypeChanges(Query $query): void
    {
        $query->setParameter('field', 'test', 'string');
        $this->assertStringEndsWith('.field = ?', $query->getSQL());
    }
}

class DDC2224Type extends Type
{
    /**
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getVarcharTypeDeclarationSQL($fieldDeclaration);
    }

    public function getName(): string
    {
        return 'DDC2224Type';
    }

    /**
     * {@inheritdoc}
     */
    public function canRequireSQLConversion()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValueSQL($sqlExpr, AbstractPlatform $platform)
    {
        return sprintf('FUNCTION(%s)', $sqlExpr);
    }
}

/**
 * @Entity
 */
class DDC2224Entity
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var mixed
     * @Column(type="DDC2224Type")
     */
    public $field;
}
