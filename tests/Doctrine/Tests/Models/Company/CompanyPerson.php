<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Company;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\EntityResult;
use Doctrine\ORM\Mapping\FieldResult;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\NamedNativeQueries;
use Doctrine\ORM\Mapping\NamedNativeQuery;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\SqlResultSetMapping;
use Doctrine\ORM\Mapping\SqlResultSetMappings;
use Doctrine\ORM\Mapping\Table;

/**
 * Description of CompanyPerson
 *
 * @Entity
 * @Table(name="company_persons")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({
 *      "person"    = "CompanyPerson",
 *      "manager"   = "CompanyManager",
 *      "employee"  = "CompanyEmployee"
 * })
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
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var string
     * @Column
     */
    private $name;

    /**
     * @var CompanyPerson|null
     * @OneToOne(targetEntity="CompanyPerson")
     * @JoinColumn(name="spouse_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private $spouse;

    /**
     * @psalm-var Collection<int, CompanyPerson>
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

    public function __construct()
    {
        $this->friends = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSpouse(): ?CompanyPerson
    {
        return $this->spouse;
    }

    /**
     * @psalm-return Collection<int, CompanyPerson>
     */
    public function getFriends(): Collection
    {
        return $this->friends;
    }

    public function addFriend(CompanyPerson $friend): void
    {
        if (! $this->friends->contains($friend)) {
            $this->friends->add($friend);
            $friend->addFriend($this);
        }
    }

    public function setSpouse(CompanyPerson $spouse): void
    {
        if ($spouse !== $this->spouse) {
            $this->spouse = $spouse;
            $this->spouse->setSpouse($this);
        }
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setPrimaryTable(
            ['name' => 'company_person']
        );

        $metadata->addNamedNativeQuery(
            [
                'name'              => 'fetchAllWithResultClass',
                'query'             => 'SELECT id, name, discr FROM company_persons ORDER BY name',
                'resultClass'       => self::class,
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
                        'entityClass' => self::class,
                        'discriminatorColumn' => 'discriminator',
                    ],
                ],
            ]
        );
    }
}
