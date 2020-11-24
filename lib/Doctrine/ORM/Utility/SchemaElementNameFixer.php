<?php

namespace Doctrine\ORM\Utility;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;

final class SchemaElementNameFixer
{
    public static function fix(AbstractPlatform $platform, string $schemaElementName): string
    {
        if ($platform instanceof OraclePlatform) {
            if (strlen($schemaElementName) > 30) {
                // Trim it
                return substr($schemaElementName, 0, 30);
            }
        }

        return $schemaElementName;
    }
}
