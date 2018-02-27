<?php

namespace Doctrine\Tests\Models\Company;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @Entity
 * @Table(name="company_contracts")
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="discr", type="string")
 * @EntityListeners({"CompanyContractListener"})
 * @DiscriminatorMap({
 *     "fix"       = "CompanyFixContract",
 *     "flexible"  = "CompanyFlexContract",
 *     "flexultra" = "CompanyFlexUltraContract"
 * })
 *
 * @NamedNativeQueries({
 *      @NamedNativeQuery(
 *          name           = "all-contracts",
 *          resultClass    = "__CLASS__",
 *          query          = "SELECT id, completed, discr FROM company_contracts"
 *      ),
 *      @NamedNativeQuery(
 *          name           = "all",
 *          resultClass    = "__CLASS__",
 *          query          = "SELECT id, completed, discr FROM company_contracts"
 *      ),
 * })
 *
 * @SqlResultSetMappings({
 *      @SqlResultSetMapping(
 *          name    = "mapping-all-contracts",
 *          entities= {
 *              @EntityResult(
 *                  entityClass         = "__CLASS__",
 *                  discriminatorColumn = "discr",
 *                  fields              = {
 *                      @FieldResult("id"),
 *                      @FieldResult("completed"),
 *                  }
 *              )
 *          }
 *      ),
 *      @SqlResultSetMapping(
 *          name    = "mapping-all",
 *          entities= {
 *              @EntityResult(
 *                  entityClass         = "__CLASS__",
 *                  discriminatorColumn = "discr",
 *                  fields              = {
 *                      @FieldResult("id"),
 *                      @FieldResult("completed"),
 *                  }
 *              )
 *          }
 *      ),
 * })
 */
abstract class CompanyContract
{
    /**
     * @Id @column(type="integer") @GeneratedValue
     */
    private $id;

    /**
     * @ManyToOne(targetEntity="CompanyEmployee", inversedBy="soldContracts")
     */
    private $salesPerson;

    /**
     * @Column(type="boolean")
     * @var bool
     */
    private $completed = false;

    /**
     * @ManyToMany(targetEntity="CompanyEmployee", inversedBy="contracts")
     * @JoinTable(name="company_contract_employees",
     *    joinColumns={@JoinColumn(name="contract_id", referencedColumnName="id", onDelete="CASCADE")},
     *    inverseJoinColumns={@JoinColumn(name="employee_id", referencedColumnName="id")}
     * )
     */
    private $engineers;

    public function __construct()
    {
        $this->engineers = new \Doctrine\Common\Collections\ArrayCollection;
    }

    public function getId()
    {
        return $this->id;
    }

    public function markCompleted()
    {
        $this->completed = true;
    }

    public function isCompleted()
    {
        return $this->completed;
    }

    public function getSalesPerson()
    {
        return $this->salesPerson;
    }

    public function setSalesPerson(CompanyEmployee $salesPerson)
    {
        $this->salesPerson = $salesPerson;
    }

    public function getEngineers()
    {
        return $this->engineers;
    }

    public function addEngineer(CompanyEmployee $engineer)
    {
        $this->engineers[] = $engineer;
    }

    public function removeEngineer(CompanyEmployee $engineer)
    {
        $this->engineers->removeElement($engineer);
    }

    abstract public function calculatePrice();

    static public function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_JOINED);
        $metadata->setTableName( 'company_contracts');
        $metadata->setDiscriminatorColumn(
            [
            'name' => 'discr',
            'type' => 'string',
            ]
        );

        $metadata->mapField(
            [
            'id'        => true,
            'name'      => 'id',
            'fieldName' => 'id',
            ]
        );

        $metadata->mapField(
            [
            'type'      => 'boolean',
            'name'      => 'completed',
            'fieldName' => 'completed',
            ]
        );

        $metadata->setDiscriminatorMap(
            [
            "fix"       => "CompanyFixContract",
            "flexible"  => "CompanyFlexContract",
            "flexultra" => "CompanyFlexUltraContract"
            ]
        );

        $metadata->addEntityListener(\Doctrine\ORM\Events::postPersist, 'CompanyContractListener', 'postPersistHandler');
        $metadata->addEntityListener(\Doctrine\ORM\Events::prePersist, 'CompanyContractListener', 'prePersistHandler');

        $metadata->addEntityListener(\Doctrine\ORM\Events::postUpdate, 'CompanyContractListener', 'postUpdateHandler');
        $metadata->addEntityListener(\Doctrine\ORM\Events::preUpdate, 'CompanyContractListener', 'preUpdateHandler');

        $metadata->addEntityListener(\Doctrine\ORM\Events::postRemove, 'CompanyContractListener', 'postRemoveHandler');
        $metadata->addEntityListener(\Doctrine\ORM\Events::preRemove, 'CompanyContractListener', 'preRemoveHandler');

        $metadata->addEntityListener(\Doctrine\ORM\Events::preFlush, 'CompanyContractListener', 'preFlushHandler');
        $metadata->addEntityListener(\Doctrine\ORM\Events::postLoad, 'CompanyContractListener', 'postLoadHandler');
    }
}
