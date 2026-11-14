<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\EntityManagerProvider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Override;

final class SingleManagerProvider implements EntityManagerProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $defaultManagerName = 'default',
    ) {
    }

    #[Override]
    public function getDefaultManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    #[Override]
    public function getManager(string $name): EntityManagerInterface
    {
        if ($name !== $this->defaultManagerName) {
            throw UnknownManagerException::unknownManager($name, [$this->defaultManagerName]);
        }

        return $this->entityManager;
    }
}
