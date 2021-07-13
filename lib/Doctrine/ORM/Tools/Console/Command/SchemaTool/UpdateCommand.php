<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command\SchemaTool;

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

/**
 * Command to generate the SQL needed to update the database schema to match
 * the current mapping information.
 *
 * @link    www.doctrine-project.org
 */
class UpdateCommand extends AbstractCommand
{
    /** @var string */
    protected $name = 'orm:schema-tool:update';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName($this->name)
             ->setDescription('Executes (or dumps) the SQL needed to update the database schema to match the current mapping metadata')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('complete', null, InputOption::VALUE_NONE, 'If defined, all assets of the database which are not relevant to the current metadata will be dropped.')
             ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Dumps the generated SQL statements to the screen (does not execute them).')
             ->addOption('force', 'f', InputOption::VALUE_NONE, 'Causes the generated SQL statements to be physically executed against your database.')
             ->setHelp(<<<'EOT'
The <info>%command.name%</info> command generates the SQL needed to
synchronize the database schema with the current mapping metadata of the
default entity manager.

For example, if you add metadata for a new column to an entity, this command
would generate and output the SQL needed to add the new column to the database:

<info>%command.name% --dump-sql</info>

Alternatively, you can execute the generated queries:

<info>%command.name% --force</info>

If both options are specified, the queries are output and then executed:

<info>%command.name% --dump-sql --force</info>

Finally, be aware that if the <info>--complete</info> option is passed, this
task will drop all database assets (e.g. tables, etc) that are *not* described
by the current metadata. In other words, without this option, this task leaves
untouched any "extra" tables that exist in the database, but which aren't
described by any metadata.

<comment>Hint:</comment> If you have a database with tables that should not be managed
by the ORM, you can use a DBAL functionality to filter the tables and sequences down
on a global level:

    $config->setFilterSchemaAssetsExpression($regexp);
EOT
             );
    }

    /**
     * {@inheritdoc}
     */
    protected function executeSchemaCommand(InputInterface $input, OutputInterface $output, SchemaTool $schemaTool, array $metadatas, SymfonyStyle $ui)
    {
        // Defining if update is complete or not (--complete not defined means $saveMode = true)
        $saveMode = ! $input->getOption('complete');

        $sqls = $schemaTool->getUpdateSchemaSql($metadatas, $saveMode);

        if (empty($sqls)) {
            $ui->success('Nothing to update - your database is already in sync with the current entity metadata.');

            return 0;
        }

        $dumpSql = $input->getOption('dump-sql') === true;
        $force   = $input->getOption('force') === true;

        if ($dumpSql) {
            $ui->text('The following SQL statements will be executed:');
            $ui->newLine();

            foreach ($sqls as $sql) {
                $ui->text(sprintf('    %s;', $sql));
            }
        }

        if ($force) {
            if ($dumpSql) {
                $ui->newLine();
            }

            $ui->text('Updating database schema...');
            $ui->newLine();

            $schemaTool->updateSchema($metadatas, $saveMode);

            $pluralization = count($sqls) === 1 ? 'query was' : 'queries were';

            $ui->text(sprintf('    <info>%s</info> %s executed', count($sqls), $pluralization));
            $ui->success('Database schema updated successfully!');
        }

        if ($dumpSql || $force) {
            return 0;
        }

        $ui->caution(
            [
                'This operation should not be executed in a production environment!',
                '',
                'Use the incremental update to detect changes during development and use',
                'the SQL DDL provided to manually update your database in production.',
            ]
        );

        $ui->text(
            [
                sprintf('The Schema-Tool would execute <info>"%s"</info> queries to update the database.', count($sqls)),
                '',
                'Please run the operation by passing one - or both - of the following options:',
                '',
                sprintf('    <info>%s --force</info> to execute the command', $this->getName()),
                sprintf('    <info>%s --dump-sql</info> to dump the SQL statements to the screen', $this->getName()),
            ]
        );

        return 1;
    }
}
