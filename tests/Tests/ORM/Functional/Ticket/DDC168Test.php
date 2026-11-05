<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\Mocks\OverridableMetadataFactory;
use Doctrine\Tests\Models\Company\CompanyEmployee;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function ksort;

class DDC168Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('company');

        parent::setUp();

        $metadata = clone $this->_em->getClassMetadata(CompanyEmployee::class);
        ksort($metadata->propertyAccessors);

        $this->_em = $this->getEntityManager(metadataFactoryClassName: OverridableMetadataFactory::class);

        $metadataFactory = $this->_em->getMetadataFactory();
        $metadataFactory->setMetadataFor(CompanyEmployee::class, $metadata);
    }

    #[Group('DDC-168')]
    public function testJoinedSubclassPersisterRequiresSpecificOrderOfMetadataReflFieldsArray(): void
    {
        $spouse = new CompanyEmployee();
        $spouse->setName('Blub');
        $spouse->setDepartment('Accounting');
        $spouse->setSalary(500);

        $employee = new CompanyEmployee();
        $employee->setName('Foo');
        $employee->setDepartment('bar');
        $employee->setSalary(1000);
        $employee->setSpouse($spouse);

        $this->_em->persist($spouse);
        $this->_em->persist($employee);

        $this->_em->flush();
        $this->_em->clear();

        $q = $this->_em->createQuery('SELECT e FROM Doctrine\Tests\Models\Company\CompanyEmployee e WHERE e.name = ?1');
        $q->setParameter(1, 'Foo');
        $theEmployee = $q->getSingleResult();

        self::assertEquals('bar', $theEmployee->getDepartment());
        self::assertEquals('Foo', $theEmployee->getName());
        self::assertEquals(1000, $theEmployee->getSalary());
        self::assertInstanceOf(CompanyEmployee::class, $theEmployee);
        self::assertInstanceOf(CompanyEmployee::class, $theEmployee->getSpouse());
    }
}
