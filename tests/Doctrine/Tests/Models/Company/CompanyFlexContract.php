<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Company;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * @Entity
 * @NamedNativeQueries({
 *      @NamedNativeQuery(
 *          name           = "all",
 *          resultClass    = "__CLASS__",
 *          query          = "SELECT id, hoursWorked, discr FROM company_contracts"
 *      ),
 *      @NamedNativeQuery(
 *          name           = "all-flex",
 *          resultClass    = "CompanyFlexContract",
 *          query          = "SELECT id, hoursWorked, discr FROM company_contracts"
 *      ),
 * })
 * @SqlResultSetMappings({
 *      @SqlResultSetMapping(
 *          name    = "mapping-all-flex",
 *          entities= {
 *              @EntityResult(
 *                  entityClass         = "__CLASS__",
 *                  discriminatorColumn = "discr",
 *                  fields              = {
 *                      @FieldResult("id"),
 *                      @FieldResult("hoursWorked"),
 *                  }
 *              )
 *          }
 *      ),
 *      @SqlResultSetMapping(
 *          name    = "mapping-all",
 *          entities= {
 *              @EntityResult(
 *                  entityClass         = "CompanyFlexContract",
 *                  discriminatorColumn = "discr",
 *                  fields              = {
 *                      @FieldResult("id"),
 *                      @FieldResult("hoursWorked"),
 *                  }
 *              )
 *          }
 *      ),
 * })
 */
#[ORM\Entity]
class CompanyFlexContract extends CompanyContract
{
    /**
     * @column(type="integer")
     * @var int
     */
    private $hoursWorked = 0;

    /**
     * @var int
     * @Column(type="integer")
     */
    private $pricePerHour = 0;

    /**
     * @psalm-var Collection<int, CompanyManager>
     * @ManyToMany(targetEntity="CompanyManager", inversedBy="managedContracts", fetch="EXTRA_LAZY")
     * @JoinTable(name="company_contract_managers",
     *    joinColumns={@JoinColumn(name="contract_id", referencedColumnName="id", onDelete="CASCADE")},
     *    inverseJoinColumns={@JoinColumn(name="employee_id", referencedColumnName="id")}
     * )
     */
    public $managers;

    public function calculatePrice(): int
    {
        return $this->hoursWorked * $this->pricePerHour;
    }

    public function getHoursWorked(): int
    {
        return $this->hoursWorked;
    }

    public function setHoursWorked(int $hoursWorked): void
    {
        $this->hoursWorked = $hoursWorked;
    }

    public function getPricePerHour(): int
    {
        return $this->pricePerHour;
    }

    public function setPricePerHour(int $pricePerHour): void
    {
        $this->pricePerHour = $pricePerHour;
    }

    /**
     * @psalm-return Collection<int, CompanyManager>
     */
    public function getManagers(): Collection
    {
        return $this->managers;
    }

    public function addManager(CompanyManager $manager): void
    {
        $this->managers[] = $manager;
    }

    public function removeManager(CompanyManager $manager): void
    {
        $this->managers->removeElement($manager);
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'type'      => 'integer',
                'name'      => 'hoursWorked',
                'fieldName' => 'hoursWorked',
            ]
        );

        $metadata->mapField(
            [
                'type'      => 'integer',
                'name'      => 'pricePerHour',
                'fieldName' => 'pricePerHour',
            ]
        );
    }
}
