<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Export;

use Doctrine\ORM\Exception\ORMException;

use function sprintf;

/**
 * @deprecated 2.7 This class is being removed from the ORM and won't have any replacement
 */
class ExportException extends ORMException
{
    /**
     * @param string $type
     *
     * @return ExportException
     */
    public static function invalidExporterDriverType($type)
    {
        return new self(sprintf(
            "The specified export driver '%s' does not exist",
            $type
        ));
    }

    /**
     * @param string $type
     *
     * @return ExportException
     */
    public static function invalidMappingDriverType($type)
    {
        return new self(sprintf(
            "The mapping driver '%s' does not exist",
            $type
        ));
    }

    /**
     * @param string $file
     *
     * @return ExportException
     */
    public static function attemptOverwriteExistingFile($file)
    {
        return new self("Attempting to overwrite an existing file '" . $file . "'.");
    }
}
