<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group GH7079
 */
final class GH7079Test extends OrmFunctionalTestCase
{
    /** @var DefaultQuoteStrategy */
    private $strategy;

    /** @var AbstractPlatform */
    private $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = $this->_em->getConnection()->getDatabasePlatform();
        $this->strategy = new DefaultQuoteStrategy();
    }

    public function testGetTableName(): void
    {
        $table = [
            'name'   => 'cms_user',
            'schema' => 'cms',
        ];

        $cm = $this->createClassMetadata(GH7079CmsUser::class);
        $cm->setPrimaryTable($table);

        self::assertEquals($this->getTableFullName($table), $this->strategy->getTableName($cm, $this->platform));
    }

    public function testJoinTableName(): void
    {
        $table = [
            'name'   => 'cmsaddress_cmsuser',
            'schema' => 'cms',
        ];

        $cm = $this->createClassMetadata(GH7079CmsAddress::class);
        $cm->mapManyToMany(
            [
                'fieldName'    => 'user',
                'targetEntity' => 'DDC7079CmsUser',
                'inversedBy'   => 'users',
                'joinTable'    => $table,
            ]
        );

        self::assertEquals(
            $this->getTableFullName($table),
            $this->strategy->getJoinTableName($cm->associationMappings['user'], $cm, $this->platform)
        );
    }

    private function getTableFullName(array $table): string
    {
        $join = '.';
        if (! $this->platform->supportsSchemas() && $this->platform->canEmulateSchemas()) {
            $join = '__';
        }

        return $table['schema'] . $join . $table['name'];
    }

    private function createClassMetadata(string $className): ClassMetadata
    {
        $cm = new ClassMetadata($className);
        $cm->initializeReflection(new RuntimeReflectionService());

        return $cm;
    }
}

/**
 * @Entity
 * @Table(name="cms_users", schema="cms")
 */
class GH7079CmsUser
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var GH7079CmsAddress
     * @OneToOne(targetEntity=GH7079CmsAddress::class, mappedBy="user", cascade={"persist"}, orphanRemoval=true)
     */
    public $address;
}

/**
 * @Entity
 * @Table(name="cms_addresses", schema="cms")
 */
class GH7079CmsAddress
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id @GeneratedValue
     */
    public $id;

    /**
     * @var GH7079CmsUser
     * @OneToOne(targetEntity=GH7079CmsUser::class, inversedBy="address")
     * @JoinColumn(referencedColumnName="id")
     */
    public $user;
}
