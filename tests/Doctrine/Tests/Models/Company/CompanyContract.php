<?php

namespace Doctrine\Tests\Models\Company;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\EntityListeners;
use Doctrine\ORM\Mapping\EntityResult;
use Doctrine\ORM\Mapping\FieldResult;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\NamedNativeQueries;
use Doctrine\ORM\Mapping\NamedNativeQuery;
use Doctrine\ORM\Mapping\SqlResultSetMapping;
use Doctrine\ORM\Mapping\SqlResultSetMappings;
use Doctrine\ORM\Mapping\Table;

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
#[ORM\Entity, ORM\Table(name: 'company_contracts')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'discr', type: 'string')]
#[ORM\DiscriminatorMap(['fix' => 'CompanyFixContract', 'flexible' => 'CompanyFlexContract', 'flexultra' => 'CompanyFlexUltraContract'])]
#[ORM\EntityListeners(['CompanyContractListener'])]
abstract class CompanyContract
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
use Doctrine\ORM\Mapping\OrderBy;
     * @GeneratedValue
     */
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    private $id;

    /**
     * @var CompanyEmployee
     * @ManyToOne(targetEntity="CompanyEmployee", inversedBy="soldContracts")
     */
    private $salesPerson;

    /**
     * @Column(type="boolean")
     * @var bool
     */
    private $completed = false;

    /**
     * @psalm-var Collection<int, CompanyEmployee>
     * @ManyToMany(targetEntity="CompanyEmployee", inversedBy="contracts")
     * @JoinTable(name="company_contract_employees",
     *    joinColumns={@JoinColumn(name="contract_id", referencedColumnName="id", onDelete="CASCADE")},
     *    inverseJoinColumns={@JoinColumn(name="employee_id", referencedColumnName="id")}
     * )
     */
    private $engineers;

    public function __construct()
    {
        $this->engineers = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function markCompleted(): void
    {
        $this->completed = true;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function getSalesPerson(): CompanyEmployee
    {
        return $this->salesPerson;
    }

    public function setSalesPerson(CompanyEmployee $salesPerson): void
    {
        $this->salesPerson = $salesPerson;
    }

    /**
     * @psalm-return Collection<int, CompanyEmployee>
     */
    public function getEngineers(): Collection
    {
        return $this->engineers;
    }

    public function addEngineer(CompanyEmployee $engineer): void
    {
        $this->engineers[] = $engineer;
    }

    public function removeEngineer(CompanyEmployee $engineer): void
    {
        $this->engineers->removeElement($engineer);
    }

    abstract public function calculatePrice(): int;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_JOINED);
        $metadata->setTableName('company_contracts');
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
                'fix'       => 'CompanyFixContract',
                'flexible'  => 'CompanyFlexContract',
                'flexultra' => 'CompanyFlexUltraContract',
            ]
        );

        $metadata->addEntityListener(Events::postPersist, 'CompanyContractListener', 'postPersistHandler');
        $metadata->addEntityListener(Events::prePersist, 'CompanyContractListener', 'prePersistHandler');

        $metadata->addEntityListener(Events::postUpdate, 'CompanyContractListener', 'postUpdateHandler');
        $metadata->addEntityListener(Events::preUpdate, 'CompanyContractListener', 'preUpdateHandler');

        $metadata->addEntityListener(Events::postRemove, 'CompanyContractListener', 'postRemoveHandler');
        $metadata->addEntityListener(Events::preRemove, 'CompanyContractListener', 'preRemoveHandler');

        $metadata->addEntityListener(Events::preFlush, 'CompanyContractListener', 'preFlushHandler');
        $metadata->addEntityListener(Events::postLoad, 'CompanyContractListener', 'postLoadHandler');
    }
}
