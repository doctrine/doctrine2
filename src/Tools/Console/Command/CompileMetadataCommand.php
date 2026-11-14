<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\ORM\Mapping\Cache\FileClassMetadatasCache;
use Doctrine\ORM\Mapping\ClassMetadataBagCompiler;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

class CompileMetadataCommand extends AbstractEntityManagerCommand
{
    protected function configure(): void
    {
        $this->setName('orm:metadata:compile')
             ->setDescription('Build the class metadata and write it into the configured metadata cache directory')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->setHelp('Builds the complete class metadata and writes it into the directory configured via Configuration::setMetadataCacheDir(), so it can be loaded without consulting the mapping driver again.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = (new SymfonyStyle($input, $output))->getErrorStyle();

        $em       = $this->getEntityManager($input);
        $cacheDir = $em->getConfiguration()->getMetadataCacheDir();

        if ($cacheDir === null) {
            throw new InvalidArgumentException('No metadata cache directory is configured on the given EntityManager.');
        }

        $classMetadatas = (new ClassMetadataBagCompiler($em, new RuntimeReflectionService()))->compile();

        (new FileClassMetadatasCache($cacheDir))->save($classMetadatas);

        $ui->success(sprintf('Compiled metadata for %d classes into "%s".', count($classMetadatas->all()), $cacheDir));

        return 0;
    }
}
