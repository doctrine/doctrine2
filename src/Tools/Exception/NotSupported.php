<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Exception;

use Doctrine\ORM\Exception\SchemaToolException;
use LogicException;

use function sprintf;

final class NotSupported extends LogicException implements SchemaToolException
{
    public static function create(): self
    {
        return new self('This behaviour is (currently) not supported by Doctrine 2');
    }

    public static function createForDbal5(string $context): self
    {
        return new self(sprintf(
            <<<'EXCEPTION'
Context: %s
Problem: Feature was deprecated in doctrine/dbal 4.x and is not supported by installed doctrine/dbal:5.x
Solution: See the doctrine/deprecations logs for new alternative approaches.
EXCEPTION
            ,
            $context,
        ));
    }
}
