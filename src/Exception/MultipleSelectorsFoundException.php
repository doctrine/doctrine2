<?php

declare(strict_types=1);

namespace Doctrine\ORM\Exception;

use LogicException;

use function implode;
use function sprintf;

final class MultipleSelectorsFoundException extends LogicException implements ORMException
{
    final public const string MULTIPLE_SELECTORS_FOUND_EXCEPTION = 'Multiple selectors found: %s. Please select only one.';

    /** @param string[] $selectors */
    public static function create(array $selectors): self
    {
        return new self(
            sprintf(
                self::MULTIPLE_SELECTORS_FOUND_EXCEPTION,
                implode(', ', $selectors),
            ),
        );
    }
}
