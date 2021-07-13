<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Command to ensure that Doctrine is properly configured for a production environment.
 *
 * @link    www.doctrine-project.org
 */
class EnsureProductionSettingsCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:ensure-production-settings')
             ->setDescription('Verify that Doctrine is properly configured for a production environment')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('complete', null, InputOption::VALUE_NONE, 'Flag to also inspect database connection existence.')
             ->setHelp('Verify that Doctrine is properly configured for a production environment.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);

        $em = $this->getEntityManager($input);

        try {
            $em->getConfiguration()->ensureProductionSettings();

            if ($input->getOption('complete') === true) {
                $em->getConnection()->connect();
            }
        } catch (Throwable $e) {
            $ui->error($e->getMessage());

            return 1;
        }

        $ui->success('Environment is correctly configured for production.');

        return 0;
    }
}
