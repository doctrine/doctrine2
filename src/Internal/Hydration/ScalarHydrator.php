<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use Override;

/**
 * Hydrator that produces flat, rectangular results of scalar data.
 * The created result is almost the same as a regular SQL result set, except
 * that column names are mapped to field names and data type conversions take place.
 */
class ScalarHydrator extends AbstractHydrator
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function hydrateAllData(): array
    {
        $this->hydrateAllRows();

        return $this->takeResult();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function hydrateRow(array $row): void
    {
        $this->hydrationResult[] = $this->gatherScalarRow($row);
    }
}
