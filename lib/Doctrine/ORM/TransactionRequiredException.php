<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\ORM\Exception\ORMException;
use LogicException;

/**
 * Is thrown when a transaction is required for the current operation, but there is none open.
 *
 * @link        www.doctrine-project.com
 */
class TransactionRequiredException extends ORMException
{
    /**
     * @return TransactionRequiredException
     */
    public static function transactionRequired()
    {
        return new self('An open transaction is required for this operation.');
    }
}
