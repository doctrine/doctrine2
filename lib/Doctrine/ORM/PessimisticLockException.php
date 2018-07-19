<?php

declare(strict_types=1);

namespace Doctrine\ORM;

class PessimisticLockException extends \RuntimeException implements ORMException
{
    /**
     * @return PessimisticLockException
     */
    public static function lockFailed()
    {
        return new self('The pessimistic lock failed.');
    }
}
