<?php

declare(strict_types=1);

namespace Doctrine\ORM\Exception;

use LogicException;

use function sprintf;

/** @deprecated */
final class NotSupported extends LogicException implements ORMException
{
    public static function create(): self
    {
        return new self('This behaviour is (currently) not supported by Doctrine 2');
    }

    public static function createForDbal3(string $context): self
    {
        return new self(sprintf(
            <<<'EXCEPTION'
Context: %s
Problem: Feature was deprecated in doctrine/dbal 2.x and is not supported by installed doctrine/dbal:3.x
Solution: See the doctrine/deprecations logs for new alternative approaches.
EXCEPTION
            ,
            $context,
        ));
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

    public static function createForPersistence3(string $context): self
    {
        return new self(sprintf(
            <<<'EXCEPTION'
Context: %s
Problem: Feature was deprecated in doctrine/persistence 2.x and is not supported by installed doctrine/persistence:3.x
Solution: See the doctrine/deprecations logs for new alternative approaches.
EXCEPTION
            ,
            $context,
        ));
    }
}
