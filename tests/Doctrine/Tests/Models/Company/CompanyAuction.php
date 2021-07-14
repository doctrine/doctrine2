<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Company;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;

/** @Entity @Table(name="company_auctions") */
class CompanyAuction extends CompanyEvent
{
    /**
     * @var string
     * @Column(type="string")
     */
    private $data;

    public function setData(string $data): void
    {
        $this->data = $data;
    }

    public function getData(): string
    {
        return $this->data;
    }
}
