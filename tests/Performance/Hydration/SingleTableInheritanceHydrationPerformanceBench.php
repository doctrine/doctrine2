<?php

declare(strict_types=1);

namespace Doctrine\Performance\Hydration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Performance\EntityManagerFactory;
use Doctrine\Tests\Models\Company\CompanyAuction;
use Doctrine\Tests\Models\Company\CompanyCar;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\Company\CompanyEmployee;
use Doctrine\Tests\Models\Company\CompanyEvent;
use Doctrine\Tests\Models\Company\CompanyFixContract;
use Doctrine\Tests\Models\Company\CompanyFlexContract;
use Doctrine\Tests\Models\Company\CompanyFlexUltraContract;
use Doctrine\Tests\Models\Company\CompanyManager;
use Doctrine\Tests\Models\Company\CompanyOrganization;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\Models\Company\CompanyRaffle;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;

/** @BeforeMethods({"init"}) */
final class SingleTableInheritanceHydrationPerformanceBench
{
    private EntityManagerInterface|null $entityManager = null;

    private EntityRepository|null $contractsRepository = null;

    private EntityRepository|null $fixContractsRepository = null;

    private EntityRepository|null $flexContractRepository = null;

    private EntityRepository|null $ultraContractRepository = null;

    public function init(): void
    {
        $entityManager = EntityManagerFactory::getEntityManager([
            CompanyPerson::class,
            CompanyEmployee::class,
            CompanyManager::class,
            CompanyOrganization::class,
            CompanyEvent::class,
            CompanyAuction::class,
            CompanyRaffle::class,
            CompanyCar::class,
            CompanyContract::class,
        ]);

        $this->entityManager           = $entityManager;
        $this->contractsRepository     = $entityManager->getRepository(CompanyContract::class);
        $this->fixContractsRepository  = $entityManager->getRepository(CompanyFixContract::class);
        $this->flexContractRepository  = $entityManager->getRepository(CompanyFlexContract::class);
        $this->ultraContractRepository = $entityManager->getRepository(CompanyFlexUltraContract::class);

        $person = new CompanyEmployee();
        $person->setName('Poor Sales Guy');
        $person->setDepartment('Sales');
        $person->setSalary(100);
        $entityManager->persist($person);

        for ($i = 0; $i < 33; $i++) {
            $fixContract   = new CompanyFixContract();
            $flexContract  = new CompanyFlexContract();
            $ultraContract = new CompanyFlexUltraContract();

            $fixContract->setFixPrice(1000);
            $fixContract->setSalesPerson($person);
            $fixContract->markCompleted();

            $flexContract->setSalesPerson($person);
            $flexContract->setHoursWorked(100);
            $flexContract->setPricePerHour(100);
            $flexContract->markCompleted();

            $ultraContract->setSalesPerson($person);
            $ultraContract->setHoursWorked(150);
            $ultraContract->setPricePerHour(150);
            $ultraContract->setMaxPrice(7000);

            $entityManager->persist($fixContract);
            $entityManager->persist($flexContract);
            $entityManager->persist($ultraContract);
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    public function benchHydrateFixContracts(): void
    {
        $this->fixContractsRepository->findAll();

        $this->entityManager->clear();
    }

    public function benchHydrateFlexContracts(): void
    {
        $this->flexContractRepository->findAll();

        $this->entityManager->clear();
    }

    public function benchHydrateUltraContracts(): void
    {
        $this->ultraContractRepository->findAll();

        $this->entityManager->clear();
    }

    public function benchHydrateAllContracts(): void
    {
        $this->contractsRepository->findAll();

        $this->entityManager->clear();
    }
}
