<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\ORM\Internal\Hydration\AbstractHydrator;

/** Observes SQL result hydration for profiling. Calls may be nested. */
interface HydrationObserver
{
    /**
     * @param class-string<AbstractHydrator> $hydratorClass
     * @param array<string, class-string>    $entityClasses Entity classes indexed by result alias.
     */
    public function start(string $hydratorClass, array $entityClasses): void;

    /**
     * Called even if hydration fails. The count is null on failure or for non-array bulk results.
     * For streamed results, the count is 1 for a yielded result and 0 at exhaustion.
     */
    public function end(int|null $resultCount): void;
}
