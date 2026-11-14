<?php

declare(strict_types=1);

/*
 * This file bootstraps the test environment.
 */

namespace Doctrine\Tests;

use Doctrine\Deprecations\Deprecation;
use Exception;

use function date_default_timezone_set;
use function error_reporting;
use function file_exists;
use function mkdir;

use const E_ALL;

error_reporting(E_ALL);
date_default_timezone_set('UTC');

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    // dependencies were installed via composer - this is the main project
    require __DIR__ . '/../../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../../autoload.php')) {
    // installed as a dependency in `vendor`
    require __DIR__ . '/../../../../autoload.php';
} else {
    throw new Exception('Can\'t find autoload.php. Did you install dependencies via composer?');
}

if (! file_exists(__DIR__ . '/Proxies') && ! mkdir(__DIR__ . '/Proxies')) {
    throw new Exception('Could not create ' . __DIR__ . '/Proxies Folder.');
}

if (! file_exists(__DIR__ . '/ORM/Proxy/generated') && ! mkdir(__DIR__ . '/ORM/Proxy/generated')) {
    throw new Exception('Could not create ' . __DIR__ . '/ORM/Proxy/generated Folder.');
}

Deprecation::withoutDeduplication();
