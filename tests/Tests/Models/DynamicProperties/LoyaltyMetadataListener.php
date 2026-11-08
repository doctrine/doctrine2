<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DynamicProperties;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

use function is_a;

/**
 * Simulates a third-party plugin adding columns to an entity it does not own.
 */
final class LoyaltyMetadataListener
{
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if (
            ! is_a($metadata->getName(), Customer::class, true)
            || ! isset($metadata->dynamicProperties['extra'])
            || $metadata->hasField('loyalty_points')
        ) {
            return;
        }

        $metadata->mapDynamicField('extra', [
            'fieldName' => 'loyalty_points',
            'type' => 'integer',
            'nullable' => true,
        ]);

        $metadata->mapDynamicManyToOne('extra', [
            'fieldName' => 'loyalty_tier',
            'targetEntity' => LoyaltyTier::class,
            'joinColumns' => [['name' => 'loyalty_tier_id', 'referencedColumnName' => 'id', 'nullable' => true]],
        ]);
    }
}
