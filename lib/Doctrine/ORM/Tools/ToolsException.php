<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\ORM\Exception\ORMException;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Tools related Exceptions.
 */
class ToolsException extends ORMException
{
    public static function schemaToolFailure(string $sql, Throwable $e): self
    {
        return new self(
            "Schema-Tool failed with Error '" . $e->getMessage() . "' while executing DDL: " . $sql,
            0,
            $e
        );
    }

    /**
     * @param string $type
     *
     * @return ToolsException
     */
    public static function couldNotMapDoctrine1Type($type)
    {
        return new self(sprintf("Could not map doctrine 1 type '%s'!", $type));
    }
}
