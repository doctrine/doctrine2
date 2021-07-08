<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CMS;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * @Entity
 * @Table(name="cms_users")
 * @NamedQueries({
 *     @NamedQuery(name="all", query="SELECT u FROM __CLASS__ u")
 * })
 * @NamedNativeQueries({
 *      @NamedNativeQuery(
 *          name           = "fetchIdAndUsernameWithResultClass",
 *          resultClass    = "CmsUser",
 *          query          = "SELECT id, username FROM cms_users WHERE username = ?"
 *      ),
 *      @NamedNativeQuery(
 *          name           = "fetchAllColumns",
 *          resultClass    = "CmsUser",
 *          query          = "SELECT * FROM cms_users WHERE username = ?"
 *      ),
 *      @NamedNativeQuery(
 *          name            = "fetchJoinedAddress",
 *          resultSetMapping= "mappingJoinedAddress",
 *          query           = "SELECT u.id, u.name, u.status, a.id AS a_id, a.country, a.zip, a.city FROM cms_users u INNER JOIN cms_addresses a ON u.id = a.user_id WHERE u.username = ?"
 *      ),
 *      @NamedNativeQuery(
 *          name            = "fetchJoinedPhonenumber",
 *          resultSetMapping= "mappingJoinedPhonenumber",
 *          query           = "SELECT id, name, status, phonenumber AS number FROM cms_users INNER JOIN cms_phonenumbers ON id = user_id WHERE username = ?"
 *      ),
 *      @NamedNativeQuery(
 *          name            = "fetchUserPhonenumberCount",
 *          resultSetMapping= "mappingUserPhonenumberCount",
 *          query           = "SELECT id, name, status, COUNT(phonenumber) AS numphones FROM cms_users INNER JOIN cms_phonenumbers ON id = user_id WHERE username IN (?) GROUP BY id, name, status, username ORDER BY username"
 *      ),
 *      @NamedNativeQuery(
 *          name            = "fetchMultipleJoinsEntityResults",
 *          resultSetMapping= "mappingMultipleJoinsEntityResults",
 *          query           = "SELECT u.id AS u_id, u.name AS u_name, u.status AS u_status, a.id AS a_id, a.zip AS a_zip, a.country AS a_country, COUNT(p.phonenumber) AS numphones FROM cms_users u INNER JOIN cms_addresses a ON u.id = a.user_id INNER JOIN cms_phonenumbers p ON u.id = p.user_id GROUP BY u.id, u.name, u.status, u.username, a.id, a.zip, a.country ORDER BY u.username"
 *      ),
 * })
 * @SqlResultSetMappings({
 *      @SqlResultSetMapping(
 *          name    = "mappingJoinedAddress",
 *          entities= {
 *              @EntityResult(
 *                  entityClass = "__CLASS__",
 *                  fields      = {
 *                      @FieldResult(name = "id"),
 *                      @FieldResult(name = "name"),
 *                      @FieldResult(name = "status"),
 *                      @FieldResult(name = "address.zip"),
 *                      @FieldResult(name = "address.city"),
 *                      @FieldResult(name = "address.country"),
 *                      @FieldResult(name = "address.id", column = "a_id"),
 *                  }
 *              )
 *          }
 *      ),
 *      @SqlResultSetMapping(
 *          name    = "mappingJoinedPhonenumber",
 *          entities= {
 *              @EntityResult(
 *                  entityClass = "CmsUser",
 *                  fields      = {
 *                      @FieldResult("id"),
 *                      @FieldResult("name"),
 *                      @FieldResult("status"),
 *                      @FieldResult("phonenumbers.phonenumber" , column = "number"),
 *                  }
 *              )
 *          }
 *      ),
 *      @SqlResultSetMapping(
 *          name    = "mappingUserPhonenumberCount",
 *          entities= {
 *              @EntityResult(
 *                  entityClass = "CmsUser",
 *                  fields      = {
 *                      @FieldResult(name = "id"),
 *                      @FieldResult(name = "name"),
 *                      @FieldResult(name = "status"),
 *                  }
 *              )
 *          },
 *          columns = {
 *              @ColumnResult("numphones")
 *          }
 *      ),
 *      @SqlResultSetMapping(
 *          name    = "mappingMultipleJoinsEntityResults",
 *          entities= {
 *              @EntityResult(
 *                  entityClass = "__CLASS__",
 *                  fields      = {
 *                      @FieldResult(name = "id",       column="u_id"),
 *                      @FieldResult(name = "name",     column="u_name"),
 *                      @FieldResult(name = "status",   column="u_status"),
 *                  }
 *              ),
 *              @EntityResult(
 *                  entityClass = "CmsAddress",
 *                  fields      = {
 *                      @FieldResult(name = "id",       column="a_id"),
 *                      @FieldResult(name = "zip",      column="a_zip"),
 *                      @FieldResult(name = "country",  column="a_country"),
 *                  }
 *              )
 *          },
 *          columns = {
 *              @ColumnResult("numphones")
 *          }
 *      )
 * })
 */
