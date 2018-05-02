<?php

declare(strict_types=1);

namespace Doctrine\ORM\Repository\Exception;

use Doctrine\ORM\Exception\ORMException;

/**
 * This interface should be implemented by all exceptions in the Repository
 * namespace.
 */
interface RepositoryException extends ORMException
{
}
