<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

use function implode;
use function is_object;
use function preg_match;

/**
 * Expression class for building DQL and parts.
 *
 * @link    www.doctrine-project.org
 */
class Composite extends Base
{
    /**
     * @return string
     */
    public function __toString()
    {
        if ($this->count() === 1) {
            return (string) $this->parts[0];
        }

        $components = [];

        foreach ($this->parts as $part) {
            $components[] = $this->processQueryPart($part);
        }

        return implode($this->separator, $components);
    }

    /**
     * @param string|object $part
     */
    private function processQueryPart($part): string
    {
        $queryPart = (string) $part;

        if (is_object($part) && $part instanceof self && $part->count() > 1) {
            return $this->preSeparator . $queryPart . $this->postSeparator;
        }

        // Fixes DDC-1237: User may have added a where item containing nested expression (with "OR" or "AND")
        if (preg_match('/\s(OR|AND)\s/i', $queryPart)) {
            return $this->preSeparator . $queryPart . $this->postSeparator;
        }

        return $queryPart;
    }
}
