<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\TypeProvider;
use Doctrine\DBAL\Types\TypeRegistry;

use function method_exists;

/**
 * Resolves the {@see TypeProvider} owned by a DBAL connection.
 *
 * @internal
 */
final class TypeProviderLocator
{
    public static function fromConnection(Connection $connection): TypeProvider|TypeRegistry
    {
        $configuration = $connection->getConfiguration();

        // The method_exists() check is for DBAL < 4.6 compatibility, where Configuration::getTypeProvider() does not exist yet.
        // @phpstan-ignore function.alreadyNarrowedType (DBAL < 4.6 compatibility)
        if (method_exists($configuration, 'getTypeProvider')) {
            return $configuration->getTypeProvider();
        }

        /** @phpstan-ignore staticMethod.deprecated (DBAL < 4.6 compatibility) */
        return Type::getTypeRegistry();
    }
}
