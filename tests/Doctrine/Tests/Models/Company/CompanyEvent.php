<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Company;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity @Table(name="company_events")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="event_type", type="string")
 * @DiscriminatorMap({"auction"="CompanyAuction", "raffle"="CompanyRaffle"})
 */
abstract class CompanyEvent
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var CompanyOrganization
     * @ManyToOne(targetEntity="CompanyOrganization", inversedBy="events", cascade={"persist"})
     * @JoinColumn(name="org_id", referencedColumnName="id")
     */
     private $organization;

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrganization(): CompanyOrganization
    {
        return $this->organization;
    }

    public function setOrganization(CompanyOrganization $org): void
    {
        $this->organization = $org;
    }
}
