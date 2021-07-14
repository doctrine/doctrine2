<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Navigation;

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
 * @Table(name="navigation_photos")
 */
class NavPhotos
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var NavPointOfInterest
     * @ManyToOne(targetEntity="NavPointOfInterest")
     * @JoinColumns({
     *   @JoinColumn(name="poi_long", referencedColumnName="nav_long"),
     *   @JoinColumn(name="poi_lat", referencedColumnName="nav_lat")
     * })
     */
    private $poi;

    /**
     * @var string
     * @Column(type="string", name="file_name")
     */
    private $file;

    public function __construct(NavPointOfInterest $poi, string $file)
    {
        $this->poi  = $poi;
        $this->file = $file;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPointOfInterest(): NavPointOfInterest
    {
        return $this->poi;
    }

    public function getFile(): string
    {
        return $this->file;
    }
}
