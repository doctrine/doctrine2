<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;
use Exception;

class GH8565EmployeePayloadType extends JsonType
{
    final public const string NAME = 'GH8565EmployeePayloadType';

    public function convertToPHPValue($value, AbstractPlatform $platform): string
    {
        if (! isset($value['GH8565EmployeePayloadRequiredField'])) {
            throw new Exception('GH8565EmployeePayloadType cannot be initialized without required field');
        }

        return $value['GH8565EmployeePayloadRequiredField'];
    }
}
