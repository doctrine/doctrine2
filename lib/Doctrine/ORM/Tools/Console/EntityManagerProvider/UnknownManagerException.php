<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\EntityManagerProvider;

use OutOfBoundsException;

use function implode;
use function sprintf;

final class UnknownManagerException extends OutOfBoundsException
{
    /**
     * @psalm-param list<string> $knownManagers
     */
    public static function unknownManager(string $unknownManager, array $knownManagers = []): self
    {
        return new self(sprintf(
            'Requested unknown entity manager: %s, known managers: %s',
            $unknownManager,
            implode(', ', $knownManagers)
        ));
    }
}
