<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Export;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\EntityListeners;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\PostPersist;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @HasLifecycleCallbacks
 * @EntityListeners({
 *     Doctrine\Tests\ORM\Tools\Export\UserListener::class,
 *     Doctrine\Tests\ORM\Tools\Export\GroupListener::class,
 *     Doctrine\Tests\ORM\Tools\Export\AddressListener::class
 * })
 * @Table(name="cms_users",options={"engine"="MyISAM","foo"={"bar"="baz"}})
 */
class User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer") @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(length=50, nullable=true, unique=true)
     */
    public $name;

    /**
     * @var string
     * @Column(name="user_email", columnDefinition="CHAR(32) NOT NULL")
     */
    public $email;

    /**
     * @var int
     * @Column(type="integer", options={"unsigned"=true})
     */
    public $age;

    /**
     * @var Address
     * @OneToOne(targetEntity="Doctrine\Tests\ORM\Tools\Export\Address", inversedBy="user", cascade={"persist"}, orphanRemoval=true, fetch="EAGER")
     * @JoinColumn(name="address_id", onDelete="CASCADE")
     */
    public $address;

    /**
     * @var Group
     * @ManyToOne(targetEntity="Doctrine\Tests\ORM\Tools\Export\Group")
     */
    public $mainGroup;

    /**
     * @psalm-var Collection<int, Phonenumber>
     * @OneToMany(targetEntity="Doctrine\Tests\ORM\Tools\Export\Phonenumber", mappedBy="user", cascade={"persist", "merge"}, orphanRemoval=true)
     * @OrderBy({"number"="ASC"})
     */
    public $phonenumbers;

    /**
     * @psalm-var Collection<int, Group>
     * @ManyToMany(targetEntity="Doctrine\Tests\ORM\Tools\Export\Group", cascade={"all"}, fetch="EXTRA_LAZY")
     * @JoinTable(name="cms_users_groups",
     *    joinColumns={@JoinColumn(name="user_id", referencedColumnName="id", nullable=false, unique=false)},
     *    inverseJoinColumns={@JoinColumn(name="group_id", referencedColumnName="id", columnDefinition="INT NULL")}
     * )
     */
    public $groups;

    /**
     * @PrePersist
     */
    public function doStuffOnPrePersist(): void
    {
    }

    /**
     * @PrePersist
     */
    public function doOtherStuffOnPrePersistToo(): void
    {
    }

    /**
     * @PostPersist
     */
    public function doStuffOnPostPersist(): void
    {
    }
}
