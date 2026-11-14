<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Tests\Models\Company\CompanyFlexContract;
use Doctrine\Tests\Models\Company\CompanyManager;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

class DDC3719Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('company');

        parent::setUp();
    }

    #[Group('DDC-3719')]
    public function testCriteriaOnNotOwningSide(): void
    {
        $manager = new CompanyManager();
        $manager->setName('Gandalf');
        $manager->setSalary(666);
        $manager->setTitle('Boss');
        $manager->setDepartment('Marketing');
        $this->_em->persist($manager);

        $contractA = new CompanyFlexContract();
        $contractA->markCompleted();
        $contractA->addManager($manager);
        $this->_em->persist($contractA);

        $contractB = new CompanyFlexContract();
        $contractB->addManager($manager);
        $this->_em->persist($contractB);

        $this->_em->flush();
        $this->_em->refresh($manager);

        $contracts = $manager->managedContracts;
        self::assertCount(2, $contracts);

        $criteria = Criteria::create();
        $criteria->where(Criteria::expr()->eq('completed', true));

        $completedContracts = $contracts->matching($criteria);
        self::assertCount(1, $completedContracts);
    }
}
