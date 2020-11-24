<?php

namespace Doctrine\ORM\Utility;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;

final class SQLResultCaser
{
    public static function casing(AbstractPlatform $platform, string $column): string
    {
        if ($platform instanceof PostgreSQL94Platform) {
            return strtolower($column);
        } else if (
            $platform instanceof OraclePlatform ||
            $platform instanceof DB2Platform
        ) {
            return strtoupper($column);
        }

        return $column;
    }
}