class CmsUser
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string", length=50, nullable=true)
     */
    public $status;

    /**
     * @var string
     * @Column(type="string", length=255, unique=true)
     */
    public $username;

    /**
     * @psalm-var string|null
     * @Column(type="string", length=255)
     */
    public $name;

    /**
     * @psalm-var Collection<int, CmsPhonenumber>
     * @OneToMany(targetEntity="CmsPhonenumber", mappedBy="user", cascade={"persist", "merge"}, orphanRemoval=true)
     */
    public $phonenumbers;

    /**
     * @psalm-var Collection<int, CmsArticle>
     * @OneToMany(targetEntity="CmsArticle", mappedBy="user", cascade={"detach"})
     */
    public $articles;

    /**
     * @var CmsAddress
     * @OneToOne(targetEntity="CmsAddress", mappedBy="user", cascade={"persist"}, orphanRemoval=true)
     */
    public $address;

    /**
     * @var CmsEmail
     * @OneToOne(targetEntity="CmsEmail", inversedBy="user", cascade={"persist"}, orphanRemoval=true)
     * @JoinColumn(referencedColumnName="id", nullable=true)
     */
    public $email;

    /**
     * @psalm-var Collection<int, CmsGroup>
     * @ManyToMany(targetEntity="CmsGroup", inversedBy="users", cascade={"persist", "merge", "detach"})
     * @JoinTable(name="cms_users_groups",
     *      joinColumns={@JoinColumn(name="user_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="group_id", referencedColumnName="id")}
     *      )
     */
    public $groups;

    /**
     * @var Collection<int, CmsTag>
     * @ManyToMany(targetEntity="CmsTag", inversedBy="users", cascade={"all"}, ignoreDuplicates=true)
     * @JoinTable(name="cms_users_tags",
     *      joinColumns={@JoinColumn(name="user_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="tag_id", referencedColumnName="id")}
     *      )
     */
    public $tags;

    /** @var mixed */
    public $nonPersistedProperty;

    /** @var mixed */
    public $nonPersistedPropertyObject;

    public function __construct()
    {
        $this->phonenumbers = new ArrayCollection();
        $this->articles     = new ArrayCollection();
        $this->groups       = new ArrayCollection();
        $this->tags         = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Adds a phonenumber to the user.
     */
    public function addPhonenumber(CmsPhonenumber $phone): void
    {
        $this->phonenumbers[] = $phone;
        $phone->setUser($this);
    }

    /**
     * @psalm-return Collection<int, CmsPhonenumber>
     */
    public function getPhonenumbers(): Collection
    {
        return $this->phonenumbers;
    }

    public function addArticle(CmsArticle $article): void
    {
        $this->articles[] = $article;
        $article->setAuthor($this);
    }

    public function addGroup(CmsGroup $group): void
    {
        $this->groups[] = $group;
        $group->addUser($this);
    }

    /**
     * @psalm-return Collection<int, CmsGroup>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addTag(CmsTag $tag): void
    {
        $this->tags[] = $tag;
        $tag->addUser($this);
    }

    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function removePhonenumber($index): bool
    {
        if (isset($this->phonenumbers[$index])) {
            $ph = $this->phonenumbers[$index];
            unset($this->phonenumbers[$index]);
            $ph->user = null;

            return true;
        }

        return false;
    }

    public function getAddress(): CmsAddress
    {
        return $this->address;
    }

    public function setAddress(CmsAddress $address): void
    {
        if ($this->address !== $address) {
            $this->address = $address;
            $address->setUser($this);
        }
    }

    public function getEmail(): ?CmsEmail
    {
        return $this->email;
    }

    public function setEmail(?CmsEmail $email = null): void
    {
        if ($this->email !== $email) {
            $this->email = $email;

            if ($email) {
                $email->setUser($this);
            }
        }
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setPrimaryTable(
            ['name' => 'cms_users']
        );

        $metadata->addNamedNativeQuery(
            [
                'name'              => 'fetchIdAndUsernameWithResultClass',
                'query'             => 'SELECT id, username FROM cms_users WHERE username = ?',
                'resultClass'       => self::class,
            ]
        );

        $metadata->addNamedNativeQuery(
            [
                'name'              => 'fetchAllColumns',
                'query'             => 'SELECT * FROM cms_users WHERE username = ?',
                'resultClass'       => self::class,
            ]
        );

        $metadata->addNamedNativeQuery(
            [
                'name'              => 'fetchJoinedAddress',
                'query'             => 'SELECT u.id, u.name, u.status, a.id AS a_id, a.country, a.zip, a.city FROM cms_users u INNER JOIN cms_addresses a ON u.id = a.user_id WHERE u.username = ?',
                'resultSetMapping'  => 'mappingJoinedAddress',
            ]
        );

        $metadata->addNamedNativeQuery(
            [
                'name'              => 'fetchJoinedPhonenumber',
                'query'             => 'SELECT id, name, status, phonenumber AS number FROM cms_users INNER JOIN cms_phonenumbers ON id = user_id WHERE username = ?',
                'resultSetMapping'  => 'mappingJoinedPhonenumber',
            ]
        );

        $metadata->addNamedNativeQuery(
            [
                'name'              => 'fetchUserPhonenumberCount',
                'query'             => 'SELECT id, name, status, COUNT(phonenumber) AS numphones FROM cms_users INNER JOIN cms_phonenumbers ON id = user_id WHERE username IN (?) GROUP BY id, name, status, username ORDER BY username',
                'resultSetMapping'  => 'mappingUserPhonenumberCount',
            ]
        );

        $metadata->addNamedNativeQuery(
            [
                'name'              => 'fetchMultipleJoinsEntityResults',
                'resultSetMapping'  => 'mappingMultipleJoinsEntityResults',
                'query'             => 'SELECT u.id AS u_id, u.name AS u_name, u.status AS u_status, a.id AS a_id, a.zip AS a_zip, a.country AS a_country, COUNT(p.phonenumber) AS numphones FROM cms_users u INNER JOIN cms_addresses a ON u.id = a.user_id INNER JOIN cms_phonenumbers p ON u.id = p.user_id GROUP BY u.id, u.name, u.status, u.username, a.id, a.zip, a.country ORDER BY u.username',
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
                'name'      => 'mappingJoinedAddress',
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
                            [
                                'name'      => 'status',
                                'column'    => 'status',
                            ],
                            [
                                'name'      => 'address.zip',
                                'column'    => 'zip',
                            ],
                            [
                                'name'      => 'address.city',
                                'column'    => 'city',
                            ],
                            [
                                'name'      => 'address.country',
                                'column'    => 'country',
                            ],
                            [
                                'name'      => 'address.id',
                                'column'    => 'a_id',
                            ],
                        ],
                        'entityClass'           => self::class,
                        'discriminatorColumn'   => null,
                    ],
                ],
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
                'name'      => 'mappingJoinedPhonenumber',
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
                            [
                                'name'      => 'status',
                                'column'    => 'status',
                            ],
                            [
                                'name'      => 'phonenumbers.phonenumber',
                                'column'    => 'number',
                            ],
                        ],
                        'entityClass'   => self::class,
                        'discriminatorColumn'   => null,
                    ],
                ],
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
                'name'      => 'mappingUserPhonenumberCount',
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
                            [
                                'name'      => 'status',
                                'column'    => 'status',
                            ],
                        ],
                        'entityClass'   => self::class,
                        'discriminatorColumn'   => null,
                    ],
                ],
                'columns' => [
                    ['name' => 'numphones'],
                ],
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
                'name'      => 'mappingMultipleJoinsEntityResults',
                'entities'  => [
                    [
                        'fields' => [
                            [
                                'name'      => 'id',
                                'column'    => 'u_id',
                            ],
                            [
                                'name'      => 'name',
                                'column'    => 'u_name',
                            ],
                            [
                                'name'      => 'status',
                                'column'    => 'u_status',
                            ],
                        ],
                        'entityClass'           => self::class,
                        'discriminatorColumn'   => null,
                    ],
                    [
                        'fields' => [
                            [
                                'name'      => 'id',
                                'column'    => 'a_id',
                            ],
                            [
                                'name'      => 'zip',
                                'column'    => 'a_zip',
                            ],
                            [
                                'name'      => 'country',
                                'column'    => 'a_country',
                            ],
                        ],
                        'entityClass'           => CmsAddress::class,
                        'discriminatorColumn'   => null,
                    ],
                ],
                'columns' => [
                    ['name' => 'numphones'],
                ],
            ]
        );
    }
}
