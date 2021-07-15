<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command\ClearCache;

use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\XcacheCache;
use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function get_class;
use function sprintf;

/**
 * Command to clear the metadata cache of the various cache drivers.
 *
 * @link    www.doctrine-project.org
 */
class MetadataCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:clear-cache:metadata')
             ->setDescription('Clear all metadata cache of the various cache drivers')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('flush', null, InputOption::VALUE_NONE, 'If defined, cache entries will be flushed instead of deleted/invalidated.')
             ->setHelp(<<<'EOT'
The <info>%command.name%</info> command is meant to clear the metadata cache of associated Entity Manager.
EOT
             );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);

        $em          = $this->getEntityManager($input);
        $cacheDriver = $em->getConfiguration()->getMetadataCache();

        if (! $cacheDriver) {
            throw new InvalidArgumentException('No Metadata cache driver is configured on given EntityManager.');
        }

        $ui->comment('Clearing <info>all</info> Metadata cache entries');

        $result  = $cacheDriver->clear();
        $message = $result ? 'Successfully deleted cache entries.' : 'No cache entries were deleted.';

        $ui->success($message);

        return 0;
    }
}
