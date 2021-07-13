<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use function str_replace;
use function strtolower;
use function version_compare;

/**
 * Class to store and retrieve the version of Doctrine
 *
 * @deprecated 2.7 This class is being removed from the ORM and won't have any replacement
 *
 * @link    www.doctrine-project.org
 */
class Version
{
    /**
     * Current Doctrine Version
     */
    public const VERSION = '2.7.1-DEV';

    /**
     * Compares a Doctrine version with the current one.
     *
     * @param string $version Doctrine version to compare.
     *
     * @return int Returns -1 if older, 0 if it is the same, 1 if version
     *             passed as argument is newer.
     */
    public static function compare($version)
    {
        $currentVersion = str_replace(' ', '', strtolower(self::VERSION));
        $version        = str_replace(' ', '', $version);

        return version_compare($version, $currentVersion);
    }
}
