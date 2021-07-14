<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table("cache_traveler_profile_info")
 * @Cache("NONSTRICT_READ_WRITE")
 */
class TravelerProfileInfo
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    protected $id;

    /**
     * @var string
     * @Column(unique=true)
     */
    private $description;

    /**
     * @var TravelerProfile
     * @Cache()
     * @JoinColumn(name="profile_id", referencedColumnName="id")
     * @OneToOne(targetEntity="TravelerProfile", inversedBy="info")
     */
    private $profile;

    public function __construct(TravelerProfile $profile, string $description)
    {
        $this->profile     = $profile;
        $this->description = $description;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getProfile(): TravelerProfile
    {
        return $this->profile;
    }

    public function setProfile(TravelerProfile $profile): void
    {
        $this->profile = $profile;
    }
}
