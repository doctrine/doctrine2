<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Command;

use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

abstract class AbstractEntityManagerCommand extends Command
{
    /** @var EntityManagerProvider|null */
    private $entityManagerProvider;

    public function __construct(?EntityManagerProvider $entityManagerProvider = null)
    {
        parent::__construct();

        $this->entityManagerProvider = $entityManagerProvider;
    }

    final protected function getEntityManager(InputInterface $input): EntityManagerInterface
    {
        // This is a backwards compatibility required check for commands extending Doctrine ORM commands
        if (! $input->hasOption('em') || $this->entityManagerProvider === null) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/issues/8327',
                'Not passing EntityManagerProvider as a dependency to command class "%s" is deprecated',
                $this->getName()
            );

            return $this->getHelper('em')->getEntityManager();
        }

        return $input->getOption('em') === null
            ? $this->entityManagerProvider->getDefaultManager()
            : $this->entityManagerProvider->getManager($input->getOption('em'));
    }
}
