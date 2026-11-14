<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Event;

use BadMethodCallException;
use Doctrine\Common\EventArgs;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Mapping\ClassMetadata;

use function method_exists;

/**
 * Event Args used for the Events::postGenerateSchemaTable event.
 *
 * @link        www.doctrine-project.com
 */
class GenerateSchemaTableEventArgs extends EventArgs
{
    private bool $classTableWasMutated = false;

    public function __construct(
        private readonly ClassMetadata $classMetadata,
        private Schema $schema,
        private Table $classTable,
    ) {
    }

    public function getClassMetadata(): ClassMetadata
    {
        return $this->classMetadata;
    }

    public function getSchema(): Schema
    {
        return $this->schema;
    }

    public function getClassTable(): Table
    {
        return $this->classTable;
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

    public function setClassTable(Table $classTable): void
    {
        // @phpstan-ignore function.alreadyNarrowedType (This API is only available in DBAL 4.5+)
        if (! method_exists(Schema::class, 'edit')) {
            throw new BadMethodCallException(
                'The setClassTable() method requires the DBAL Schema::edit() API which is not available in the current DBAL version. '
                . 'This feature requires doctrine/dbal ^4.5 or higher.',
            );
        }

        $this->classTable           = $classTable;
        $this->classTableWasMutated = true;
    }

    public function classTableWasMutated(): bool
    {
        return $this->classTableWasMutated;
    }
}
