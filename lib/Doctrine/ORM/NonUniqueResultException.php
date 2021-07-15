<?php

declare(strict_types=1);

namespace Doctrine\ORM;

/**
 * Exception thrown when an ORM query unexpectedly returns more than one result.
 */
class NonUniqueResultException extends UnexpectedResultException
{
    public const DEFAULT_MESSAGE = 'More than one result was found for query although one row or none was expected.';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? self::DEFAULT_MESSAGE);
    }
}
