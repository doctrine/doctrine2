<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

use function array_shift;
use function count;
use function key;

/**
 * Hydrator that hydrates a single scalar value from the result set.
 */
class SingleScalarHydrator extends AbstractHydrator
{
    protected function hydrateAllData(): mixed
    {
        $data    = $this->statement()->fetchAllAssociative();
        $numRows = count($data);

        if ($numRows === 0) {
            throw new NoResultException();
        }

        if ($numRows > 1) {
            throw new NonUniqueResultException('The query returned multiple rows. Change the query or use a different result function like getScalarResult().');
        }

        $result = $this->gatherScalarRow($data[key($data)]);

        if (count($result) > 1) {
            throw new NonUniqueResultException('The query returned a row containing multiple columns. Change the query or use a different result function like getScalarResult().');
        }

        return array_shift($result);
    }
}
