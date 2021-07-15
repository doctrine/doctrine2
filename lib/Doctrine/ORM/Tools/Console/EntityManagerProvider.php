<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console;

use Doctrine\ORM\EntityManagerInterface;

interface EntityManagerProvider
{
    public function getDefaultManager(): EntityManagerInterface;

    public function getManager(string $name): EntityManagerInterface;
}
