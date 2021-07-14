<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC2372\Traits;

use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\Models\DDC2372\DDC2372Address;

trait DDC2372AddressAndAccessors
{
    /**
     * @var DDC2372Address
     * @OneToOne(targetEntity="Doctrine\Tests\Models\DDC2372\DDC2372Address", inversedBy="user")
     * @JoinColumn(name="address_id", referencedColumnName="id")
     */
    private $address;

    public function getAddress(): DDC2372Address
    {
        return $this->address;
    }

    public function setAddress(DDC2372Address $address): void
    {
        if ($this->address !== $address) {
            $this->address = $address;
            $address->setUser($this);
        }
    }
}
