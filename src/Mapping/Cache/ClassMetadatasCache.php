<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Cache;

use Doctrine\ORM\Mapping\ReadonlyClassMetadataBag;

/**
 * Loads and stores a complete {@see ReadonlyClassMetadataBag} snapshot for a {@see
 * \Doctrine\ORM\Mapping\CompiledClassMetadataFactory}. An implementation decides on its own how
 * (or whether) it splits the snapshot across multiple storage units internally; this contract
 * only ever deals in whole snapshots.
 */
interface ClassMetadatasCache
{
    /** Returns null if no snapshot is stored yet. */
    public function load(): ReadonlyClassMetadataBag|null;

    public function save(ReadonlyClassMetadataBag $classMetadatas): void;
}
