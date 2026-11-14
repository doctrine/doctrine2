<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

final class UuidObject
{
    public function __construct(public readonly string $bytes)
    {
    }
}
