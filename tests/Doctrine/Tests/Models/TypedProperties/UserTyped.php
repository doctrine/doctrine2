<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\TypedProperties;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\Models\CMS\CmsEmail;

/**
 * @Entity
 * @Table(name="cms_users_typed")
 */
#[ORM\Entity, ORM\Table(name: 'cms_users_typed')]
class UserTyped
{
    /**
     * @Id @Column
     * @GeneratedValue
     */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public int $id;

    /** @Column(length=50) */
    #[ORM\Column(length: 50)]
    public ?string $status;

    /** @Column(length=255, unique=true) */
    #[ORM\Column(length: 255, unique: true)]
    public string $username;

    /** @Column */
    #[ORM\Column]
    public DateInterval $dateInterval;

    /** @Column */
    #[ORM\Column]
    public DateTime $dateTime;

    /** @Column */
    #[ORM\Column]
    public DateTimeImmutable $dateTimeImmutable;

    /** @Column */
    #[ORM\Column]
    public array $array;

    /** @Column */
    #[ORM\Column]
    public bool $boolean;

    /** @Column */
    #[ORM\Column]
    public float $float;

    /**
     * @OneToOne(cascade={"persist"}, orphanRemoval=true)
     * @JoinColumn
     */
    #[ORM\OneToOne(cascade: ['persist'], orphanRemoval: true), ORM\JoinColumn]
    public CmsEmail $email;

    /** @ManyToOne */
    #[ORM\ManyToOne]
    public ?CmsEmail $mainEmail;

    /** @Embedded */
    #[ORM\Embedded]
    public ?Contact $contact = null;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadataInfo::INHERITANCE_TYPE_NONE);
        $metadata->setPrimaryTable(
            ['name' => 'cms_users_typed']
        );

        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
            ]
        );
        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);

        $metadata->mapField(
            [
                'fieldName' => 'status',
                'length' => 50,
            ]
        );
        $metadata->mapField(
            [
                'fieldName' => 'username',
                'length' => 255,
                'unique' => true,
            ]
        );
        $metadata->mapField(
            ['fieldName' => 'dateInterval']
        );
        $metadata->mapField(
            ['fieldName' => 'dateTime']
        );
        $metadata->mapField(
            ['fieldName' => 'dateTimeImmutable']
        );
        $metadata->mapField(
            ['fieldName' => 'array']
        );
        $metadata->mapField(
            ['fieldName' => 'boolean']
        );
        $metadata->mapField(
            ['fieldName' => 'float']
        );

        $metadata->mapOneToOne(
            [
                'fieldName' => 'email',
                'cascade' =>
                    [0 => 'persist'],
                'joinColumns' =>
                    [
                        0 =>
                            [],
                    ],
                'orphanRemoval' => true,
            ]
        );

        $metadata->mapManyToOne(
            ['fieldName' => 'mainEmail']
        );

        $metadata->mapEmbedded(['fieldName' => 'contact']);
    }
}
