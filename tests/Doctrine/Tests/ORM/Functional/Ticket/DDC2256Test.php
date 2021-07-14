<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-2256
 */
class DDC2256Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC2256User::class),
                $this->_em->getClassMetadata(DDC2256Group::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $config = $this->_em->getConfiguration();
        $config->addEntityNamespace('MyNamespace', __NAMESPACE__);

        $user        = new DDC2256User();
        $user->name  = 'user';
        $group       = new DDC2256Group();
        $group->name = 'group';
        $user->group = $group;

        $this->_em->persist($user);
        $this->_em->persist($group);
        $this->_em->flush();
        $this->_em->clear();

        $sql = 'SELECT u.id, u.name, g.id as group_id, g.name as group_name FROM ddc2256_users u LEFT JOIN ddc2256_groups g ON u.group_id = g.id';

        // Test ResultSetMapping.
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult('MyNamespace:DDC2256User', 'u');
        $rsm->addFieldResult('u', 'id', 'id');
        $rsm->addFieldResult('u', 'name', 'name');

        $rsm->addJoinedEntityResult('MyNamespace:DDC2256Group', 'g', 'u', 'group');
        $rsm->addFieldResult('g', 'group_id', 'id');
        $rsm->addFieldResult('g', 'group_name', 'name');

        self::assertCount(1, $this->_em->createNativeQuery($sql, $rsm)->getResult());

        // Test ResultSetMappingBuilder.
        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata('MyNamespace:DDC2256User', 'u');
        $rsm->addJoinedEntityFromClassMetadata('MyNamespace:DDC2256Group', 'g', 'u', 'group', ['id' => 'group_id', 'name' => 'group_name']);

        self::assertCount(1, $this->_em->createNativeQuery($sql, $rsm)->getResult());
    }
}

/**
 * @Entity
 * @Table(name="ddc2256_users")
 */
class DDC2256User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @var DDC2256Group
     * @ManyToOne(targetEntity="DDC2256Group", inversedBy="users")A
     * @JoinColumn(name="group_id")
     */
    public $group;
}

/**
 * @Entity
 * @Table(name="ddc2256_groups")
 */
class DDC2256Group
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @psalm-var Collection<int, DDC2256User>
     * @OneToMany(targetEntity="DDC2256User", mappedBy="group")
     */
    public $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
    }
}
