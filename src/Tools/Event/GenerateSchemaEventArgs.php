<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Event;

use BadMethodCallException;
use Doctrine\Common\EventArgs;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;

use function method_exists;

/**
 * Event Args used for the Events::postGenerateSchema event.
 *
 * @link        www.doctrine-project.com
 */
class GenerateSchemaEventArgs extends EventArgs
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private Schema $schema,
    ) {
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    public function getSchema(): Schema
    {
        return $this->schema;
    }

    public function setSchema(Schema $schema): void
    {
        // @phpstan-ignore function.alreadyNarrowedType (This API is only available in DBAL 4.5+)
        if (! method_exists(Schema::class, 'edit')) {
            throw new BadMethodCallException(
                'The setSchema() method requires the DBAL Schema::edit() API which is not available in the current DBAL version. '
                . 'This feature requires doctrine/dbal ^4.5 or higher.',
            );
        }

        $this->schema = $schema;
    }
}
