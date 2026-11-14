<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command\SchemaTool;

use Doctrine\ORM\Tools\SchemaTool;
use Override;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

/**
 * Command to drop the database schema for a set of classes based on their mappings.
 *
 * @link    www.doctrine-project.org
 */
class DropCommand extends AbstractCommand
{
    #[Override]
    protected function configure(): void
    {
        $this->setName('orm:schema-tool:drop')
             ->setDescription('Drop the complete database schema of EntityManager Storage Connection or generate the corresponding SQL output')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Instead of trying to apply generated SQLs into EntityManager Storage Connection, output them.')
             ->addOption('force', 'f', InputOption::VALUE_NONE, "Don't ask for the deletion of the database, but force the operation to run.")
             ->addOption('full-database', null, InputOption::VALUE_NONE, 'Instead of using the Class Metadata to detect the database table schema, drop ALL assets that the database contains.')
             ->setHelp(<<<'EOT'
Processes the schema and either drop the database schema of EntityManager Storage Connection or generate the SQL output.
Beware that the complete database is dropped by this command, even tables that are not relevant to your metadata model.

<comment>Hint:</comment> If you have a database with tables that should not be managed
by the ORM, you can use a DBAL functionality to filter the tables and sequences down
on a global level:

    $config->setSchemaAssetsFilter(function (string|AbstractAsset $assetName): bool {
        if ($assetName instanceof AbstractAsset) {
            $assetName = $assetName->getName();
        }

        return !str_starts_with($assetName, 'audit_');
    });
EOT);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function executeSchemaCommand(InputInterface $input, OutputInterface $output, SchemaTool $schemaTool, array $metadatas, SymfonyStyle $ui): int
    {
        $isFullDatabaseDrop = $input->getOption('full-database');
        $dumpSql            = $input->getOption('dump-sql') === true;
        $force              = $input->getOption('force') === true;

        if ($dumpSql) {
            if ($isFullDatabaseDrop) {
                $sqls = $schemaTool->getDropDatabaseSQL();
            } else {
                $sqls = $schemaTool->getDropSchemaSQL($metadatas);
            }

            foreach ($sqls as $sql) {
                $ui->writeln(sprintf('%s;', $sql));
            }

            return 0;
        }

        $notificationUi = $ui->getErrorStyle();

        if ($force) {
            $notificationUi->text('Dropping database schema...');
            $notificationUi->newLine();

            if ($isFullDatabaseDrop) {
                $schemaTool->dropDatabase();
            } else {
                $schemaTool->dropSchema($metadatas);
            }

            $notificationUi->success('Database schema dropped successfully!');

            return 0;
        }

        $notificationUi->caution('This operation should not be executed in a production environment!');

        if ($isFullDatabaseDrop) {
            $sqls = $schemaTool->getDropDatabaseSQL();
        } else {
            $sqls = $schemaTool->getDropSchemaSQL($metadatas);
        }

        if (empty($sqls)) {
            $notificationUi->success('Nothing to drop. The database is empty!');

            return 0;
        }

        $notificationUi->text(
            [
                sprintf('The Schema-Tool would execute <info>"%s"</info> queries to update the database.', count($sqls)),
                '',
                'Please run the operation by passing one - or both - of the following options:',
                '',
                sprintf('    <info>%s --force</info> to execute the command', $this->getName()),
                sprintf('    <info>%s --dump-sql</info> to dump the SQL statements to the screen', $this->getName()),
            ],
        );

        return 1;
    }
}
