<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

use Stringable;

/**
 * A value object whose string representation is hexadecimal, stored as the bytes it encodes
 * by {@see BinaryCodeType}, the way Symfony's UID types store their values on platforms
 * without a native GUID type.
 */
class BinaryCode implements Stringable
{
    public function __construct(public readonly string $hex)
    {
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}
