<?php

namespace Doctrine\Tests\Models\CMS;

use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\ColumnResult;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\EntityListeners;
use Doctrine\ORM\Mapping\EntityResult;
use Doctrine\ORM\Mapping\FieldResult;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\NamedNativeQueries;
use Doctrine\ORM\Mapping\NamedNativeQuery;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\SqlResultSetMapping;
use Doctrine\ORM\Mapping\SqlResultSetMappings;
use Doctrine\ORM\Mapping\Table;

/**
 * CmsAddress
 *
 * @Entity
 * @Table(name="cms_addresses")
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
 * @EntityListeners({"CmsAddressListener"})
 */
#[ORM\Entity]
#[ORM\Table(name: 'cms_addresses')]
#[ORM\EntityListeners(['CmsAddressListener'])]
class CmsAddress
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id @GeneratedValue
     */
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public $id;

    /**
     * @var string
     * @Column(length=50)
     */
    public $country;

    /**
     * @var string
     * @Column(length=50)
     */
    public $zip;

    /**
     * @var string
     * @Column(length=50)
     */
    public $city;

    /**
     * Testfield for Schema Updating Tests.
     *
     * @var string
     */
    public $street;

    /**
     * @var CmsUser
     * @OneToOne(targetEntity="CmsUser", inversedBy="address")
     * @JoinColumn(referencedColumnName="id")
     */
    public $user;

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): CmsUser
    {
        return $this->user;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getZipCode(): string
    {
        return $this->zip;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setUser(CmsUser $user): void
    {
        if ($this->user !== $user) {
            $this->user = $user;
            $user->setAddress($this);
        }
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setPrimaryTable(
            ['name' => 'company_person']
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
                'joinColumns'   => [['referencedColumnName' => 'id']],
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
                'resultClass'       => self::class,
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
                        'entityClass' => self::class,
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
                        'entityClass' => self::class,
                        'fields' => [],
                    ],
                ],
            ]
        );

        $metadata->addSqlResultSetMapping(
            [
                'name' => 'mapping-count',
                'columns' => [
                    ['name' => 'count'],
                ],
            ]
        );

        $metadata->addEntityListener(Events::postPersist, 'CmsAddressListener', 'postPersist');
        $metadata->addEntityListener(Events::prePersist, 'CmsAddressListener', 'prePersist');

        $metadata->addEntityListener(Events::postUpdate, 'CmsAddressListener', 'postUpdate');
        $metadata->addEntityListener(Events::preUpdate, 'CmsAddressListener', 'preUpdate');

        $metadata->addEntityListener(Events::postRemove, 'CmsAddressListener', 'postRemove');
        $metadata->addEntityListener(Events::preRemove, 'CmsAddressListener', 'preRemove');

        $metadata->addEntityListener(Events::preFlush, 'CmsAddressListener', 'preFlush');
        $metadata->addEntityListener(Events::postLoad, 'CmsAddressListener', 'postLoad');
    }
}
