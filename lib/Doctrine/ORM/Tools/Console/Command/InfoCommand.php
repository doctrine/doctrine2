<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\ORM\Mapping\MappingException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

/**
 * Show information about mapped entities.
 *
 * @link    www.doctrine-project.org
 */
class InfoCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:info')
             ->setDescription('Show basic information about all mapped entities')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->setHelp(<<<'EOT'
The <info>%command.name%</info> shows basic information about which
entities exist and possibly if their mapping information contains errors or
not.
EOT
             );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);

        $entityManager = $this->getEntityManager($input);

        $entityClassNames = $entityManager->getConfiguration()
                                          ->getMetadataDriverImpl()
                                          ->getAllClassNames();

        if (! $entityClassNames) {
            $ui->caution(
                [
                    'You do not have any mapped Doctrine ORM entities according to the current configuration.',
                    'If you have entities or mapping files you should check your mapping configuration for errors.',
                ]
            );

            return 1;
        }

        $ui->text(sprintf('Found <info>%d</info> mapped entities:', count($entityClassNames)));
        $ui->newLine();

        $failure = false;

        foreach ($entityClassNames as $entityClassName) {
            try {
                $entityManager->getClassMetadata($entityClassName);
                $ui->text(sprintf('<info>[OK]</info>   %s', $entityClassName));
            } catch (MappingException $e) {
                $ui->text(
                    [
                        sprintf('<error>[FAIL]</error> %s', $entityClassName),
                        sprintf('<comment>%s</comment>', $e->getMessage()),
                        '',
                    ]
                );

                $failure = true;
            }
        }

        return $failure ? 1 : 0;
    }
}
