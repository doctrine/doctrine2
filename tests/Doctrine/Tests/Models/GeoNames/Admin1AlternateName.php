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
 * @Table(name="geonames_admin1_alternate_name")
 * @Cache
 */
class Admin1AlternateName
{
    /**
     * @var int
     * @Id
     * @Column(type="string", length=25)
     * @GeneratedValue(strategy="NONE")
     */
    public $id;

    /**
     * @var Admin1
     * @ManyToOne(targetEntity="Admin1", inversedBy="names")
     * @JoinColumns({
     *    @JoinColumn(name="admin1", referencedColumnName="id"),
     *    @JoinColumn(name="country", referencedColumnName="country")
     * })
     * @Cache
     */
    public $admin1;

    /**
     * @var string
     * @Column(type="string", length=255);
     */
    public $name;

    public function __construct(int $id, string $name, Admin1 $admin1)
    {
        $this->id     = $id;
        $this->name   = $name;
        $this->admin1 = $admin1;
    }
}
