<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;
use function reset;

class DDC2138Test extends OrmFunctionalTestCase
{
    /**
     * @group DDC-2138
     */
    public function testForeignKeyOnSTIWithMultipleMapping(): void
    {
        $em         = $this->_em;
        $schemaTool = new SchemaTool($em);

        $classes = [
            $em->getClassMetadata(DDC2138User::class),
            $em->getClassMetadata(DDC2138Structure::class),
            $em->getClassMetadata(DDC2138UserFollowedObject::class),
            $em->getClassMetadata(DDC2138UserFollowedStructure::class),
            $em->getClassMetadata(DDC2138UserFollowedUser::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);
        $this->assertTrue($schema->hasTable('users_followed_objects'), 'Table users_followed_objects should exist.');

        $table = $schema->getTable('users_followed_objects');
        assert($table instanceof DbalTable);
        $this->assertTrue($table->columnsAreIndexed(['object_id']));
        $this->assertTrue($table->columnsAreIndexed(['user_id']));
        $foreignKeys = $table->getForeignKeys();
        $this->assertCount(1, $foreignKeys, 'user_id column has to have FK, but not object_id');

        $fk = reset($foreignKeys);
        assert($fk instanceof ForeignKeyConstraint);
        $this->assertEquals('users', $fk->getForeignTableName());

        $localColumns = $fk->getLocalColumns();
        $this->assertContains('user_id', $localColumns);
        $this->assertCount(1, $localColumns);
    }
}



/**
 * @Table(name="structures")
 * @Entity
 */
class DDC2138Structure
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string
     * @Column(type="string", length=32, nullable=true)
     */
    protected $name;
}

/**
 * @Entity
 * @Table(name="users_followed_objects")
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="object_type", type="smallint")
 * @DiscriminatorMap({4 = "DDC2138UserFollowedUser", 3 = "DDC2138UserFollowedStructure"})
 */
abstract class DDC2138UserFollowedObject
{
    /**
     * @var int $id
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * Get id
     */
    public function getId(): int
    {
        return $this->id;
    }
}

/**
 * @Entity
 */
class DDC2138UserFollowedStructure extends DDC2138UserFollowedObject
{
    /**
     * @ManyToOne(targetEntity="DDC2138User", inversedBy="followedStructures")
     * @JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
     * @var User $user
     */
    protected $user;

    /**
     * @ManyToOne(targetEntity="DDC2138Structure")
     * @JoinColumn(name="object_id", referencedColumnName="id", nullable=false)
     * @var Structure $followedStructure
     */
    private $followedStructure;

    /**
     * Construct a UserFollowedStructure entity
     */
    public function __construct(User $user, Structure $followedStructure)
    {
        $this->user              = $user;
        $this->followedStructure = $followedStructure;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Gets followed structure
     */
    public function getFollowedStructure(): Structure
    {
        return $this->followedStructure;
    }
}

/**
 * @Entity
 */
class DDC2138UserFollowedUser extends DDC2138UserFollowedObject
{
    /**
     * @ManyToOne(targetEntity="DDC2138User", inversedBy="followedUsers")
     * @JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
     * @var User $user
     */
    protected $user;

    /**
     * @ManyToOne(targetEntity="DDC2138User")
     * @JoinColumn(name="object_id", referencedColumnName="id", nullable=false)
     * @var User $user
     */
    private $followedUser;

    /**
     * Construct a UserFollowedUser entity
     */
    public function __construct(User $user, User $followedUser)
    {
        $this->user         = $user;
        $this->followedUser = $followedUser;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Gets followed user
     */
    public function getFollowedUser(): User
    {
        return $this->followedUser;
    }
}

/**
 * @Table(name="users")
 * @Entity
 */
class DDC2138User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string
     * @Column(type="string", length=32, nullable=true)
     */
    protected $name;

    /**
     * @var ArrayCollection $followedUsers
     * @OneToMany(targetEntity="DDC2138UserFollowedUser", mappedBy="user", cascade={"persist"}, orphanRemoval=true)
     */
    protected $followedUsers;

    /**
     * @var ArrayCollection $followedStructures
     * @OneToMany(targetEntity="DDC2138UserFollowedStructure", mappedBy="user", cascade={"persist"}, orphanRemoval=true)
     */
    protected $followedStructures;

    public function __construct()
    {
        $this->followedUsers      = new ArrayCollection();
        $this->followedStructures = new ArrayCollection();
    }

    public function addFollowedUser(UserFollowedUser $followedUsers): User
    {
        $this->followedUsers[] = $followedUsers;

        return $this;
    }

    public function removeFollowedUser(UserFollowedUser $followedUsers): User
    {
        $this->followedUsers->removeElement($followedUsers);

        return $this;
    }

    public function getFollowedUsers(): Doctrine\Common\Collections\Collection
    {
        return $this->followedUsers;
    }

    public function addFollowedStructure(UserFollowedStructure $followedStructures): User
    {
        $this->followedStructures[] = $followedStructures;

        return $this;
    }

    public function removeFollowedStructure(UserFollowedStructure $followedStructures): User
    {
        $this->followedStructures->removeElement($followedStructures);

        return $this;
    }

    public function getFollowedStructures(): Doctrine\Common\Collections\Collection
    {
        return $this->followedStructures;
    }
}
