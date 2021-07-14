<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC211Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC211User::class),
                $this->_em->getClassMetadata(DDC211Group::class),
            ]
        );
    }

    public function testIssue(): void
    {
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);

        $user = new DDC211User();
        $user->setName('John Doe');

        $this->_em->persist($user);
        $this->_em->flush();

        $groupNames = ['group 1', 'group 2', 'group 3', 'group 4'];
        foreach ($groupNames as $name) {
            $group = new DDC211Group();
            $group->setName($name);
            $this->_em->persist($group);
            $this->_em->flush();

            if (! $user->getGroups()->contains($group)) {
                $user->getGroups()->add($group);
                $group->getUsers()->add($user);
                $this->_em->flush();
            }
        }

        $this->assertEquals(4, $user->getGroups()->count());
    }
}


/**
 * @Entity
 * @Table(name="ddc211_users")
 */
class DDC211User
{
    /**
     * @var int
     * @Id
     * @Column(name="id", type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string
     * @Column(name="name", type="string")
     */
    protected $name;

    /**
     * @psalm-var Collection<int, DDC211Group>
     * @ManyToMany(targetEntity="DDC211Group", inversedBy="users")
     *   @JoinTable(name="user_groups",
     *       joinColumns={@JoinColumn(name="user_id", referencedColumnName="id")},
     *       inverseJoinColumns={@JoinColumn(name="group_id", referencedColumnName="id")}
     *   )
     */
    protected $groups;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @psalm-return Collection<int, DDC211Group>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }
}

/**
 * @Entity
 * @Table(name="ddc211_groups")
 */
class DDC211Group
{
    /**
     * @var int
     * @Id
     * @Column(name="id", type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string
     * @Column(name="name", type="string")
     */
    protected $name;

    /**
     * @psalm-var Collection<int, DDC211User>
     * @ManyToMany(targetEntity="DDC211User", mappedBy="groups")
     */
    protected $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @psalm-return Collection<int, DDC211User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }
}
