<?php

namespace Doctrine\Tests\Models\CMS;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * CmsAddress
 *
 * @author Roman S. Borschel
 * @Entity
 * @Table(name="cms_addresses")
 *
 * @NamedNativeQueries({
 *      @NamedNativeQuery(
 *          name                = "find-all",
 *          resultSetMapping    = "mapping-find-all",
 *          query               = "SELECT id, country, city FROM cms_addresses"
 *      ),
 *      @NamedNativeQuery(
 *          name           = "find-by-id",
 *          resultClass    = "CmsAddress",
 *          query          = "SELECT * FROM cms_addresses WHERE id = ?"
 *      ),
 *      @NamedNativeQuery(
 *          name            = "count",
 *          resultSetMapping= "mapping-count",
 *          query           = "SELECT COUNT(*) AS count FROM cms_addresses"
 *      )
 * })
 *
 * @SqlResultSetMappings({
 *      @SqlResultSetMapping(
 *          name    = "mapping-find-all",
 *          entities= {
 *              @EntityResult(
 *                  entityClass = "CmsAddress",
 *                  fields      = {
 *                      @FieldResult(name = "id",       column="id"),
 *                      @FieldResult(name = "city",     column="city"),
 *                      @FieldResult(name = "country",  column="country")
 *                  }
 *              )
 *          }
 *      ),
 *      @SqlResultSetMapping(
 *          name    = "mapping-without-fields",
 *          entities= {
 *              @EntityResult(
 *                  entityClass = "__CLASS__"
 *              )
 *          }
 *      ),
 *      @SqlResultSetMapping(
 *          name    = "mapping-count",
 *          columns = {
 *              @ColumnResult(
 *                  name = "count"
 *              )
 *          }
 *      )
 * })
 *
 * @EntityListeners({"CmsAddressListener"})
 */
class CmsAddress
{
    /**
     * @Column(type="integer")
     * @Id @GeneratedValue
     */
    public $id;

    /**
     * @Column(length=50)
     */
    public $country;

    /**
     * @Column(length=50)
     */
    public $zip;

    /**
     * @Column(length=50)
     */
    public $city;

    /**
     * Testfield for Schema Updating Tests.
     */
    public $street;

    /**
     * @OneToOne(targetEntity="CmsUser", inversedBy="address")
     * @JoinColumn(referencedColumnName="id")
     */
    public $user;

    public function getId() {
        return $this->id;
    }

    public function getUser() {
        return $this->user;
    }

    public function getCountry() {
        return $this->country;
    }

    public function getZipCode() {
        return $this->zip;
    }

    public function getCity() {
        return $this->city;
    }

    public function setUser(CmsUser $user) {
        if ($this->user !== $user) {
            $this->user = $user;
            $user->setAddress($this);
        }
    }

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->setPrimaryTable(
            [
           'name' => 'company_person',
            ]
        );

        $metadata->mapField(
            [
            'id'        => true,
            'fieldName' => 'id',
            'type'      => 'integer',
            ]
        );

        $metadata->mapField(
            [
            'fieldName' => 'zip',
            'length'    => 50,
            ]
        );

        $metadata->mapField(
            [
            'fieldName' => 'city',
            'length'    => 50,
            ]
        );

        $metadata->mapOneToOne(
            [
            'fieldName'     => 'user',
            'targetEntity'  => 'CmsUser',
            'joinColumns'   => [['referencedColumnName' => 'id']]
            ]
        );

        $metadata->addNamedNativeQuery(
            [
            'name'              => 'find-all',
            'query'             => 'SELECT id, country, city FROM cms_addresses',
            'resultSetMapping'  => 'mapping-find-all',
            ]
        );

        $metadata->addNamedNativeQuery(
            [
            'name'              => 'find-by-id',
            'query'             => 'SELECT * FROM cms_addresses WHERE id = ?',
            'resultClass'       => CmsAddress::class,
            ]
        );

        $metadata->addNamedNativeQuery(
            [
            'name'              => 'count',
            'query'             => 'SELECT COUNT(*) AS count FROM cms_addresses',
            'resultSetMapping'  => 'mapping-count',
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
            'name'      => 'mapping-find-all',
            'columns'   => [],
            'entities'  => [
                [
                'fields' => [
                  [
                    'name'      => 'id',
                    'column'    => 'id',
                  ],
                  [
                    'name'      => 'city',
                    'column'    => 'city',
                  ],
                  [
                    'name'      => 'country',
                    'column'    => 'country',
                  ],
                ],
                'entityClass' => CmsAddress::class,
                ],
            ],
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
            'name'      => 'mapping-without-fields',
            'columns'   => [],
            'entities'  => [
                [
                'entityClass' => CmsAddress::class,
                'fields' => []
                ]
            ]
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
            'name' => 'mapping-count',
            'columns' => [
                [
                    'name' => 'count',
                ],
            ]
            ]
        );

        $metadata->addEntityListener(\Doctrine\ORM\Events::postPersist, 'CmsAddressListener', 'postPersist');
        $metadata->addEntityListener(\Doctrine\ORM\Events::prePersist, 'CmsAddressListener', 'prePersist');

        $metadata->addEntityListener(\Doctrine\ORM\Events::postUpdate, 'CmsAddressListener', 'postUpdate');
        $metadata->addEntityListener(\Doctrine\ORM\Events::preUpdate, 'CmsAddressListener', 'preUpdate');

        $metadata->addEntityListener(\Doctrine\ORM\Events::postRemove, 'CmsAddressListener', 'postRemove');
        $metadata->addEntityListener(\Doctrine\ORM\Events::preRemove, 'CmsAddressListener', 'preRemove');

        $metadata->addEntityListener(\Doctrine\ORM\Events::preFlush, 'CmsAddressListener', 'preFlush');
        $metadata->addEntityListener(\Doctrine\ORM\Events::postLoad, 'CmsAddressListener', 'postLoad');
    }
}
