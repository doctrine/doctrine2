<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\BinaryType;

use function bin2hex;
use function hex2bin;
use function is_resource;
use function stream_get_contents;

/**
 * Stores a hexadecimal string as the raw bytes it encodes, the way Symfony's
 * UID types store their values on platforms without a native GUID type.
 */
class HexBinaryType extends BinaryType
{
    public const NAME = 'hex_binary';

    /**
     * {@inheritDoc}
     *
     * @param string|null $value
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): string|null
    {
        return $value === null ? null : (string) hex2bin($value);
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): string|null
    {
        // DBAL 3 hydrates binary columns as a stream, DBAL 4 as a string
        $value = parent::convertToPHPValue($value, $platform);

        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }

        return $value === null ? null : bin2hex($value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
