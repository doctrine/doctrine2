<?php

namespace Doctrine\Tests\ORM\Hydration;

use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use PDO;

class CustomHydratorTest extends HydrationTestCase
{
    public function testCustomHydrator()
    {
        $em = $this->_getTestEntityManager();
        $config = $em->getConfiguration();
        $config->addCustomHydrationMode('CustomHydrator', CustomHydrator::class);

        $hydrator = $em->newHydrator('CustomHydrator');
        $this->assertInstanceOf(CustomHydrator::class, $hydrator);
        $this->assertNull($config->getCustomHydrationMode('does not exist'));
    }
}

class CustomHydrator extends AbstractHydrator
{
    protected function hydrateAllData()
    {
        return $this->_stmt->fetchAllAssociative();
    }
}
