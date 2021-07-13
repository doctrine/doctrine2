<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Company;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * @Entity
 */
#[ORM\Entity]
class CompanyFixContract extends CompanyContract
{
    /**
     * @column(type="integer")
     * @var int
     */
    private $fixPrice = 0;

    public function calculatePrice(): int
    {
        return $this->fixPrice;
    }

    public function getFixPrice(): int
    {
        return $this->fixPrice;
    }

    public function setFixPrice($fixPrice): void
    {
        $this->fixPrice = $fixPrice;
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'type'      => 'integer',
                'name'      => 'fixPrice',
                'fieldName' => 'fixPrice',
            ]
        );
    }
}
