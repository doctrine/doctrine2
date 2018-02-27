<?php

namespace Doctrine\Tests\Models\CMS;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @Entity
 * @Table(name="cms_users")
 * @NamedQueries({
 *     @NamedQuery(name="all", query="SELECT u FROM __CLASS__ u")
 * })
 *
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
 *
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
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
    /**
     * @Column(type="string", length=50, nullable=true)
     */
    public $status;
    /**
     * @Column(type="string", length=255, unique=true)
     */
    public $username;
    /**
     * @Column(type="string", length=255)
     */
    public $name;
    /**
     * @OneToMany(targetEntity="CmsPhonenumber", mappedBy="user", cascade={"persist", "merge"}, orphanRemoval=true)
     */
    public $phonenumbers;
    /**
     * @OneToMany(targetEntity="CmsArticle", mappedBy="user", cascade={"detach"})
     */
    public $articles;
    /**
     * @OneToOne(targetEntity="CmsAddress", mappedBy="user", cascade={"persist"}, orphanRemoval=true)
     */
    public $address;
    /**
     * @OneToOne(targetEntity="CmsEmail", inversedBy="user", cascade={"persist"}, orphanRemoval=true)
     * @JoinColumn(referencedColumnName="id", nullable=true)
     */
    public $email;
    /**
     * @ManyToMany(targetEntity="CmsGroup", inversedBy="users", cascade={"persist", "merge", "detach"})
     * @JoinTable(name="cms_users_groups",
     *      joinColumns={@JoinColumn(name="user_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="group_id", referencedColumnName="id")}
     *      )
     */
    public $groups;
    /**
     * @ManyToMany(targetEntity="CmsTag", inversedBy="users", cascade={"all"})
     * @JoinTable(name="cms_users_tags",
     *      joinColumns={@JoinColumn(name="user_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="tag_id", referencedColumnName="id")}
     *      )
     */
    public $tags;

    public $nonPersistedProperty;

    public $nonPersistedPropertyObject;

    public function __construct() {
        $this->phonenumbers = new ArrayCollection;
        $this->articles = new ArrayCollection;
        $this->groups = new ArrayCollection;
        $this->tags = new ArrayCollection;
    }

    public function getId() {
        return $this->id;
    }

    public function getStatus() {
        return $this->status;
    }

    public function getUsername() {
        return $this->username;
    }

    public function getName() {
        return $this->name;
    }

    /**
     * Adds a phonenumber to the user.
     *
     * @param CmsPhonenumber $phone
     */
    public function addPhonenumber(CmsPhonenumber $phone) {
        $this->phonenumbers[] = $phone;
        $phone->setUser($this);
    }

    public function getPhonenumbers() {
        return $this->phonenumbers;
    }

    public function addArticle(CmsArticle $article) {
        $this->articles[] = $article;
        $article->setAuthor($this);
    }

    public function addGroup(CmsGroup $group) {
        $this->groups[] = $group;
        $group->addUser($this);
    }

    public function getGroups() {
        return $this->groups;
    }

    public function addTag(CmsTag $tag) {
        $this->tags[] = $tag;
        $tag->addUser($this);
    }

    public function getTags() {
        return $this->tags;
    }

    public function removePhonenumber($index) {
        if (isset($this->phonenumbers[$index])) {
            $ph = $this->phonenumbers[$index];
            unset($this->phonenumbers[$index]);
            $ph->user = null;
            return true;
        }
        return false;
    }

    public function getAddress() { return $this->address; }

    public function setAddress(CmsAddress $address) {
        if ($this->address !== $address) {
            $this->address = $address;
            $address->setUser($this);
        }
    }

    /**
     * @return CmsEmail
     */
    public function getEmail() { return $this->email; }

    public function setEmail(CmsEmail $email = null) {
        if ($this->email !== $email) {
            $this->email = $email;

            if ($email) {
                $email->setUser($this);
            }
        }
    }

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->setPrimaryTable(
            [
           'name' => 'cms_users',
            ]
        );

        $metadata->addNamedNativeQuery(
            [
            'name'              => 'fetchIdAndUsernameWithResultClass',
            'query'             => 'SELECT id, username FROM cms_users WHERE username = ?',
            'resultClass'       => CmsUser::class,
            ]
        );

        $metadata->addNamedNativeQuery(
            [
            'name'              => 'fetchAllColumns',
            'query'             => 'SELECT * FROM cms_users WHERE username = ?',
            'resultClass'       => CmsUser::class,
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
            "name"              => "fetchMultipleJoinsEntityResults",
            "resultSetMapping"  => "mappingMultipleJoinsEntityResults",
            "query"             => "SELECT u.id AS u_id, u.name AS u_name, u.status AS u_status, a.id AS a_id, a.zip AS a_zip, a.country AS a_country, COUNT(p.phonenumber) AS numphones FROM cms_users u INNER JOIN cms_addresses a ON u.id = a.user_id INNER JOIN cms_phonenumbers p ON u.id = p.user_id GROUP BY u.id, u.name, u.status, u.username, a.id, a.zip, a.country ORDER BY u.username"
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
            'name'      => 'mappingJoinedAddress',
            'columns'   => [],
            'entities'  => [
                [
                'fields'=> [
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
                'entityClass'           => CmsUser::class,
                'discriminatorColumn'   => null
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
                'fields'=> [
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
                'entityClass'   => CmsUser::class,
                'discriminatorColumn'   => null
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
                  ]
                ],
                'entityClass'   => CmsUser::class,
                'discriminatorColumn'   => null
              ]
            ],
            'columns' => [
                  [
                    'name' => 'numphones',
                  ]
            ]
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
                        ]
                    ],
                    'entityClass'           => CmsUser::class,
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
                [
                    'name' => 'numphones',
                ]
            ]
            ]
        );

    }
}
