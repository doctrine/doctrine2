<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\ORM\Mapping\Driver\DatabaseDriver;
use Doctrine\ORM\Tools\Console\MetadataFilter;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ORM\Tools\EntityGenerator;
use Doctrine\ORM\Tools\Export\ClassMetadataExporter;
use Doctrine\ORM\Tools\Export\Driver\AbstractExporter;
use Doctrine\ORM\Tools\Export\Driver\AnnotationExporter;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_exists;
use function is_dir;
use function is_writable;
use function mkdir;
use function realpath;
use function sprintf;
use function strtolower;

/**
 * Command to convert your mapping information between the various formats.
 *
 * @link    www.doctrine-project.org
 */
class ConvertMappingCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:convert-mapping')
             ->setAliases(['orm:convert:mapping'])
             ->setDescription('Convert mapping information between supported formats')
             ->addArgument('to-type', InputArgument::REQUIRED, 'The mapping type to be converted.')
             ->addArgument('dest-path', InputArgument::REQUIRED, 'The path to generate your entities classes.')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('filter', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A string pattern used to match entities that should be processed.')
             ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force to overwrite existing mapping files.')
             ->addOption('from-database', null, null, 'Whether or not to convert mapping information from existing database.')
             ->addOption('extend', null, InputOption::VALUE_OPTIONAL, 'Defines a base class to be extended by generated entity classes.')
             ->addOption('num-spaces', null, InputOption::VALUE_OPTIONAL, 'Defines the number of indentation spaces', 4)
             ->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Defines a namespace for the generated entity classes, if converted from database.')
             ->setHelp(<<<EOT
Convert mapping information between supported formats.

This is an execute <info>one-time</info> command. It should not be necessary for
you to call this method multiple times, especially when using the <comment>--from-database</comment>
flag.

Converting an existing database schema into mapping files only solves about 70-80%
of the necessary mapping information. Additionally the detection from an existing
database cannot detect inverse associations, inheritance types,
entities with foreign keys as primary keys and many of the
semantical operations on associations such as cascade.

<comment>Hint:</comment> There is no need to convert YAML or XML mapping files to annotations
every time you make changes. All mapping drivers are first class citizens
in Doctrine 2 and can be used as runtime mapping for the ORM.

<comment>Hint:</comment> If you have a database with tables that should not be managed
by the ORM, you can use a DBAL functionality to filter the tables and sequences down
on a global level:

    \$config->setFilterSchemaAssetsExpression(\$regexp);
EOT
             );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);

        $em = $this->getEntityManager($input);

        if ($input->getOption('from-database') === true) {
            $databaseDriver = new DatabaseDriver(
                $em->getConnection()->getSchemaManager()
            );

            $em->getConfiguration()->setMetadataDriverImpl(
                $databaseDriver
            );

            $namespace = $input->getOption('namespace');
            if ($namespace !== null) {
                $databaseDriver->setNamespace($namespace);
            }
        }

        $cmf = new DisconnectedClassMetadataFactory();
        $cmf->setEntityManager($em);
        $metadata = $cmf->getAllMetadata();
        $metadata = MetadataFilter::filter($metadata, $input->getOption('filter'));

        // Process destination directory
        $destPath = $input->getArgument('dest-path');
        if (! is_dir($destPath)) {
            mkdir($destPath, 0775, true);
        }

        $destPath = realpath($destPath);

        if (! file_exists($destPath)) {
            throw new InvalidArgumentException(
                sprintf("Mapping destination directory '<info>%s</info>' does not exist.", $input->getArgument('dest-path'))
            );
        }

        if (! is_writable($destPath)) {
            throw new InvalidArgumentException(
                sprintf("Mapping destination directory '<info>%s</info>' does not have write permissions.", $destPath)
            );
        }

        $toType = strtolower($input->getArgument('to-type'));

        $exporter = $this->getExporter($toType, $destPath);
        $exporter->setOverwriteExistingFiles($input->getOption('force'));

        if ($exporter instanceof AnnotationExporter) {
            $entityGenerator = new EntityGenerator();
            $exporter->setEntityGenerator($entityGenerator);

            $entityGenerator->setNumSpaces((int) $input->getOption('num-spaces'));

            $extend = $input->getOption('extend');
            if ($extend !== null) {
                $entityGenerator->setClassToExtend($extend);
            }
        }

        if (empty($metadata)) {
            $ui->success('No Metadata Classes to process.');

            return;
        }

        foreach ($metadata as $class) {
            $ui->text(sprintf('Processing entity "<info>%s</info>"', $class->name));
        }

        $exporter->setMetadata($metadata);
        $exporter->export();

        $ui->newLine();
        $ui->text(
            sprintf(
                'Exporting "<info>%s</info>" mapping information to "<info>%s</info>"',
                $toType,
                $destPath
            )
        );

        return 0;
    }

    /**
     * @param string $toType
     * @param string $destPath
     *
     * @return AbstractExporter
     */
    protected function getExporter($toType, $destPath)
    {
        $cme = new ClassMetadataExporter();

        return $cme->getExporter($toType, $destPath);
    }
}
