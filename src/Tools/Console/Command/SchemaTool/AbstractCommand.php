<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command\SchemaTool;

use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use Doctrine\ORM\Tools\SchemaTool;
use Override;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base class for CreateCommand, DropCommand and UpdateCommand.
 *
 * @link    www.doctrine-project.org
 */
abstract class AbstractCommand extends AbstractEntityManagerCommand
{
    /** @param mixed[] $metadatas */
    abstract protected function executeSchemaCommand(InputInterface $input, OutputInterface $output, SchemaTool $schemaTool, array $metadatas, SymfonyStyle $ui): int;

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = new SymfonyStyle($input, $output);

        $em = $this->getEntityManager($input);

        $metadatas = $em->getMetadataFactory()->getAllMetadata();

        if (empty($metadatas)) {
            $ui->getErrorStyle()->success('No Metadata Classes to process.');

            return 0;
        }

        return $this->executeSchemaCommand($input, $output, new SchemaTool($em), $metadatas, $ui);
    }
}
