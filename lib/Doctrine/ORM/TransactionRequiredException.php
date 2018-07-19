<?php

declare(strict_types=1);

namespace Doctrine\ORM;

/**
 * Is thrown when a transaction is required for the current operation, but there is none open.
 */
class TransactionRequiredException extends \LogicException implements ORMException
{
    /**
     * @return TransactionRequiredException
     */
    public static function transactionRequired()
    {
        return new self('An open transaction is required for this operation.');
    }
}
