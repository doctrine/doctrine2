<?php
namespace Doctrine\Tests\Models\Company;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @Entity
 *
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
 *
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
class CompanyFlexContract extends CompanyContract
{
    /**
     * @column(type="integer")
     * @var int
     */
    private $hoursWorked = 0;

    /**
     * @column(type="integer")
     * @var int
     */
    private $pricePerHour = 0;

    /**
     * @ManyToMany(targetEntity="CompanyManager", inversedBy="managedContracts", fetch="EXTRA_LAZY")
     * @JoinTable(name="company_contract_managers",
     *    joinColumns={@JoinColumn(name="contract_id", referencedColumnName="id", onDelete="CASCADE")},
     *    inverseJoinColumns={@JoinColumn(name="employee_id", referencedColumnName="id")}
     * )
     */
    public $managers;

    public function calculatePrice()
    {
        return $this->hoursWorked * $this->pricePerHour;
    }

    public function getHoursWorked()
    {
        return $this->hoursWorked;
    }

    public function setHoursWorked($hoursWorked)
    {
        $this->hoursWorked = $hoursWorked;
    }

    public function getPricePerHour()
    {
        return $this->pricePerHour;
    }

    public function setPricePerHour($pricePerHour)
    {
        $this->pricePerHour = $pricePerHour;
    }
    public function getManagers()
    {
        return $this->managers;
    }

    public function addManager(CompanyManager $manager)
    {
        $this->managers[] = $manager;
    }

    public function removeManager(CompanyManager $manager)
    {
        $this->managers->removeElement($manager);
    }

    static public function loadMetadata(ClassMetadata $metadata)
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
