<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

use function method_exists;
use function strtolower;
use function strtoupper;

/** @internal */
trait SQLResultCasing
{
    private function getSQLResultCasing(AbstractPlatform $platform, string $column): string
    {
        if ($column === '') {
            return $column;
        }

        // @phpstan-ignore function.alreadyNarrowedType (DBAL 3 compatibility)
        if (method_exists($platform, 'getUnquotedIdentifierFolding')) {
            return $platform->getUnquotedIdentifierFolding()->foldUnquotedIdentifier($column);
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return strtolower($column);
        }

        if ($platform instanceof DB2Platform || $platform instanceof OraclePlatform) {
            return strtoupper($column);
        }

        return $column;
    }
}
