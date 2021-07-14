<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC3597;

use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\Tests\Models\DDC3597\Embeddable\DDC3597Dimension;

/**
 * Description of Image
 *
 * @Entity
 */
class DDC3597Image extends DDC3597Media
{
    /**
     * @var DDC3597Dimension
     * @Embedded(class = "Doctrine\Tests\Models\DDC3597\Embeddable\DDC3597Dimension", columnPrefix = false)
     */
    private $dimension;

    public function __construct(string $distributionHash)
    {
        parent::__construct($distributionHash);
        $this->dimension = new DDC3597Dimension();
    }

    public function getDimension(): DDC3597Dimension
    {
        return $this->dimension;
    }
}
