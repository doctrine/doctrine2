<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command\ClearCache;

use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\Region\DefaultRegion;
use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function get_class;
use function gettype;
use function is_object;
use function sprintf;

/**
 * Command to clear a collection cache region.
 */
class CollectionRegionCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:clear-cache:region:collection')
             ->setDescription('Clear a second-level cache collection region')
             ->addArgument('owner-class', InputArgument::OPTIONAL, 'The owner entity name.')
             ->addArgument('association', InputArgument::OPTIONAL, 'The association collection name.')
             ->addArgument('owner-id', InputArgument::OPTIONAL, 'The owner identifier.')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('all', null, InputOption::VALUE_NONE, 'If defined, all entity regions will be deleted/invalidated.')
             ->addOption('flush', null, InputOption::VALUE_NONE, 'If defined, all cache entries will be flushed.')
             ->setHelp(<<<'EOT'
The <info>%command.name%</info> command is meant to clear a second-level cache collection regions for an associated Entity Manager.
It is possible to delete/invalidate all collection region, a specific collection region or flushes the cache provider.

The execution type differ on how you execute the command.
If you want to invalidate all entries for an collection region this command would do the work:

<info>%command.name% 'Entities\MyEntity' 'collectionName'</info>

To invalidate a specific entry you should use :

<info>%command.name% 'Entities\MyEntity' 'collectionName' 1</info>

If you want to invalidate all entries for the all collection regions:

<info>%command.name% --all</info>

Alternatively, if you want to flush the configured cache provider for an collection region use this command:

<info>%command.name% 'Entities\MyEntity' 'collectionName' --flush</info>

Finally, be aware that if <info>--flush</info> option is passed,
not all cache providers are able to flush entries, because of a limitation of its execution nature.
EOT
             );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);

        $em         = $this->getEntityManager($input);
        $ownerClass = $input->getArgument('owner-class');
        $assoc      = $input->getArgument('association');
        $ownerId    = $input->getArgument('owner-id');
        $cache      = $em->getCache();

        if (! $cache instanceof Cache) {
            throw new InvalidArgumentException('No second-level cache is configured on the given EntityManager.');
        }

        if (( ! $ownerClass || ! $assoc) && ! $input->getOption('all')) {
            throw new InvalidArgumentException('Missing arguments "--owner-class" "--association"');
        }

        if ($input->getOption('flush')) {
            $collectionRegion = $cache->getCollectionCacheRegion($ownerClass, $assoc);

            if (! $collectionRegion instanceof DefaultRegion) {
                throw new InvalidArgumentException(sprintf(
                    'The option "--flush" expects a "Doctrine\ORM\Cache\Region\DefaultRegion", but got "%s".',
                    is_object($collectionRegion) ? get_class($collectionRegion) : gettype($collectionRegion)
                ));
            }

            $collectionRegion->getCache()->flushAll();

            $ui->comment(
                sprintf(
                    'Flushing cache provider configured for <info>"%s#%s"</info>',
                    $ownerClass,
                    $assoc
                )
            );

            return 0;
        }

        if ($input->getOption('all')) {
            $ui->comment('Clearing <info>all</info> second-level cache collection regions');

            $cache->evictEntityRegions();

            return 0;
        }

        if ($ownerId) {
            $ui->comment(
                sprintf(
                    'Clearing second-level cache entry for collection <info>"%s#%s"</info> owner entity identified by <info>"%s"</info>',
                    $ownerClass,
                    $assoc,
                    $ownerId
                )
            );
            $cache->evictCollection($ownerClass, $assoc, $ownerId);

            return 0;
        }

        $ui->comment(sprintf('Clearing second-level cache for collection <info>"%s#%s"</info>', $ownerClass, $assoc));
        $cache->evictCollectionRegion($ownerClass, $assoc);

        return 0;
    }
}
