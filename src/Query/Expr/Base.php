<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

use InvalidArgumentException;
use Override;
use Stringable;

use function array_key_exists;
use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Abstract base Expr class for building DQL parts.
 *
 * @link    www.doctrine-project.org
 */
abstract class Base implements Stringable
{
    protected string $preSeparator  = '(';
    protected string $separator     = ', ';
    protected string $postSeparator = ')';

    /** @var list<class-string<Stringable>> */
    protected array $allowedClasses = [];

    /** @var list<string|Stringable> */
    protected array $parts = [];

    public function __construct(mixed $args = [])
    {
        if (is_array($args) && array_key_exists(0, $args) && is_array($args[0])) {
            $args = $args[0];
        }

        $this->addMultiple($args);
    }

    /**
     * @param string[]|object[]|string|object $args
     * @phpstan-param list<string|object>|string|object $args
     *
     * @return $this
     */
    public function addMultiple(array|string|object $args = []): static
    {
        foreach ((array) $args as $arg) {
            $this->add($arg);
        }

        return $this;
    }

    /**
     * @param string|Stringable|null $arg
     *
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function add(mixed $arg): static
    {
        if ($arg !== null && (! $arg instanceof self || $arg->count() > 0)) {
            // If we decide to keep Expr\Base instances, we can use this check
            if (! is_string($arg) && ! (is_object($arg) && in_array($arg::class, $this->allowedClasses, true))) {
                throw new InvalidArgumentException(sprintf(
                    "Expression of type '%s' not allowed in this context.",
                    get_debug_type($arg),
                ));
            }

            $this->parts[] = $arg;
        }

        return $this;
    }

    /** @phpstan-return 0|positive-int */
    public function count(): int
    {
        return count($this->parts);
    }

    #[Override]
    public function __toString(): string
    {
        if ($this->count() === 1) {
            return (string) $this->parts[0];
        }

        return $this->preSeparator . implode($this->separator, $this->parts) . $this->postSeparator;
    }
}
