<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Persistence\Mapping\MappingException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Tests\Models\Company\CompanyManager;
use Doctrine\Tests\OrmFunctionalTestCase;
use Doctrine\Tests\TestUtil;

class DDC933Test extends OrmFunctionalTestCase
{
    public function setUp() : void
    {
        $this->useModelSet('company');

        parent::setUp();
    }

    /**
     * @group DDC-933
     */
    public function testLockCTIClass() : void
    {
        if ($this->em->getConnection()->getDatabasePlatform()->getName() === 'sqlite') {
            self::markTestSkipped('It should not run on in-memory databases');
        }

        $manager = new CompanyManager();
        $manager->setName('beberlei');
        $manager->setSalary(1234);
        $manager->setTitle('Vice President of This Test');
        $manager->setDepartment('Foo');

        $this->em->persist($manager);
        $this->em->flush();

        $this->em->beginTransaction();
        $this->em->lock($manager, LockMode::PESSIMISTIC_READ);
        $this->em->rollback();

        // if lock hasn't been released we'd have an exception here
        $this->assertManagerCanBeUpdatedOnAnotherConnection($manager->getId(), 'Master of This Test');
    }

    /**
     *
     *
     * @throws MappingException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    private function assertManagerCanBeUpdatedOnAnotherConnection(int $id, string $newName)
    {
        $em = $this->getEntityManager(TestUtil::getConnection());

        /** @var CompanyManager $manager */
        $manager = $em->find(CompanyManager::class, $id);
        $manager->setName($newName);

        $em->flush();
        $em->clear();

        self::assertSame($newName, $em->find(CompanyManager::class, $id)->getName());
    }
}
