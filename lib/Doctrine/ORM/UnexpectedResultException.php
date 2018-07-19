<?php

declare(strict_types=1);

namespace Doctrine\ORM;

/**
 * Exception for a unexpected query result.
 */
class UnexpectedResultException extends \RuntimeException implements ORMException
{
}
