<?php

namespace Doctrine\Tests\Models\Company;

use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\EntityListeners;

use function max;

/**
 * @Entity
 * @EntityListeners({"CompanyContractListener","CompanyFlexUltraContractListener"})
 */
#[ORM\Entity]
#[ORM\EntityListeners(['CompanyContractListener', 'CompanyFlexUltraContractListener'])]
class CompanyFlexUltraContract extends CompanyFlexContract
{
    /**
     * @Column(type="integer")
     * @var int
     */
    private $maxPrice = 0;

    public function calculatePrice(): int
    {
        return max($this->maxPrice, parent::calculatePrice());
    }

    public function getMaxPrice(): int
    {
        return $this->maxPrice;
    }

    public function setMaxPrice(int $maxPrice): void
    {
        $this->maxPrice = $maxPrice;
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'type'      => 'integer',
                'name'      => 'maxPrice',
                'fieldName' => 'maxPrice',
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

        $metadata->addEntityListener(Events::prePersist, 'CompanyFlexUltraContractListener', 'prePersistHandler1');
        $metadata->addEntityListener(Events::prePersist, 'CompanyFlexUltraContractListener', 'prePersistHandler2');
    }
}
