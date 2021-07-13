<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Helper\Helper;

/**
 * Doctrine CLI Connection Helper.
 *
 * @link    www.doctrine-project.org
 */
class EntityManagerHelper extends Helper
{
    /**
     * Doctrine ORM EntityManagerInterface.
     *
     * @var EntityManagerInterface
     */
    protected $_em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->_em = $em;
    }

    /**
     * Retrieves Doctrine ORM EntityManager.
     *
     * @return EntityManagerInterface
     */
    public function getEntityManager()
    {
        return $this->_em;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'entityManager';
    }
}
