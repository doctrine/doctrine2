<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\Persistence\Mapping\Driver\SymfonyFileLocator;

/**
 * YamlDriver that additionally looks for mapping information in a global file.
 */
class SimplifiedYamlDriver extends YamlDriver
{
    public const DEFAULT_FILE_EXTENSION = '.orm.yml';

    /**
     * {@inheritDoc}
     */
    public function __construct($prefixes, $fileExtension = self::DEFAULT_FILE_EXTENSION)
    {
        $locator = new SymfonyFileLocator((array) $prefixes, $fileExtension);
        parent::__construct($locator, $fileExtension);
    }
}
