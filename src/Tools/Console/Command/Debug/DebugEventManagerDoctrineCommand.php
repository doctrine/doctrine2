<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command\Debug;

use Override;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_keys;
use function array_values;
use function ksort;
use function method_exists;
use function sprintf;

final class DebugEventManagerDoctrineCommand extends AbstractCommand
{
    #[Override]
    protected function configure(): void
    {
        $this
            ->setName('orm:debug:event-manager')
            ->setDescription('Lists event listeners for an entity manager')
            ->addArgument('event', InputArgument::OPTIONAL, 'The event name to filter by (e.g. postPersist)')
            ->addOption('em', null, InputOption::VALUE_REQUIRED, 'The entity manager to use for this command')
            ->setHelp(<<<'EOT'
The <info>%command.name%</info> command lists all event listeners for the default entity manager:

    <info>php %command.full_name%</info>

You can also specify an entity manager:

    <info>php %command.full_name% --em=default</info>

To show only listeners for a specific event, pass the event name as an argument:

    <info>php %command.full_name% postPersist</info>
EOT);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entityManagerName = $input->getOption('em') ?: $this->getManagerRegistry()->getDefaultManagerName();
        $eventManager      = $this->getEntityManager($entityManagerName)->getEventManager();

        $eventName = $input->getArgument('event');

        if ($eventName === null) {
            $allListeners = $eventManager->getAllListeners();
            if (! $allListeners) {
                $io->info(sprintf('No listeners are configured for the "%s" entity manager.', $entityManagerName));

                return self::SUCCESS;
            }

            ksort($allListeners);
        } else {
            $listeners = $eventManager->getListeners($eventName);
            if (! $listeners) {
                $io->info(sprintf('No listeners are configured for the "%s" event.', $eventName));

                return self::SUCCESS;
            }

            $allListeners = [$eventName => $listeners];
        }

        $io->title(sprintf('Event listeners for <info>%s</info> entity manager', $entityManagerName));

        $rows = [];
        foreach ($allListeners as $event => $listeners) {
            if ($rows) {
                $rows[] = new TableSeparator();
            }

            foreach (array_values($listeners) as $order => $listener) {
                $method = method_exists($listener, '__invoke') ? '__invoke' : $event;
                $rows[] = [$order === 0 ? $event : '', sprintf('#%d', ++$order), sprintf('%s::%s()', $listener::class, $method)];
            }
        }

        $io->table(['Event', 'Order', 'Listener'], $rows);

        return self::SUCCESS;
    }

    #[Override]
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('event')) {
            $entityManagerName = $input->getOption('em') ?: $this->getManagerRegistry()->getDefaultManagerName();
            $eventManager      = $this->getEntityManager($entityManagerName)->getEventManager();

            $suggestions->suggestValues(array_keys($eventManager->getAllListeners()));

            return;
        }

        if ($input->mustSuggestOptionValuesFor('em')) {
            $suggestions->suggestValues(array_keys($this->getManagerRegistry()->getManagerNames()));

            return;
        }
    }
}
