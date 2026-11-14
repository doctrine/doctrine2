<?php

declare(strict_types=1);

namespace Doctrine\StaticAnalysis\Repository;

use Doctrine\ORM\EntityRepository;
use SortDirection;

/** @template T of object */
final class FindBySortDirection
{
    /**
     * @param EntityRepository<T> $repository
     *
     * @return list<T>
     */
    public function findBy(EntityRepository $repository): array
    {
        return $repository->findBy([], [
            'name' => SortDirection::Ascending,
            'id'   => SortDirection::Descending,
        ]);
    }

    /**
     * @param EntityRepository<T> $repository
     *
     * @return T|null
     */
    public function findOneBy(EntityRepository $repository): object|null
    {
        return $repository->findOneBy([], [
            'name' => SortDirection::Descending,
        ]);
    }
}
