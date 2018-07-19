<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\ORM\ORMException;
use Throwable;

/**
 * Tools related Exceptions.
 */
class ToolsException extends \RuntimeException implements ORMException
{
    public static function schemaToolFailure(string $sql, Throwable $e) : self
    {
        return new self("Schema-Tool failed with Error '" . $e->getMessage() . "' while executing DDL: " . $sql, 0, $e);
    }
}
