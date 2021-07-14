<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\GeoNames;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="geonames_city")
 * @Cache
 */
class City
{
    /**
     * @var string
     * @Id
     * @Column(type="string", length=25)
     * @GeneratedValue(strategy="NONE")
     */
    public $id;

    /**
     * @var Country
     * @ManyToOne(targetEntity="Country")
     * @JoinColumn(name="country", referencedColumnName="id")
     * @Cache
     */
    public $country;

    /**
     * @var Admin1
     * @ManyToOne(targetEntity="Admin1")
     * @JoinColumns({
     *   @JoinColumn(name="admin1", referencedColumnName="id"),
     *   @JoinColumn(name="country", referencedColumnName="country")
     * })
     * @Cache
     */
    public $admin1;

    /**
     * @var string
     * @Column(type="string", length=255);
     */
    public $name;

    public function __construct(int $id, string $name)
    {
        $this->id   = $id;
        $this->name = $name;
    }
}
