<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Cache
 * @Entity
 * @Table("cache_attraction_info")
 * @InheritanceType("JOINED")
 * @DiscriminatorMap({
 *  1  = "AttractionContactInfo",
 *  2  = "AttractionLocationInfo",
 * })
 */
abstract class AttractionInfo
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    protected $id;

    /**
     * @var Attraction
     * @Cache
     * @ManyToOne(targetEntity="Attraction", inversedBy="infos")
     * @JoinColumn(name="attraction_id", referencedColumnName="id")
     */
    protected $attraction;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getAttraction(): Attraction
    {
        return $this->attraction;
    }

    public function setAttraction(Attraction $attraction): void
    {
        $this->attraction = $attraction;

        $attraction->addInfo($this);
    }
}
