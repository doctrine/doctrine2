<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Company;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="company_cars")
 */
class CompanyCar
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string|null
     * @Column(type="string", length=50)
     */
    private $brand;

    public function __construct(?string $brand = null)
    {
        $this->brand = $brand;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }
}
