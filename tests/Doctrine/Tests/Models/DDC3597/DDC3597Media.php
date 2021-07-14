<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC3597;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * Description of Media
 *
 * @Entity
 */
abstract class DDC3597Media extends DDC3597Root
{
    /**
     * @var string
     * @Column
     */
    private $distributionHash;

    /**
     * @var int
     * @Column
     */
    private $size = 0;

    /**
     * @var string
     * @Column
     */
    private $format;

    public function __construct($distributionHash)
    {
        $this->distributionHash = $distributionHash;
    }

    public function getDistributionHash(): string
    {
        return $this->distributionHash;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): void
    {
        $this->format = $format;
    }
}
