<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC964;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

/**
 * @Entity
 */
class DDC964Address
{
    /**
     * @var int
     * @GeneratedValue
     * @Id @Column(type="integer")
     */
    private $id;

    /**
     * @var string|null
     * @Column
     */
    private $country;

    /**
     * @var string|null
     * @Column
     */
    private $zip;

    /**
     * @var string|null
     * @Column
     */
    private $city;

    /**
     * @var string|null
     * @Column
     */
    private $street;

    public function __construct(
        ?string $zip = null,
        ?string $country = null,
        ?string $city = null,
        ?string $street = null
    ) {
        $this->zip     = $zip;
        $this->country = $country;
        $this->city    = $city;
        $this->street  = $street;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(string $country): void
    {
        $this->country = $country;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function setZip(string $zip): void
    {
        $this->zip = $zip;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): void
    {
        $this->city = $city;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(string $street): void
    {
        $this->street = $street;
    }
}
