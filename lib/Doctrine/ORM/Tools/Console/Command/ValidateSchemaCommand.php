<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Command to validate that the current mapping is valid.
 *
 * @link        www.doctrine-project.com
 */
class ValidateSchemaCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:validate-schema')
             ->setDescription('Validate the mapping files')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('skip-mapping', null, InputOption::VALUE_NONE, 'Skip the mapping validation check')
             ->addOption('skip-sync', null, InputOption::VALUE_NONE, 'Skip checking if the mapping is in sync with the database')
             ->setHelp('Validate that the mapping files are correct and in sync with the database.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);

        $em        = $this->getEntityManager($input);
        $validator = new SchemaValidator($em);
        $exit      = 0;

        $ui->section('Mapping');

        if ($input->getOption('skip-mapping')) {
            $ui->text('<comment>[SKIPPED] The mapping was not checked.</comment>');
        } else {
            $errors = $validator->validateMapping();
            if ($errors) {
                foreach ($errors as $className => $errorMessages) {
                    $ui->text(
                        sprintf(
                            '<error>[FAIL]</error> The entity-class <comment>%s</comment> mapping is invalid:',
                            $className
                        )
                    );

                    $ui->listing($errorMessages);
                    $ui->newLine();
                }

                ++$exit;
            } else {
                $ui->success('The mapping files are correct.');
            }
        }

        $ui->section('Database');

        if ($input->getOption('skip-sync')) {
            $ui->text('<comment>[SKIPPED] The database was not checked for synchronicity.</comment>');
        } elseif (! $validator->schemaInSyncWithMetadata()) {
            $ui->error('The database schema is not in sync with the current mapping file.');
            $exit += 2;
        } else {
            $ui->success('The database schema is in sync with the mapping files.');
        }

        return $exit;
    }
}
