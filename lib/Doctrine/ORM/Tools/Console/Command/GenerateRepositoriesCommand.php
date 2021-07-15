<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\ORM\Tools\Console\MetadataFilter;
use Doctrine\ORM\Tools\EntityRepositoryGenerator;
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
 * Command to generate repository classes for mapping information.
 *
 * @deprecated 2.7 This class is being removed from the ORM and won't have any replacement
 *
 * @link    www.doctrine-project.org
 */
class GenerateRepositoriesCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:generate-repositories')
             ->setAliases(['orm:generate:repositories'])
             ->setDescription('Generate repository classes from your mapping information')
             ->addArgument('dest-path', InputArgument::REQUIRED, 'The path to generate your repository classes.')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('filter', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A string pattern used to match entities that should be processed.')
             ->setHelp('Generate repository classes from your mapping information.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);
        $ui->warning('Command ' . $this->getName() . ' is deprecated and will be removed in Doctrine ORM 3.0.');

        $em = $this->getEntityManager($input);

        $metadatas = $em->getMetadataFactory()->getAllMetadata();
        $metadatas = MetadataFilter::filter($metadatas, $input->getOption('filter'));

        $repositoryName = $em->getConfiguration()->getDefaultRepositoryClassName();

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

        $numRepositories = 0;
        $generator       = new EntityRepositoryGenerator();

        $generator->setDefaultRepositoryName($repositoryName);

        foreach ($metadatas as $metadata) {
            if ($metadata->customRepositoryClassName) {
                $ui->text(sprintf('Processing repository "<info>%s</info>"', $metadata->customRepositoryClassName));

                $generator->writeEntityRepositoryClass($metadata->customRepositoryClassName, $destPath);

                ++$numRepositories;
            }
        }

        if ($numRepositories === 0) {
            $ui->text('No Repository classes were found to be processed.');

            return 0;
        }

        // Outputting information message
        $ui->newLine();
        $ui->text(sprintf('Repository classes generated to "<info>%s</info>"', $destPath));

        return 0;
    }
}
