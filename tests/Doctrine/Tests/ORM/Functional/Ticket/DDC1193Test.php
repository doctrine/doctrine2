<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;
use function get_class;

class DDC1193Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1193Company::class),
                $this->_em->getClassMetadata(DDC1193Person::class),
                $this->_em->getClassMetadata(DDC1193Account::class),
            ]
        );
    }

    /**
     * @group DDC-1193
     */
    public function testIssue(): void
    {
        $company = new DDC1193Company();
        $person  = new DDC1193Person();
        $account = new DDC1193Account();

        $person->account = $account;
        $person->company = $company;

        $company->member = $person;

        $this->_em->persist($company);

        $this->_em->flush();

        $companyId = $company->id;
        $accountId = $account->id;
        $this->_em->clear();

        $company = $this->_em->find(get_class($company), $companyId);

        $this->assertTrue($this->_em->getUnitOfWork()->isInIdentityMap($company), 'Company is in identity map.');
        $this->assertFalse($company->member->__isInitialized__, 'Pre-Condition');
        $this->assertTrue($this->_em->getUnitOfWork()->isInIdentityMap($company->member), 'Member is in identity map.');

        $this->_em->remove($company);
        $this->_em->flush();

        $this->assertEquals(count($this->_em->getRepository(get_class($account))->findAll()), 0);
    }
}

/** @Entity */
class DDC1193Company
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC1193Person
     * @OneToOne(targetEntity="DDC1193Person", cascade={"persist", "remove"})
     */
    public $member;
}

/** @Entity */
class DDC1193Person
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC1193Account
     * @OneToOne(targetEntity="DDC1193Account", cascade={"persist", "remove"})
     */
    public $account;
}

/** @Entity */
class DDC1193Account
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}
