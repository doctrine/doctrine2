<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command\ClearCache;

use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use InvalidArgumentException;
use LogicException;
use Override;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to clear the query cache of the various cache drivers.
 *
 * @link    www.doctrine-project.org
 */
class QueryCommand extends AbstractEntityManagerCommand
{
    #[Override]
    protected function configure(): void
    {
        $this->setName('orm:clear-cache:query')
             ->setDescription('Clear all query cache of the various cache drivers')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->setHelp('The <info>%command.name%</info> command is meant to clear the query cache of associated Entity Manager.');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = (new SymfonyStyle($input, $output))->getErrorStyle();

        $em    = $this->getEntityManager($input);
        $cache = $em->getConfiguration()->getQueryCache();

        if (! $cache) {
            throw new InvalidArgumentException('No Query cache driver is configured on given EntityManager.');
        }

        if ($cache instanceof ApcuAdapter) {
            throw new LogicException('Cannot clear APCu Cache from Console, it\'s shared in the Webserver memory and not accessible from the CLI.');
        }

        $ui->comment('Clearing <info>all</info> Query cache entries');

        $message = $cache->clear() ? 'Successfully deleted cache entries.' : 'No cache entries were deleted.';

        $ui->success($message);

        return 0;
    }
}
