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
 * Command to clear a entity cache region.
 */
class EntityRegionCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:clear-cache:region:entity')
             ->setDescription('Clear a second-level cache entity region')
             ->addArgument('entity-class', InputArgument::OPTIONAL, 'The entity name.')
             ->addArgument('entity-id', InputArgument::OPTIONAL, 'The entity identifier.')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('all', null, InputOption::VALUE_NONE, 'If defined, all entity regions will be deleted/invalidated.')
             ->addOption('flush', null, InputOption::VALUE_NONE, 'If defined, all cache entries will be flushed.')
             ->setHelp(<<<'EOT'
The <info>%command.name%</info> command is meant to clear a second-level cache entity region for an associated Entity Manager.
It is possible to delete/invalidate all entity region, a specific entity region or flushes the cache provider.

The execution type differ on how you execute the command.
If you want to invalidate all entries for an entity region this command would do the work:

<info>%command.name% 'Entities\MyEntity'</info>

To invalidate a specific entry you should use :

<info>%command.name% 'Entities\MyEntity' 1</info>

If you want to invalidate all entries for the all entity regions:

<info>%command.name% --all</info>

Alternatively, if you want to flush the configured cache provider for an entity region use this command:

<info>%command.name% 'Entities\MyEntity' --flush</info>

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

        $em          = $this->getEntityManager($input);
        $entityClass = $input->getArgument('entity-class');
        $entityId    = $input->getArgument('entity-id');
        $cache       = $em->getCache();

        if (! $cache instanceof Cache) {
            throw new InvalidArgumentException('No second-level cache is configured on the given EntityManager.');
        }

        if (! $entityClass && ! $input->getOption('all')) {
            throw new InvalidArgumentException('Invalid argument "--entity-class"');
        }

        if ($input->getOption('flush')) {
            $entityRegion = $cache->getEntityCacheRegion($entityClass);

            if (! $entityRegion instanceof DefaultRegion) {
                throw new InvalidArgumentException(sprintf(
                    'The option "--flush" expects a "Doctrine\ORM\Cache\Region\DefaultRegion", but got "%s".',
                    is_object($entityRegion) ? get_class($entityRegion) : gettype($entityRegion)
                ));
            }

            $entityRegion->getCache()->flushAll();

            $ui->comment(sprintf('Flushing cache provider configured for entity named <info>"%s"</info>', $entityClass));

            return 0;
        }

        if ($input->getOption('all')) {
            $ui->comment('Clearing <info>all</info> second-level cache entity regions');

            $cache->evictEntityRegions();

            return 0;
        }

        if ($entityId) {
            $ui->comment(
                sprintf(
                    'Clearing second-level cache entry for entity <info>"%s"</info> identified by <info>"%s"</info>',
                    $entityClass,
                    $entityId
                )
            );
            $cache->evictEntity($entityClass, $entityId);

            return 0;
        }

        $ui->comment(sprintf('Clearing second-level cache for entity <info>"%s"</info>', $entityClass));
        $cache->evictEntityRegion($entityClass);

        return 0;
    }
}
