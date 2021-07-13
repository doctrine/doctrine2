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
 * Command to clear a query cache region.
 */
class QueryRegionCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:clear-cache:region:query')
             ->setDescription('Clear a second-level cache query region')
             ->addArgument('region-name', InputArgument::OPTIONAL, 'The query region to clear.')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('all', null, InputOption::VALUE_NONE, 'If defined, all query regions will be deleted/invalidated.')
             ->addOption('flush', null, InputOption::VALUE_NONE, 'If defined, all cache entries will be flushed.')
             ->setHelp(<<<'EOT'
The <info>%command.name%</info> command is meant to clear a second-level cache query region for an associated Entity Manager.
It is possible to delete/invalidate all query region, a specific query region or flushes the cache provider.

The execution type differ on how you execute the command.
If you want to invalidate all entries for the default query region this command would do the work:

<info>%command.name%</info>

To invalidate entries for a specific query region you should use :

<info>%command.name% my_region_name</info>

If you want to invalidate all entries for the all query region:

<info>%command.name% --all</info>

Alternatively, if you want to flush the configured cache provider use this command:

<info>%command.name% my_region_name --flush</info>

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

        $em    = $this->getEntityManager($input);
        $name  = $input->getArgument('region-name');
        $cache = $em->getCache();

        if ($name === null) {
            $name = Cache::DEFAULT_QUERY_REGION_NAME;
        }

        if (! $cache instanceof Cache) {
            throw new InvalidArgumentException('No second-level cache is configured on the given EntityManager.');
        }

        if ($input->getOption('flush')) {
            $queryCache  = $cache->getQueryCache($name);
            $queryRegion = $queryCache->getRegion();

            if (! $queryRegion instanceof DefaultRegion) {
                throw new InvalidArgumentException(sprintf(
                    'The option "--flush" expects a "Doctrine\ORM\Cache\Region\DefaultRegion", but got "%s".',
                    is_object($queryRegion) ? get_class($queryRegion) : gettype($queryRegion)
                ));
            }

            $queryRegion->getCache()->flushAll();

            $ui->comment(
                sprintf(
                    'Flushing cache provider configured for second-level cache query region named <info>"%s"</info>',
                    $name
                )
            );

            return 0;
        }

        if ($input->getOption('all')) {
            $ui->comment('Clearing <info>all</info> second-level cache query regions');

            $cache->evictQueryRegions();

            return 0;
        }

        $ui->comment(sprintf('Clearing second-level cache query region named <info>"%s"</info>', $name));
        $cache->evictQueryRegion($name);

        return 0;
    }
}
