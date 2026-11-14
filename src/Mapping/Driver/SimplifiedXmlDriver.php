<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\Persistence\Mapping\Driver\SymfonyFileLocator;

/**
 * XmlDriver that additionally looks for mapping information in a global file.
 */
class SimplifiedXmlDriver extends XmlDriver
{
    final public const string DEFAULT_FILE_EXTENSION = '.orm.xml';

    /**
     * {@inheritDoc}
     */
    public function __construct($prefixes, $fileExtension = self::DEFAULT_FILE_EXTENSION, bool $isXsdValidationEnabled = true)
    {
        $locator = new SymfonyFileLocator((array) $prefixes, $fileExtension);

        parent::__construct($locator, $fileExtension, $isXsdValidationEnabled);
    }
}
