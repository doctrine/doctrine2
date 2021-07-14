<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Company;

use DateTime;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="company_employees")
 */
class CompanyEmployee extends CompanyPerson
{
    /**
     * @var int
     * @Column(type="integer")
     */
    private $salary;

    /**
     * @var string
     * @Column(type="string", length=255)
     */
    private $department;

    /**
     * @var DateTime|null
     * @Column(type="datetime", nullable=true)
     */
    private $startDate;

    /**
     * @psalm-var Collection<int, CompanyContract>
     * @ManyToMany(targetEntity="CompanyContract", mappedBy="engineers", fetch="EXTRA_LAZY")
     */
    public $contracts;

    /**
     * @psalm-var Collection<int, CompanyFlexUltraContract>
     * @OneToMany(targetEntity="CompanyFlexUltraContract", mappedBy="salesPerson", fetch="EXTRA_LAZY")
     */
    public $soldContracts;

    public function getSalary(): int
    {
        return $this->salary;
    }

    public function setSalary(int $salary): void
    {
        $this->salary = $salary;
    }

    public function getDepartment(): string
    {
        return $this->department;
    }

    public function setDepartment(string $dep): void
    {
        $this->department = $dep;
    }

    public function getStartDate(): ?DateTime
    {
        return $this->startDate;
    }

    public function setStartDate(DateTime $date): void
    {
        $this->startDate = $date;
    }
}
