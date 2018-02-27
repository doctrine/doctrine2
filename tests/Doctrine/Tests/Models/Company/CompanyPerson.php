<?php

namespace Doctrine\Tests\Models\Company;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Description of CompanyPerson
 *
 * @author robo
 * @Entity
 * @Table(name="company_persons")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({
 *      "person"    = "CompanyPerson",
 *      "manager"   = "CompanyManager",
 *      "employee"  = "CompanyEmployee"
 * })
 *
 * @NamedNativeQueries({
 *      @NamedNativeQuery(
 *          name           = "fetchAllWithResultClass",
 *          resultClass    = "__CLASS__",
 *          query          = "SELECT id, name, discr FROM company_persons ORDER BY name"
 *      ),
 *      @NamedNativeQuery(
 *          name            = "fetchAllWithSqlResultSetMapping",
 *          resultSetMapping= "mappingFetchAll",
 *          query           = "SELECT id, name, discr AS discriminator FROM company_persons ORDER BY name"
 *      )
 * })
 *
 * @SqlResultSetMappings({
 *      @SqlResultSetMapping(
 *          name    = "mappingFetchAll",
 *          entities= {
 *              @EntityResult(
 *                  entityClass         = "__CLASS__",
 *                  discriminatorColumn = "discriminator",
 *                  fields              = {
 *                      @FieldResult("id"),
 *                      @FieldResult("name"),
 *                  }
 *              )
 *          }
 *      )
 * })
 */
class CompanyPerson
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @Column
     */
    private $name;

    /**
     * @OneToOne(targetEntity="CompanyPerson")
     * @JoinColumn(name="spouse_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private $spouse;

    /**
     * @ManyToMany(targetEntity="CompanyPerson")
     * @JoinTable(
     *     name="company_persons_friends",
     *     joinColumns={
     *         @JoinColumn(name="person_id", referencedColumnName="id", onDelete="CASCADE")
     *     },
     *     inverseJoinColumns={
     *         @JoinColumn(name="friend_id", referencedColumnName="id", onDelete="CASCADE")
     *     }
     * )
     */
    private $friends;

    public function __construct() {
        $this->friends = new \Doctrine\Common\Collections\ArrayCollection;
    }

    public function getId() {
        return  $this->id;
    }

    public function getName() {
        return $this->name;
    }

    public function setName($name) {
        $this->name = $name;
    }

    public function getSpouse() {
        return $this->spouse;
    }

    public function getFriends() {
        return $this->friends;
    }

    public function addFriend(CompanyPerson $friend) {
        if ( ! $this->friends->contains($friend)) {
            $this->friends->add($friend);
            $friend->addFriend($this);
        }
    }

    public function setSpouse(CompanyPerson $spouse) {
        if ($spouse !== $this->spouse) {
            $this->spouse = $spouse;
            $this->spouse->setSpouse($this);
        }
    }

    public static function loadMetadata(ClassMetadata $metadata)
    {

        $metadata->setPrimaryTable(
            [
           'name' => 'company_person',
            ]
        );

        $metadata->addNamedNativeQuery(
            [
            'name'              => 'fetchAllWithResultClass',
            'query'             => 'SELECT id, name, discr FROM company_persons ORDER BY name',
            'resultClass'       => CompanyPerson::class,
            ]
        );

        $metadata->addNamedNativeQuery(
            [
            'name'              => 'fetchAllWithSqlResultSetMapping',
            'query'             => 'SELECT id, name, discr AS discriminator FROM company_persons ORDER BY name',
            'resultSetMapping'  => 'mappingFetchAll',
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
            'name'      => 'mappingFetchAll',
            'columns'   => [],
            'entities'  => [
                [
                'fields' => [
                  [
                    'name'      => 'id',
                    'column'    => 'id',
                  ],
                  [
                    'name'      => 'name',
                    'column'    => 'name',
                  ],
                ],
                'entityClass' => CompanyPerson::class,
                'discriminatorColumn' => 'discriminator',
                ],
            ],
            ]
        );
    }
}

