<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\BinaryPrimaryKey;

use LogicException;

use function bin2hex;
use function hex2bin;
use function random_bytes;

class BinaryId
{
    public const int LENGTH = 6;

    private string $hexId;

    private function __construct(string $data)
    {
        $this->hexId = $data;
    }

    public static function new(): self
    {
        return new self(bin2hex(random_bytes(self::LENGTH)));
    }

    public static function fromBytes(string $value): self
    {
        return new self(bin2hex($value));
    }

    public function getBytes(): string
    {
        $binary = hex2bin($this->hexId);
        if ($binary === false) {
            throw new LogicException('Cannot convert hex to binary: ' . $this->hexId);
        }

        return $binary;
    }

    public function __toString(): string
    {
        return $this->getBytes();
    }
}
