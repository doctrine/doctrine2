<?php

namespace Doctrine\Tests\Models\Company;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @Entity
 */
class CompanyFixContract extends CompanyContract
{
    /**
     * @column(type="integer")
     * @var int
     */
    private $fixPrice = 0;

    public function calculatePrice()
    {
        return $this->fixPrice;
    }

    public function getFixPrice()
    {
        return $this->fixPrice;
    }

    public function setFixPrice($fixPrice)
    {
        $this->fixPrice = $fixPrice;
    }

    static public function loadMetadata(ClassMetadata $metadata)
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
