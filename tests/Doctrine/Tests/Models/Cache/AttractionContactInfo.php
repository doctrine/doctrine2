<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table("cache_attraction_contact_info")
 */
class AttractionContactInfo extends AttractionInfo
{
    /**
     * @var string
     * @Column(unique=true)
     */
    protected $fone;

    public function __construct(string $fone, Attraction $attraction)
    {
        $this->setAttraction($attraction);
        $this->setFone($fone);
    }

    public function getFone(): string
    {
        return $this->fone;
    }

    public function setFone(string $fone): void
    {
        $this->fone = $fone;
    }
}
