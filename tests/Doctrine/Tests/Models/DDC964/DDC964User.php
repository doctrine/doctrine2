<?php

namespace Doctrine\Tests\Models\DDC964;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @MappedSuperclass
 */
class DDC964User
{

    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer", name="user_id", length=150)
     */
    protected $id;

    /**
     * @Column(name="user_name", nullable=true, unique=false, length=250)
     */
    protected $name;

    /**
     * @var ArrayCollection
     *
     * @ManyToMany(targetEntity="DDC964Group", inversedBy="users", cascade={"persist", "merge", "detach"})
     * @JoinTable(name="ddc964_users_groups",
     *  joinColumns={@JoinColumn(name="user_id", referencedColumnName="id")},
     *  inverseJoinColumns={@JoinColumn(name="group_id", referencedColumnName="id")}
     * )
     */
    protected $groups;

    /**
     * @var DDC964Address
     *
     * @ManyToOne(targetEntity="DDC964Address", cascade={"persist", "merge"})
     * @JoinColumn(name="address_id", referencedColumnName="id")
     */
    protected $address;

    /**
     * @param string $name
     */
    public function __construct($name = null)
    {
        $this->name     = $name;
        $this->groups   = new ArrayCollection;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @param DDC964Group $group
     */
    public function addGroup(DDC964Group $group)
    {
        $this->groups->add($group);
        $group->addUser($this);
    }

    /**
     * @return ArrayCollection
     */
    public function getGroups()
    {
        return $this->groups;
    }

    /**
     * @return DDC964Address
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * @param DDC964Address $address
     */
    public function setAddress(DDC964Address $address)
    {
        $this->address = $address;
    }

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->mapField(
            [
           'id'         => true,
           'fieldName'  => 'id',
           'type'       => 'integer',
           'columnName' => 'user_id',
           'length'     => 150,
            ]
        );
        $metadata->mapField(
            [
            'fieldName' => 'name',
            'type'      => 'string',
            'columnName'=> 'user_name',
            'nullable'  => true,
            'unique'    => false,
            'length'    => 250,
            ]
        );

        $metadata->mapManyToOne(
            [
           'fieldName'      => 'address',
           'targetEntity'   => 'DDC964Address',
           'cascade'        => ['persist','merge'],
           'joinColumn'     => ['name'=>'address_id', 'referencedColumnMame'=>'id'],
            ]
        );

        $metadata->mapManyToMany(
            [
           'fieldName'      => 'groups',
           'targetEntity'   => 'DDC964Group',
           'inversedBy'     => 'users',
           'cascade'        => ['persist','merge','detach'],
           'joinTable'      => [
                'name'          => 'ddc964_users_groups',
                'joinColumns'   => [
                    [
                    'name'=>'user_id',
                    'referencedColumnName'=>'id',
                    ]
                ],
                'inverseJoinColumns'=> [
                    [
                    'name'=>'group_id',
                    'referencedColumnName'=>'id',
                    ]
                ]
           ]
            ]
        );

        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);
    }
}
