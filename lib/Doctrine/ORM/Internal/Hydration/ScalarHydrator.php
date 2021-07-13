<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

/**
 * Hydrator that produces flat, rectangular results of scalar data.
 * The created result is almost the same as a regular SQL result set, except
 * that column names are mapped to field names and data type conversions take place.
 */
class ScalarHydrator extends AbstractHydrator
{
    /**
     * {@inheritdoc}
     */
    protected function hydrateAllData()
    {
        $result = [];

        while ($data = $this->_stmt->fetchAssociative()) {
            $this->hydrateRowData($data, $result);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrateRowData(array $row, array &$result)
    {
        $result[] = $this->gatherScalarRowData($row);
    }
}
