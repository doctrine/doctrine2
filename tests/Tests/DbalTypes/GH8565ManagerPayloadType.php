<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

class GH8565ManagerPayloadType extends JsonType
{
    final public const string NAME = 'GH8565ManagerPayloadType';

    public function convertToPHPValue($value, AbstractPlatform $platform): string
    {
        return $value;
    }
}
