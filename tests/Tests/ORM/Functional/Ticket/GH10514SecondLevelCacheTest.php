<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Cache\Persister\Entity\CachedEntityPersister;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The second-level cache with an identifier that is an association two levels deep. */
#[Group('GH10514')]
class GH10514SecondLevelCacheTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->enableSecondLevelCache();

        parent::setUp();

        $this->createSchemaForModels(GH10514CacheRoot::class, GH10514CacheLevel1::class, GH10514CacheLevel2::class);

        $root   = new GH10514CacheRoot(1);
        $level1 = new GH10514CacheLevel1($root);
        $this->_em->persist($root);
        $this->_em->persist($level1);
        $this->_em->persist(new GH10514CacheLevel2($level1, 'a'));
        $this->_em->flush();
        $this->_em->clear();
        $this->_em->getCache()->evictEntityRegions();
    }

    protected function tearDown(): void
    {
        $conn = static::$sharedConn;
        $conn->executeStatement('DELETE FROM GH10514CacheLevel2');
        $conn->executeStatement('DELETE FROM GH10514CacheLevel1');
        $conn->executeStatement('DELETE FROM GH10514CacheRoot');

        parent::tearDown();
    }

    public function testFindMissThenHit(): void
    {
        $level2 = $this->_em->find(GH10514CacheLevel2::class, 1);
        self::assertNotNull($level2);
        self::assertSame('a', $level2->name);
        self::assertTrue($this->_em->getCache()->containsEntity(GH10514CacheLevel2::class, 1), 'stored under the flat identifier');

        $this->_em->clear();
        $this->getQueryLog()->reset()->enable();

        $level2 = $this->_em->find(GH10514CacheLevel2::class, 1);
        self::assertNotNull($level2);
        self::assertSame('a', $level2->name);
        self::assertSame(1, $level2->level1->root->id);
        $this->assertQueryCount(0, 'second find comes from the cache');
    }

    public function testLazyLoadingAReferenceHitsTheCache(): void
    {
        $this->_em->find(GH10514CacheLevel2::class, 1);
        $this->_em->clear();
        $this->getQueryLog()->reset()->enable();

        $ref = $this->_em->getReference(GH10514CacheLevel2::class, 1);
        self::assertSame('a', $ref->name);
        $this->assertQueryCount(0, 'lazy loading comes from the cache');
    }

    public function testExistsWithAnEntityAsIdentifier(): void
    {
        $level2 = $this->_em->find(GH10514CacheLevel2::class, 1);
        self::assertNotNull($level2);

        $persister = $this->_em->getUnitOfWork()->getEntityPersister(GH10514CacheLevel2::class);
        self::assertInstanceOf(CachedEntityPersister::class, $persister);

        $this->getQueryLog()->reset()->enable();
        self::assertTrue($persister->exists($level2));
        $this->assertQueryCount(0, 'exists() answered by the cache');
    }
}

#[ORM\Entity]
#[ORM\Cache(usage: 'NONSTRICT_READ_WRITE')]
class GH10514CacheRoot
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
    ) {
    }
}

#[ORM\Entity]
#[ORM\Cache(usage: 'NONSTRICT_READ_WRITE')]
class GH10514CacheLevel1
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne]
        #[ORM\Cache(usage: 'NONSTRICT_READ_WRITE')]
        #[ORM\JoinColumn(name: 'root_id', referencedColumnName: 'id')]
        public GH10514CacheRoot $root,
    ) {
    }
}

#[ORM\Entity]
#[ORM\Cache(usage: 'NONSTRICT_READ_WRITE')]
class GH10514CacheLevel2
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne]
        #[ORM\Cache(usage: 'NONSTRICT_READ_WRITE')]
        #[ORM\JoinColumn(name: 'level1_id', referencedColumnName: 'root_id')]
        public GH10514CacheLevel1 $level1,
        #[ORM\Column]
        public string $name,
    ) {
    }
}
