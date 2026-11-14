<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use Doctrine\DBAL\Driver\Exception;
use Doctrine\ORM\Exception\MultipleSelectorsFoundException;
use Override;

use function count;

/**
 * Hydrator that produces one-dimensional array.
 */
final class ScalarColumnHydrator extends AbstractHydrator
{
    /**
     * {@inheritDoc}
     *
     * @throws MultipleSelectorsFoundException
     * @throws Exception
     */
    #[Override]
    protected function hydrateAllData(): array
    {
        if (count($this->resultSetMapping()->fieldMappings) > 1) {
            throw MultipleSelectorsFoundException::create($this->resultSetMapping()->fieldMappings);
        }

        return $this->statement()->fetchFirstColumn();
    }
}
