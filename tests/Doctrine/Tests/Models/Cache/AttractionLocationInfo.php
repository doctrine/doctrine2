<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table("cache_attraction_location_info")
 */
class AttractionLocationInfo extends AttractionInfo
{
    /**
     * @var string
     * @Column(unique=true)
     */
    protected $address;

    public function __construct(string $address, Attraction $attraction)
    {
        $this->setAttraction($attraction);
        $this->setAddress($address);
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): void
    {
        $this->address = $address;
    }
}
