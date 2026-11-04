<?php

declare(strict_types=1);

namespace Doctrine\Performance\Hydration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Performance\EntityManagerFactory;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsComment;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsTag;
use Doctrine\Tests\Models\CMS\CmsUser;

/** @BeforeMethods({"init"}) */
final class SimpleHydrationBench
{
    private EntityManagerInterface|null $entityManager = null;

    private EntityRepository|null $repository = null;

    public function init(): void
    {
        $this->entityManager = EntityManagerFactory::getEntityManager([
            CmsUser::class,
            CmsPhonenumber::class,
            CmsAddress::class,
            CmsEmail::class,
            CmsGroup::class,
            CmsTag::class,
            CmsArticle::class,
            CmsComment::class,
        ]);

        for ($i = 2; $i < 10000; ++$i) {
            $user = new CmsUser();

            $user->status   = 'developer';
            $user->username = 'jwage' . $i;
            $user->name     = 'Jonathan';

            $this->entityManager->persist($user);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->repository = $this->entityManager->getRepository(CmsUser::class);
    }

    public function benchHydration(): void
    {
        $this->repository->findAll();

        $this->entityManager->clear();
    }
}
