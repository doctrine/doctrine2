<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command\ClearCache;

use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use InvalidArgumentException;
use Override;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to clear the result cache of the various cache drivers.
 *
 * @link    www.doctrine-project.org
 */
class ResultCommand extends AbstractEntityManagerCommand
{
    #[Override]
    protected function configure(): void
    {
        $this->setName('orm:clear-cache:result')
             ->setDescription('Clear all result cache of the various cache drivers')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('flush', null, InputOption::VALUE_NONE, 'If defined, cache entries will be flushed instead of deleted/invalidated.')
             ->setHelp(<<<'EOT'
The <info>%command.name%</info> command is meant to clear the result cache of associated Entity Manager.
It is possible to invalidate all cache entries at once - called delete -, or flushes the cache provider
instance completely.

The execution type differ on how you execute the command.
If you want to invalidate the entries (and not delete from cache instance), this command would do the work:

<info>%command.name%</info>

Alternatively, if you want to flush the cache provider using this command:

<info>%command.name% --flush</info>

Finally, be aware that if <info>--flush</info> option is passed, not all cache providers are able to flush entries,
because of a limitation of its execution nature.
EOT);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = (new SymfonyStyle($input, $output))->getErrorStyle();

        $em    = $this->getEntityManager($input);
        $cache = $em->getConfiguration()->getResultCache();

        if (! $cache) {
            throw new InvalidArgumentException('No Result cache driver is configured on given EntityManager.');
        }

        $ui->comment('Clearing <info>all</info> Result cache entries');

        $message = $cache->clear() ? 'Successfully deleted cache entries.' : 'No cache entries were deleted.';

        $ui->success($message);

        return 0;
    }
}
