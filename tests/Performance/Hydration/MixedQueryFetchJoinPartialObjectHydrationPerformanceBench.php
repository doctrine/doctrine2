<?php

declare(strict_types=1);

namespace Doctrine\Performance\Hydration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\ObjectHydrator;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Performance\EntityManagerFactory;
use Doctrine\Tests\Mocks\ArrayResultFactory;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;

/** @BeforeMethods({"init"}) */
final class MixedQueryFetchJoinPartialObjectHydrationPerformanceBench
{
    private EntityManagerInterface|null $entityManager = null;

    private ResultSetMapping|null $rsm = null;

    /** @var list<array<string, mixed>> */
    private array $resultSet = [];

    public function init(): void
    {
        $resultSet = [
            [
                'u__id'          => '1',
                'u__status'      => 'developer',
                'u__username'    => 'romanb',
                'u__name'        => 'Roman',
                'sclr0'          => 'ROMANB',
                'p__phonenumber' => '42',
                'a__id'       => '1',
            ],
            [
                'u__id'          => '1',
                'u__status'      => 'developer',
                'u__username'    => 'romanb',
                'u__name'        => 'Roman',
                'sclr0'          => 'ROMANB',
                'p__phonenumber' => '43',
                'a__id'       => '1',
            ],
            [
                'u__id'          => '2',
                'u__status'      => 'developer',
                'u__username'    => 'romanb',
                'u__name'        => 'Roman',
                'sclr0'          => 'JWAGE',
                'p__phonenumber' => '91',
                'a__id'       => '1',
            ],
        ];

        for ($i = 4; $i < 2000; ++$i) {
            $resultSet[] = [
                'u__id'          => $i,
                'u__status'      => 'developer',
                'u__username'    => 'jwage',
                'u__name'        => 'Jonathan',
                'sclr0'          => 'JWAGE' . $i,
                'p__phonenumber' => '91',
                'a__id'       => '1',
            ];
        }

        $this->resultSet     = $resultSet;
        $this->entityManager = EntityManagerFactory::getEntityManager([]);
        $this->rsm           = new ResultSetMapping();

        $this->rsm->addEntityResult(CmsUser::class, 'u');
        $this->rsm->addJoinedEntityResult(CmsPhonenumber::class, 'p', 'u', 'phonenumbers');
        $this->rsm->addFieldResult('u', 'u__id', 'id');
        $this->rsm->addFieldResult('u', 'u__status', 'status');
        $this->rsm->addFieldResult('u', 'u__username', 'username');
        $this->rsm->addFieldResult('u', 'u__name', 'name');
        $this->rsm->addScalarResult('sclr0', 'nameUpper');
        $this->rsm->addFieldResult('p', 'p__phonenumber', 'phonenumber');
        $this->rsm->addJoinedEntityResult(CmsAddress::class, 'a', 'u', 'address');
        $this->rsm->addFieldResult('a', 'a__id', 'id');
    }

    public function benchHydration(): void
    {
        (new ObjectHydrator($this->entityManager))->hydrateAll(ArrayResultFactory::createWrapperResultFromArray($this->resultSet), $this->rsm, [Query::HINT_FORCE_PARTIAL_LOAD => true]);

        $this->entityManager->clear();
    }
}
