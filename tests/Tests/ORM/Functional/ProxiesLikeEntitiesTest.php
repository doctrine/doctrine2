<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsTag;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Test that Doctrine ORM correctly works with proxy instances exactly like with ordinary Entities
 *
 * The test considers two possible cases:
 *  a) __initialized__ = true and no identifier set in proxy
 *  b) __initialized__ = false and identifier set in proxy and in property
 *
 * @todo All other cases would cause lazy loading
 */
class ProxiesLikeEntitiesTest extends OrmFunctionalTestCase
{
    /** @var CmsUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            CmsUser::class,
            CmsTag::class,
            CmsPhonenumber::class,
            CmsArticle::class,
            CmsAddress::class,
            CmsEmail::class,
            CmsGroup::class,
        );

        $this->user           = new CmsUser();
        $this->user->username = 'ocramius';
        $this->user->name     = 'Marco';
        $this->_em->persist($this->user);
        $this->_em->flush();
        $this->_em->clear();
    }

    /**
     * Verifies that a proxy can be successfully persisted and updated
     */
    public function testPersistUpdate(): void
    {
        // Considering case (a)
        $proxy = $this->_em->getProxyFactory()->getProxy(CmsUser::class, ['id' => $this->user->getId()]);

        $proxy->id       = null;
        $proxy->username = 'ocra';
        $proxy->name     = 'Marco';
        $this->_em->persist($proxy);
        $this->_em->flush();
        self::assertNotNull($proxy->getId());
        $proxy->name = 'Marco Pivetta';
        $this->_em->getUnitOfWork()
            ->computeChangeSet($this->_em->getClassMetadata(CmsUser::class), $proxy);
        self::assertNotEmpty($this->_em->getUnitOfWork()->getEntityChangeSet($proxy));
        self::assertEquals('Marco Pivetta', $this->_em->find(CmsUser::class, $proxy->getId())->name);
        $this->_em->remove($proxy);
        $this->_em->flush();
    }

    public function testEntityWithIdentifier(): void
    {
        $userId             = $this->user->getId();
        $uninitializedProxy = $this->_em->getReference(CmsUser::class, $userId);
        $this->assertTrue($this->isUninitializedObject($uninitializedProxy));

        $this->_em->persist($uninitializedProxy);
        $this->_em->flush();
        self::assertTrue($this->isUninitializedObject($uninitializedProxy), 'Proxy didn\'t get initialized during flush operations');
        self::assertEquals($userId, $uninitializedProxy->getId());
        $this->_em->remove($uninitializedProxy);
        $this->_em->flush();
    }

    /**
     * Verifying that proxies can be used without problems as query parameters
     */
    public function testProxyAsDqlParameterPersist(): void
    {
        $proxy     = $this->_em->getReference(CmsUser::class, ['id' => $this->user->getId()]);
        $proxy->id = $this->user->getId();
        $result    = $this
            ->_em
            ->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u = ?1')
            ->setParameter(1, $proxy)
            ->getSingleResult();
        self::assertSame($this->user->getId(), $result->getId());
        $this->_em->remove($proxy);
        $this->_em->flush();
    }

    protected function tearDown(): void
    {
        $this->_em->createQuery('DELETE FROM Doctrine\Tests\Models\CMS\CmsUser u')->execute();
    }
}
