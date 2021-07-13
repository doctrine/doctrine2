<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\Common\Util\Debug;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function constant;
use function defined;
use function is_numeric;
use function sprintf;
use function str_replace;
use function strtoupper;

/**
 * Command to execute DQL queries in a given EntityManager.
 *
 * @link    www.doctrine-project.org
 */
class RunDqlCommand extends AbstractEntityManagerCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('orm:run-dql')
             ->setDescription('Executes arbitrary DQL directly from the command line')
             ->addArgument('dql', InputArgument::REQUIRED, 'The DQL to execute.')
             ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager to operate on')
             ->addOption('hydrate', null, InputOption::VALUE_REQUIRED, 'Hydration mode of result set. Should be either: object, array, scalar or single-scalar.', 'object')
             ->addOption('first-result', null, InputOption::VALUE_REQUIRED, 'The first result in the result set.')
             ->addOption('max-result', null, InputOption::VALUE_REQUIRED, 'The maximum number of results in the result set.')
             ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Dumping depth of Entity graph.', 7)
             ->addOption('show-sql', null, InputOption::VALUE_NONE, 'Dump generated SQL instead of executing query')
             ->setHelp('Executes arbitrary DQL directly from the command line.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);

        $em = $this->getEntityManager($input);

        $dql = $input->getArgument('dql');
        if ($dql === null) {
            throw new RuntimeException("Argument 'dql' is required in order to execute this command correctly.");
        }

        $depth = $input->getOption('depth');

        if (! is_numeric($depth)) {
            throw new LogicException("Option 'depth' must contain an integer value");
        }

        $hydrationModeName = $input->getOption('hydrate');
        $hydrationMode     = 'Doctrine\ORM\Query::HYDRATE_' . strtoupper(str_replace('-', '_', $hydrationModeName));

        if (! defined($hydrationMode)) {
            throw new RuntimeException(sprintf(
                "Hydration mode '%s' does not exist. It should be either: object. array, scalar or single-scalar.",
                $hydrationModeName
            ));
        }

        $query = $em->createQuery($dql);

        $firstResult = $input->getOption('first-result');
        if ($firstResult !== null) {
            if (! is_numeric($firstResult)) {
                throw new LogicException("Option 'first-result' must contain an integer value");
            }

            $query->setFirstResult((int) $firstResult);
        }

        $maxResult = $input->getOption('max-result');
        if ($maxResult !== null) {
            if (! is_numeric($maxResult)) {
                throw new LogicException("Option 'max-result' must contain an integer value");
            }

            $query->setMaxResults((int) $maxResult);
        }

        if ($input->getOption('show-sql')) {
            $ui->text($query->getSQL());

            return 0;
        }

        $resultSet = $query->execute([], constant($hydrationMode));

        $ui->text(Debug::dump($resultSet, (int) $input->getOption('depth'), true, false));

        return 0;
    }
}
