<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\ORM\Tools\Console\MetadataFilter;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ORM\Tools\EntityGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_exists;
use function is_writable;
use function realpath;
use function sprintf;

/**
 * Command to generate entity classes and method stubs from your mapping information.
 *
 * @deprecated 2.7 This class is being removed from the ORM and won't have any replacement
 *
 * @link    www.doctrine-project.org
 */
class GenerateEntitiesCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:generate-entities')
             ->setAliases(['orm:generate:entities'])
             ->setDescription('Generate entity classes and method stubs from your mapping information')
             ->addArgument('dest-path', InputArgument::REQUIRED, 'The path to generate your entity classes.')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('filter', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A string pattern used to match entities that should be processed.')
             ->addOption('generate-annotations', null, InputOption::VALUE_OPTIONAL, 'Flag to define if generator should generate annotation metadata on entities.', false)
             ->addOption('generate-methods', null, InputOption::VALUE_OPTIONAL, 'Flag to define if generator should generate stub methods on entities.', true)
             ->addOption('regenerate-entities', null, InputOption::VALUE_OPTIONAL, 'Flag to define if generator should regenerate entity if it exists.', false)
             ->addOption('update-entities', null, InputOption::VALUE_OPTIONAL, 'Flag to define if generator should only update entity if it exists.', true)
             ->addOption('extend', null, InputOption::VALUE_REQUIRED, 'Defines a base class to be extended by generated entity classes.')
             ->addOption('num-spaces', null, InputOption::VALUE_REQUIRED, 'Defines the number of indentation spaces', 4)
             ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Flag to define if generator should avoid backuping existing entity file if it exists.')
             ->setHelp(<<<EOT
Generate entity classes and method stubs from your mapping information.

If you use the <comment>--update-entities</comment> or <comment>--regenerate-entities</comment> flags your existing
code gets overwritten. The EntityGenerator will only append new code to your
file and will not delete the old code. However this approach may still be prone
to error and we suggest you use code repositories such as GIT or SVN to make
backups of your code.

It makes sense to generate the entity code if you are using entities as Data
Access Objects only and don't put much additional logic on them. If you are
however putting much more logic on the entities you should refrain from using
the entity-generator and code your entities manually.

<error>Important:</error> Even if you specified Inheritance options in your
XML or YAML Mapping files the generator cannot generate the base and
child classes for you correctly, because it doesn't know which
class is supposed to extend which. You have to adjust the entity
code manually for inheritance to work!
EOT
             );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);
        $ui->warning('Command ' . $this->getName() . ' is deprecated and will be removed in Doctrine ORM 3.0.');

        $em = $this->getEntityManager($input);

        $cmf = new DisconnectedClassMetadataFactory();
        $cmf->setEntityManager($em);
        $metadatas = $cmf->getAllMetadata();
        $metadatas = MetadataFilter::filter($metadatas, $input->getOption('filter'));

        // Process destination directory
        $destPath = realpath($input->getArgument('dest-path'));

        if (! file_exists($destPath)) {
            throw new InvalidArgumentException(
                sprintf("Entities destination directory '<info>%s</info>' does not exist.", $input->getArgument('dest-path'))
            );
        }

        if (! is_writable($destPath)) {
            throw new InvalidArgumentException(
                sprintf("Entities destination directory '<info>%s</info>' does not have write permissions.", $destPath)
            );
        }

        if (empty($metadatas)) {
            $ui->success('No Metadata Classes to process.');

            return 0;
        }

        $entityGenerator = new EntityGenerator();

        $entityGenerator->setGenerateAnnotations($input->getOption('generate-annotations'));
        $entityGenerator->setGenerateStubMethods($input->getOption('generate-methods'));
        $entityGenerator->setRegenerateEntityIfExists($input->getOption('regenerate-entities'));
        $entityGenerator->setUpdateEntityIfExists($input->getOption('update-entities'));
        $entityGenerator->setNumSpaces((int) $input->getOption('num-spaces'));
        $entityGenerator->setBackupExisting(! $input->getOption('no-backup'));

        $extend = $input->getOption('extend');
        if ($extend !== null) {
            $entityGenerator->setClassToExtend($extend);
        }

        foreach ($metadatas as $metadata) {
            $ui->text(sprintf('Processing entity "<info>%s</info>"', $metadata->name));
        }

        // Generating Entities
        $entityGenerator->generate($metadatas, $destPath);

        // Outputting information message
        $ui->newLine();
        $ui->success(sprintf('Entity classes generated to "%s"', $destPath));

        return 0;
    }
}
