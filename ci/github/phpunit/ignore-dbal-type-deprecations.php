<?php

declare(strict_types=1);

use Doctrine\Deprecations\Deprecation;

require_once __DIR__ . '/../../../vendor/autoload.php';

// The ORM 3.7 resolves DBAL types through the global registry. DBAL 4.6 deprecates the
// static methods of Doctrine\DBAL\Types\Type in favor of the type provider of the
// connection. These deprecations are ignored on this branch until the ORM supports
// connection-scoped type providers.
Deprecation::ignoreDeprecations(
    'https://github.com/doctrine/dbal/pull/7342',
    'https://github.com/doctrine/dbal/pull/7490',
);
