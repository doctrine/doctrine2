<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\EntityManagerProvider;

use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Symfony\Component\Console\Helper\HelperSet;

use function assert;

final class HelperSetManagerProvider implements EntityManagerProvider
{
    /** @var HelperSet */
    private $helperSet;

    public function __construct(HelperSet $helperSet)
    {
        $this->helperSet = $helperSet;

        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/issues/8327',
            'Use of a HelperSet and the HelperSetManagerProvider is deprecated and will be removed in ORM 3.0'
        );
    }

    public function getManager(string $name): EntityManagerInterface
    {
        if ($name !== 'default') {
            throw UnknownManagerException::unknownManager($name, ['default']);
        }

        return $this->getDefaultManager();
    }

    public function getDefaultManager(): EntityManagerInterface
    {
        $helper = $this->helperSet->get('entityManager');

        assert($helper instanceof EntityManagerHelper);

        return $helper->getEntityManager();
    }
}
