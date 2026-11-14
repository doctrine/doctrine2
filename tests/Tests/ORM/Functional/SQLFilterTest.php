<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\FilterException;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Company\CompanyAuction;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\Company\CompanyEvent;
use Doctrine\Tests\Models\Company\CompanyFlexContract;
use Doctrine\Tests\Models\Company\CompanyFlexUltraContract;
use Doctrine\Tests\Models\Company\CompanyManager;
use Doctrine\Tests\Models\Company\CompanyOrganization;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\OrmFunctionalTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function count;
use function in_array;
use function serialize;

/**
 * Tests SQLFilter functionality.
 */
#[Group('non-cacheable')]
class SQLFilterTest extends OrmFunctionalTestCase
{
    private int $userId;
    private int $userId2;
    private int $articleId;
    private int $articleId2;
    private int $groupId;
    private int $groupId2;
    private int $managerId;
    private int $managerId2;
    private int $contractId1;
    private int $contractId2;
    private int $organizationId;
    private int $eventId1;
    private int $eventId2;

    protected function setUp(): void
    {
        $this->useModelSet('cms');
        $this->useModelSet('company');

        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $class                                         = $this->_em->getClassMetadata(CmsUser::class);
        $class->associationMappings['groups']->fetch   = ClassMetadata::FETCH_LAZY;
        $class->associationMappings['articles']->fetch = ClassMetadata::FETCH_LAZY;
    }

    public function testConfigureFilter(): void
    {
        $config = new Configuration();

        $config->addFilter('locale', '\Doctrine\Tests\ORM\Functional\MyLocaleFilter');

        self::assertEquals('\Doctrine\Tests\ORM\Functional\MyLocaleFilter', $config->getFilterClassName('locale'));
        self::assertNull($config->getFilterClassName('foo'));
    }

    public function testEntityManagerEnableFilter(): void
    {
        $em = $this->getEntityManager();
        $this->configureFilters($em);

        // Enable an existing filter
        $filter = $em->getFilters()->enable('locale');
        self::assertInstanceOf(MyLocaleFilter::class, $filter);

        // Enable the filter again
        $filter2 = $em->getFilters()->enable('locale');
        self::assertEquals($filter, $filter2);

        // Enable a non-existing filter
        $exceptionThrown = false;
        try {
            $filter = $em->getFilters()->enable('foo');
        } catch (InvalidArgumentException) {
            $exceptionThrown = true;
        }

        self::assertTrue($exceptionThrown);
    }

    public function testEntityManagerEnabledFilters(): void
    {
        $em = $this->getEntityManager();

        // No enabled filters
        self::assertEquals([], $em->getFilters()->getEnabledFilters());

        $this->configureFilters($em);
        $filter = $em->getFilters()->enable('locale');
        $filter = $em->getFilters()->enable('soft_delete');

        // Two enabled filters
        self::assertCount(2, $em->getFilters()->getEnabledFilters());
    }

    public function testEntityManagerDisableFilter(): void
    {
        $em = $this->getEntityManager();
        $this->configureFilters($em);

        // Enable the filter
        $filter = $em->getFilters()->enable('locale');

        // Disable it
        self::assertEquals($filter, $em->getFilters()->disable('locale'));
        self::assertCount(0, $em->getFilters()->getEnabledFilters());

        // Disable a non-existing filter
        $exceptionThrown = false;
        try {
            $filter = $em->getFilters()->disable('foo');
        } catch (InvalidArgumentException) {
            $exceptionThrown = true;
        }

        self::assertTrue($exceptionThrown);

        // Disable a non-enabled filter
        $exceptionThrown = false;
        try {
            $filter = $em->getFilters()->disable('locale');
        } catch (InvalidArgumentException) {
            $exceptionThrown = true;
        }

        self::assertTrue($exceptionThrown);
    }

    public function testEntityManagerGetFilter(): void
    {
        $em = $this->getEntityManager();
        $this->configureFilters($em);

        // Enable the filter
        $filter = $em->getFilters()->enable('locale');

        // Get the filter
        self::assertEquals($filter, $em->getFilters()->getFilter('locale'));

        // Get a non-enabled filter
        $exceptionThrown = false;
        try {
            $filter = $em->getFilters()->getFilter('soft_delete');
        } catch (InvalidArgumentException) {
            $exceptionThrown = true;
        }

        self::assertTrue($exceptionThrown);
    }

    #[Group('DDC-2203')]
    public function testEntityManagerIsFilterEnabled(): void
    {
        $em = $this->getEntityManager();
        $this->configureFilters($em);

        // Check for an enabled filter
        $em->getFilters()->enable('locale');
        self::assertTrue($em->getFilters()->isEnabled('locale'));

        // Check for a disabled filter
        $em->getFilters()->disable('locale');
        self::assertFalse($em->getFilters()->isEnabled('locale'));

        // Check a non-existing filter
        self::assertFalse($em->getFilters()->isEnabled('foo_filter'));
    }

    private function configureFilters(EntityManagerInterface $em): void
    {
        // Add filters to the configuration of the EM
        $config = $em->getConfiguration();
        $config->addFilter('locale', '\Doctrine\Tests\ORM\Functional\MyLocaleFilter');
        $config->addFilter('soft_delete', '\Doctrine\Tests\ORM\Functional\MySoftDeleteFilter');
    }

    private function getMockConnection(): Connection&MockObject
    {
        return $this->createMock(Connection::class);
    }

    private function getMockEntityManager(): EntityManagerInterface&MockObject
    {
        return $this->createMock(EntityManagerInterface::class);
    }

    private function addMockFilterCollection(EntityManagerInterface&MockObject $em): FilterCollection&MockObject
    {
        $filterCollection = $this->createMock(FilterCollection::class);

        $em->method('getFilters')
           ->willReturn($filterCollection);

        return $filterCollection;
    }

    public function testSQLFilterGetSetParameter(): void
    {
        // Setup mock connection
        $conn = $this->getMockConnection();
        $conn->expects(self::once())
            ->method('quote')
            ->with(self::equalTo('en'))
            ->willReturn("'en'");

        $em = $this->getMockEntityManager();
        $em->expects(self::once())
            ->method('getConnection')
            ->willReturn($conn);

        $filterCollection = $this->addMockFilterCollection($em);
        $filterCollection
            ->expects(self::once())
            ->method('setFiltersStateDirty');

        $filter = new MyLocaleFilter($em);

        $filter->setParameter('locale', 'en', Types::STRING);

        self::assertEquals("'en'", $filter->getParameter('locale'));
    }

    #[Group('DDC-3161')]
    #[Group('1054')]
    public function testSQLFilterGetConnection(): void
    {
        // Setup mock connection
        $conn = $this->getMockConnection();

        $em = $this->getMockEntityManager();
        $em->expects(self::once())
            ->method('getConnection')
            ->willReturn($conn);

        $filter = new MyLocaleFilter($em);

        $reflMethod = new ReflectionMethod(SQLFilter::class, 'getConnection');

        self::assertSame($conn, $reflMethod->invoke($filter));
    }

    public function testSQLFilterSetParameterInfersType(): void
    {
        // Setup mock connection
        $conn = $this->getMockConnection();
        $conn->expects(self::once())
            ->method('quote')
            ->with(self::equalTo('en'))
            ->willReturn("'en'");

        $em = $this->getMockEntityManager();
        $em->expects(self::once())
            ->method('getConnection')
            ->willReturn($conn);

        $filterCollection = $this->addMockFilterCollection($em);
        $filterCollection
            ->expects(self::once())
            ->method('setFiltersStateDirty');

        $filter = new MyLocaleFilter($em);

        $filter->setParameter('locale', 'en');

        self::assertEquals("'en'", $filter->getParameter('locale'));
    }

    public function testSQLFilterSetArrayParameterInfersType(): void
    {
        // Setup mock connection
        $conn = $this->getMockConnection();
        $conn->method('quote')
             ->willReturnCallback(static fn ($value) => "'" . $value . "'");

        $em = $this->getMockEntityManager();
        $em->method('getConnection')
           ->willReturn($conn);

        $filterCollection = $this->addMockFilterCollection($em);
        $filterCollection
            ->expects(self::once())
            ->method('setFiltersStateDirty');

        $filter = new MyLocaleFilter($em);

        $filter->setParameterList('locale', ['en', 'es']);

        self::assertEquals("'en','es'", $filter->getParameterList('locale'));
    }

    public function testSQLFilterAddConstraint(): void
    {
        // Set up metadata mock
        $targetEntity = $this->getMockBuilder(ClassMetadata::class)
            ->disableOriginalConstructor()
            ->getMock();

        $filter = new MySoftDeleteFilter($this->getMockEntityManager());

        // Test for an entity that gets extra filter data
        $targetEntity->name = 'MyEntity\SoftDeleteNewsItem';
        self::assertEquals('t1_.deleted = 0', $filter->addFilterConstraint($targetEntity, 't1_'));

        // Test for an entity that doesn't get extra filter data
        $targetEntity->name = 'MyEntity\NoSoftDeleteNewsItem';
        self::assertEquals('', $filter->addFilterConstraint($targetEntity, 't1_'));
    }

    public function testSQLFilterToString(): void
    {
        $em               = $this->getMockEntityManager();
        $filterCollection = $this->addMockFilterCollection($em);

        $filter = new MyLocaleFilter($em);
        $filter->setParameter('locale', 'en', Types::STRING);
        $filter->setParameter('foo', 'bar', Types::STRING);

        $filter2 = new MyLocaleFilter($em);
        $filter2->setParameter('foo', 'bar', Types::STRING);
        $filter2->setParameter('locale', 'en', Types::STRING);

        $parameters = [
            'foo' => ['value' => 'bar', 'type' => Types::STRING, 'is_list' => false],
            'locale' => ['value' => 'en', 'type' => Types::STRING, 'is_list' => false],
        ];

        self::assertEquals(serialize($parameters), '' . $filter);
        self::assertEquals('' . $filter, '' . $filter2);
    }

    public function testQueryCacheDependsOnFilters(): void
    {
        $query = $this->_em->createQuery('select ux from Doctrine\Tests\Models\CMS\CmsUser ux');

        $cache = new ArrayAdapter();
        $query->setQueryCache($cache);

        $query->getResult();
        self::assertCount(1, $cache->getValues());

        $conf = $this->_em->getConfiguration();
        $conf->addFilter('locale', MyLocaleFilter::class);
        $this->_em->getFilters()->enable('locale');

        $query->getResult();
        self::assertCount(2, $cache->getValues());

        // Another time doesn't add another cache entry
        $query->getResult();
        self::assertCount(2, $cache->getValues());
    }

    public function testQueryGenerationDependsOnFilters(): void
    {
        $query         = $this->_em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsAddress a');
        $firstSQLQuery = $query->getSQL();

        $conf = $this->_em->getConfiguration();
        $conf->addFilter('country', '\Doctrine\Tests\ORM\Functional\CMSCountryFilter');
        $this->_em->getFilters()->enable('country')
            ->setParameterList('country', ['en'], Types::STRING);

        self::assertNotEquals($firstSQLQuery, $query->getSQL());
    }

    public function testRepositoryFind(): void
    {
        $this->loadFixtureData();

        self::assertNotNull($this->_em->getRepository(CmsGroup::class)->find($this->groupId));
        self::assertNotNull($this->_em->getRepository(CmsGroup::class)->find($this->groupId2));

        $this->useCMSGroupPrefixFilter();
        $this->_em->clear();

        self::assertNotNull($this->_em->getRepository(CmsGroup::class)->find($this->groupId));
        self::assertNull($this->_em->getRepository(CmsGroup::class)->find($this->groupId2));
    }

    public function testRepositoryFindAll(): void
    {
        $this->loadFixtureData();

        self::assertCount(2, $this->_em->getRepository(CmsGroup::class)->findAll());

        $this->useCMSGroupPrefixFilter();
        $this->_em->clear();

        self::assertCount(1, $this->_em->getRepository(CmsGroup::class)->findAll());
    }

    public function testRepositoryFindBy(): void
    {
        $this->loadFixtureData();

        self::assertCount(1, $this->_em->getRepository(CmsGroup::class)->findBy(
            ['id' => $this->groupId2],
        ));

        $this->useCMSGroupPrefixFilter();
        $this->_em->clear();

        self::assertCount(0, $this->_em->getRepository(CmsGroup::class)->findBy(
            ['id' => $this->groupId2],
        ));
    }

    public function testRepositoryFindByX(): void
    {
        $this->loadFixtureData();

        self::assertCount(1, $this->_em->getRepository(CmsGroup::class)->findById($this->groupId2));

        $this->useCMSGroupPrefixFilter();
        $this->_em->clear();

        self::assertCount(0, $this->_em->getRepository(CmsGroup::class)->findById($this->groupId2));
    }

    public function testRepositoryFindOneBy(): void
    {
        $this->loadFixtureData();

        self::assertNotNull($this->_em->getRepository(CmsGroup::class)->findOneBy(
            ['id' => $this->groupId2],
        ));

        $this->useCMSGroupPrefixFilter();
        $this->_em->clear();

        self::assertNull($this->_em->getRepository(CmsGroup::class)->findOneBy(
            ['id' => $this->groupId2],
        ));
    }

    public function testRepositoryFindOneByX(): void
    {
        $this->loadFixtureData();

        self::assertNotNull($this->_em->getRepository(CmsGroup::class)->findOneById($this->groupId2));

        $this->useCMSGroupPrefixFilter();
        $this->_em->clear();

        self::assertNull($this->_em->getRepository(CmsGroup::class)->findOneById($this->groupId2));
    }

    public function testToOneFilter(): void
    {
        $this->loadFixtureData();

        $query = $this->_em->createQuery('select ux, ua from Doctrine\Tests\Models\CMS\CmsUser ux JOIN ux.address ua');

        // We get two users before enabling the filter
        self::assertCount(2, $query->getResult());

        $conf = $this->_em->getConfiguration();
        $conf->addFilter('country', '\Doctrine\Tests\ORM\Functional\CMSCountryFilter');
        $this->_em->getFilters()->enable('country')->setParameterList('country', ['Germany'], Types::STRING);

        // We get one user after enabling the filter
        self::assertCount(1, $query->getResult());

        $this->_em->getFilters()->enable('country')->setParameterList('country', ['Germany', 'France'], Types::STRING);

        self::assertEquals(2, count($query->getResult()));
    }

    public function testOneToOneInverseSideWithFilter(): void
    {
        $this->loadFixtureData();

        $conf = $this->_em->getConfiguration();
        $conf->addFilter('country', '\Doctrine\Tests\ORM\Functional\CMSCountryFilter');
        $this->_em->getFilters()->enable('country')->setParameterList('country', ['Germany'], Types::STRING);

        $user = $this->_em->find(CmsUser::class, $this->userId);
        self::assertNotEmpty($user->address);

        $user2 = $this->_em->find(CmsUser::class, $this->userId2);
        self::assertEmpty($user2->address);
    }

    public function testManyToManyFilter(): void
    {
        $this->loadFixtureData();
        $query = $this->_em->createQuery('select ux, ug from Doctrine\Tests\Models\CMS\CmsUser ux JOIN ux.groups ug');

        // We get two users before enabling the filter
        self::assertCount(2, $query->getResult());

        $conf = $this->_em->getConfiguration();
        $conf->addFilter('group_prefix', '\Doctrine\Tests\ORM\Functional\CMSGroupPrefixFilter');
        $this->_em->getFilters()->enable('group_prefix')->setParameter('prefix', 'bar_%', Types::STRING);

        // We get one user after enabling the filter
        self::assertCount(1, $query->getResult());
    }

    public function testWhereFilter(): void
    {
        $this->loadFixtureData();
        $query = $this->_em->createQuery('select ug from Doctrine\Tests\Models\CMS\CmsGroup ug WHERE 1=1');

        // We get two users before enabling the filter
        self::assertCount(2, $query->getResult());

        $conf = $this->_em->getConfiguration();
        $conf->addFilter('group_prefix', '\Doctrine\Tests\ORM\Functional\CMSGroupPrefixFilter');
        $this->_em->getFilters()->enable('group_prefix')->setParameter('prefix', 'bar_%', Types::STRING);

        // We get one user after enabling the filter
        self::assertCount(1, $query->getResult());
    }

    public function testWhereOrFilter(): void
    {
        $this->loadFixtureData();
        $query = $this->_em->createQuery('select ug from Doctrine\Tests\Models\CMS\CmsGroup ug WHERE 1=1 OR 1=1');

        // We get two users before enabling the filter
        self::assertCount(2, $query->getResult());

        $conf = $this->_em->getConfiguration();
        $conf->addFilter('group_prefix', '\Doctrine\Tests\ORM\Functional\CMSGroupPrefixFilter');
        $this->_em->getFilters()->enable('group_prefix')->setParameter('prefix', 'bar_%', Types::STRING);

        // We get one user after enabling the filter
        self::assertCount(1, $query->getResult());
    }

    private function loadLazyFixtureData(): void
    {
        $class                                         = $this->_em->getClassMetadata(CmsUser::class);
        $class->associationMappings['articles']->fetch = ClassMetadata::FETCH_EXTRA_LAZY;
        $class->associationMappings['groups']->fetch   = ClassMetadata::FETCH_EXTRA_LAZY;
        $this->loadFixtureData();
    }

    private function useCMSArticleTopicFilter(): void
    {
        $conf = $this->_em->getConfiguration();
        $conf->addFilter('article_topic', '\Doctrine\Tests\ORM\Functional\CMSArticleTopicFilter');
        $this->_em->getFilters()->enable('article_topic')->setParameter('topic', 'Test1', Types::STRING);
    }

    public function testOneToManyExtraLazyCountWithFilter(): void
    {
        $this->loadLazyFixtureData();
        $user = $this->_em->find(CmsUser::class, $this->userId);

        self::assertFalse($user->articles->isInitialized());
        self::assertCount(2, $user->articles);

        $this->useCMSArticleTopicFilter();

        self::assertCount(1, $user->articles);
    }

    public function testOneToManyExtraLazyContainsWithFilter(): void
    {
        $this->loadLazyFixtureData();
        $user            = $this->_em->find(CmsUser::class, $this->userId);
        $filteredArticle = $this->_em->find(CmsArticle::class, $this->articleId2);

        self::assertFalse($user->articles->isInitialized());
        self::assertTrue($user->articles->contains($filteredArticle));

        $this->useCMSArticleTopicFilter();

        self::assertFalse($user->articles->contains($filteredArticle));
    }

    public function testOneToManyExtraLazySliceWithFilter(): void
    {
        $this->loadLazyFixtureData();
        $user = $this->_em->find(CmsUser::class, $this->userId);

        self::assertFalse($user->articles->isInitialized());
        self::assertCount(2, $user->articles->slice(0, 10));

        $this->useCMSArticleTopicFilter();

        self::assertCount(1, $user->articles->slice(0, 10));
    }

    private function useCMSGroupPrefixFilter(): void
    {
        $conf = $this->_em->getConfiguration();
        $conf->addFilter('group_prefix', '\Doctrine\Tests\ORM\Functional\CMSGroupPrefixFilter');
        $this->_em->getFilters()->enable('group_prefix')->setParameter('prefix', 'foo%', Types::STRING);
    }

    public function testManyToManyExtraLazyCountWithFilter(): void
    {
        $this->loadLazyFixtureData();

        $user = $this->_em->find(CmsUser::class, $this->userId2);

        self::assertFalse($user->groups->isInitialized());
        self::assertCount(2, $user->groups);

        $this->useCMSGroupPrefixFilter();

        self::assertCount(1, $user->groups);
    }

    public function testManyToManyExtraLazyContainsWithFilter(): void
    {
        $this->loadLazyFixtureData();
        $user            = $this->_em->find(CmsUser::class, $this->userId2);
        $filteredArticle = $this->_em->find(CmsGroup::class, $this->groupId2);

        self::assertFalse($user->groups->isInitialized());
        self::assertTrue($user->groups->contains($filteredArticle));

        $this->useCMSGroupPrefixFilter();

        self::assertFalse($user->groups->contains($filteredArticle));
    }

    public function testManyToManyExtraLazySliceWithFilter(): void
    {
        $this->loadLazyFixtureData();
        $user = $this->_em->find(CmsUser::class, $this->userId2);

        self::assertFalse($user->groups->isInitialized());
        self::assertCount(2, $user->groups->slice(0, 10));

        $this->useCMSGroupPrefixFilter();

        self::assertCount(1, $user->groups->slice(0, 10));
    }

    private function loadFixtureData(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'developer';

        $address          = new CmsAddress();
        $address->country = 'Germany';
        $address->city    = 'Berlin';
        $address->zip     = '12345';

        $user->address = $address; // inverse side
        $address->user = $user; // owning side!

        $group       = new CmsGroup();
        $group->name = 'foo_group';
        $user->addGroup($group);

        $article1        = new CmsArticle();
        $article1->topic = 'Test1';
        $article1->text  = 'Test';
        $article1->setAuthor($user);

        $article2        = new CmsArticle();
        $article2->topic = 'Test2';
        $article2->text  = 'Test';
        $article2->setAuthor($user);

        $this->_em->persist($article1);
        $this->_em->persist($article2);

        $this->_em->persist($user);

        $user2           = new CmsUser();
        $user2->name     = 'Guilherme';
        $user2->username = 'gblanco';
        $user2->status   = 'developer';

        $address2          = new CmsAddress();
        $address2->country = 'France';
        $address2->city    = 'Paris';
        $address2->zip     = '12345';

        $user->address  = $address2; // inverse side
        $address2->user = $user2; // owning side!

        $user2->addGroup($group);
        $group2       = new CmsGroup();
        $group2->name = 'bar_group';
        $user2->addGroup($group2);

        $this->_em->persist($user2);
        $this->_em->flush();
        $this->_em->clear();

        $this->userId     = $user->getId();
        $this->userId2    = $user2->getId();
        $this->articleId  = $article1->id;
        $this->articleId2 = $article2->id;
        $this->groupId    = $group->id;
        $this->groupId2   = $group2->id;
    }

    public function testJoinSubclassPersisterFilterOnlyOnRootTableWhenFetchingSubEntity(): void
    {
        $this->loadCompanyJoinedSubclassFixtureData();
        // Persister
        self::assertCount(2, $this->_em->getRepository(CompanyManager::class)->findAll());
        // SQLWalker
        self::assertCount(2, $this->_em->createQuery('SELECT cm FROM Doctrine\Tests\Models\Company\CompanyManager cm')->getResult());

        // Enable the filter
        $this->usePersonNameFilter('Guilh%');

        $managers = $this->_em->getRepository(CompanyManager::class)->findAll();
        self::assertCount(1, $managers);
        self::assertEquals('Guilherme', $managers[0]->getName());

        self::assertCount(1, $this->_em->createQuery('SELECT cm FROM Doctrine\Tests\Models\Company\CompanyManager cm')->getResult());
    }

    public function testJoinSubclassPersisterFilterOnlyOnRootTableWhenFetchingRootEntity(): void
    {
        $this->loadCompanyJoinedSubclassFixtureData();
        self::assertCount(3, $this->_em->getRepository(CompanyPerson::class)->findAll());
        self::assertCount(3, $this->_em->createQuery('SELECT cp FROM Doctrine\Tests\Models\Company\CompanyPerson cp')->getResult());

        // Enable the filter
        $this->usePersonNameFilter('Guilh%');

        $persons = $this->_em->getRepository(CompanyPerson::class)->findAll();
        self::assertCount(1, $persons);
        self::assertEquals('Guilherme', $persons[0]->getName());

        self::assertCount(1, $this->_em->createQuery('SELECT cp FROM Doctrine\Tests\Models\Company\CompanyPerson cp')->getResult());
    }

    private function loadCompanyJoinedSubclassFixtureData(): void
    {
        $manager = new CompanyManager();
        $manager->setName('Roman');
        $manager->setTitle('testlead');
        $manager->setSalary(42);
        $manager->setDepartment('persisters');

        $manager2 = new CompanyManager();
        $manager2->setName('Guilherme');
        $manager2->setTitle('devlead');
        $manager2->setSalary(42);
        $manager2->setDepartment('parsers');

        $person = new CompanyPerson();
        $person->setName('Benjamin');

        $this->_em->persist($manager);
        $this->_em->persist($manager2);
        $this->_em->persist($person);
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testSingleTableInheritanceFilterOnlyOnRootTableWhenFetchingSubEntity(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();
        // Persister
        self::assertCount(2, $this->_em->getRepository(CompanyFlexUltraContract::class)->findAll());
        // SQLWalker
        self::assertCount(2, $this->_em->createQuery('SELECT cfc FROM Doctrine\Tests\Models\Company\CompanyFlexUltraContract cfc')->getResult());

        // Enable the filter
        $conf = $this->_em->getConfiguration();
        $conf->addFilter('completed_contract', '\Doctrine\Tests\ORM\Functional\CompletedContractFilter');
        $this->_em->getFilters()
            ->enable('completed_contract')
            ->setParameter('completed', true, Types::BOOLEAN);

        self::assertCount(1, $this->_em->getRepository(CompanyFlexUltraContract::class)->findAll());
        self::assertCount(1, $this->_em->createQuery('SELECT cfc FROM Doctrine\Tests\Models\Company\CompanyFlexUltraContract cfc')->getResult());
    }

    public function testSingleTableInheritanceFilterOnlyOnRootTableWhenFetchingRootEntity(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();
        self::assertCount(4, $this->_em->getRepository(CompanyFlexContract::class)->findAll());
        self::assertCount(4, $this->_em->createQuery('SELECT cfc FROM Doctrine\Tests\Models\Company\CompanyFlexContract cfc')->getResult());

        // Enable the filter
        $conf = $this->_em->getConfiguration();
        $conf->addFilter('completed_contract', '\Doctrine\Tests\ORM\Functional\CompletedContractFilter');
        $this->_em->getFilters()
            ->enable('completed_contract')
            ->setParameter('completed', true, Types::BOOLEAN);

        self::assertCount(2, $this->_em->getRepository(CompanyFlexContract::class)->findAll());
        self::assertCount(2, $this->_em->createQuery('SELECT cfc FROM Doctrine\Tests\Models\Company\CompanyFlexContract cfc')->getResult());
    }

    private function loadCompanySingleTableInheritanceFixtureData(): void
    {
        $contract1 = new CompanyFlexUltraContract();
        $contract2 = new CompanyFlexUltraContract();
        $contract2->markCompleted();

        $contract3 = new CompanyFlexContract();
        $contract4 = new CompanyFlexContract();
        $contract4->markCompleted();

        $manager = new CompanyManager();
        $manager->setName('Alexander');
        $manager->setSalary(42);
        $manager->setDepartment('Doctrine');
        $manager->setTitle('Filterer');

        $manager2 = new CompanyManager();
        $manager2->setName('Benjamin');
        $manager2->setSalary(1337);
        $manager2->setDepartment('Doctrine');
        $manager2->setTitle('Maintainer');

        $contract1->addManager($manager);
        $contract2->addManager($manager);
        $contract3->addManager($manager);
        $contract4->addManager($manager);

        $contract1->addManager($manager2);

        $contract1->setSalesPerson($manager);
        $contract2->setSalesPerson($manager);

        $this->_em->persist($manager);
        $this->_em->persist($manager2);
        $this->_em->persist($contract1);
        $this->_em->persist($contract2);
        $this->_em->persist($contract3);
        $this->_em->persist($contract4);
        $this->_em->flush();
        $this->_em->clear();

        $this->managerId   = $manager->getId();
        $this->managerId2  = $manager2->getId();
        $this->contractId1 = $contract1->getId();
        $this->contractId2 = $contract2->getId();
    }

    private function useCompletedContractFilter(): void
    {
        $conf = $this->_em->getConfiguration();
        $conf->addFilter('completed_contract', '\Doctrine\Tests\ORM\Functional\CompletedContractFilter');
        $this->_em->getFilters()
            ->enable('completed_contract')
            ->setParameter('completed', true, Types::BOOLEAN);
    }

    public function testManyToManyExtraLazyCountWithFilterOnSTI(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();

        $manager = $this->_em->find(CompanyManager::class, $this->managerId);

        self::assertFalse($manager->managedContracts->isInitialized());
        self::assertCount(4, $manager->managedContracts);

        // Enable the filter
        $this->useCompletedContractFilter();

        self::assertFalse($manager->managedContracts->isInitialized());
        self::assertCount(2, $manager->managedContracts);
    }

    public function testManyToManyExtraLazyContainsWithFilterOnSTI(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();

        $manager   = $this->_em->find(CompanyManager::class, $this->managerId);
        $contract1 = $this->_em->find(CompanyContract::class, $this->contractId1);
        $contract2 = $this->_em->find(CompanyContract::class, $this->contractId2);

        self::assertFalse($manager->managedContracts->isInitialized());
        self::assertTrue($manager->managedContracts->contains($contract1));
        self::assertTrue($manager->managedContracts->contains($contract2));

        // Enable the filter
        $this->useCompletedContractFilter();

        self::assertFalse($manager->managedContracts->isInitialized());
        self::assertFalse($manager->managedContracts->contains($contract1));
        self::assertTrue($manager->managedContracts->contains($contract2));
    }

    public function testManyToManyExtraLazySliceWithFilterOnSTI(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();

        $manager = $this->_em->find(CompanyManager::class, $this->managerId);

        self::assertFalse($manager->managedContracts->isInitialized());
        self::assertCount(4, $manager->managedContracts->slice(0, 10));

        // Enable the filter
        $this->useCompletedContractFilter();

        self::assertFalse($manager->managedContracts->isInitialized());
        self::assertCount(2, $manager->managedContracts->slice(0, 10));
    }

    private function usePersonNameFilter($name): void
    {
        // Enable the filter
        $conf = $this->_em->getConfiguration();
        $conf->addFilter('person_name', '\Doctrine\Tests\ORM\Functional\CompanyPersonNameFilter');
        $this->_em->getFilters()
            ->enable('person_name')
            ->setParameter('name', $name, Types::STRING);
    }

    public function testManyToManyExtraLazyCountWithFilterOnCTI(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();

        $contract = $this->_em->find(CompanyFlexUltraContract::class, $this->contractId1);

        self::assertFalse($contract->managers->isInitialized());
        self::assertCount(2, $contract->managers);

        // Enable the filter
        $this->usePersonNameFilter('Benjamin');

        self::assertFalse($contract->managers->isInitialized());
        self::assertCount(1, $contract->managers);
    }

    public function testManyToManyExtraLazyContainsWithFilterOnCTI(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();

        $contract = $this->_em->find(CompanyFlexUltraContract::class, $this->contractId1);
        $manager1 = $this->_em->find(CompanyManager::class, $this->managerId);
        $manager2 = $this->_em->find(CompanyManager::class, $this->managerId2);

        self::assertFalse($contract->managers->isInitialized());
        self::assertTrue($contract->managers->contains($manager1));
        self::assertTrue($contract->managers->contains($manager2));

        // Enable the filter
        $this->usePersonNameFilter('Benjamin');

        self::assertFalse($contract->managers->isInitialized());
        self::assertFalse($contract->managers->contains($manager1));
        self::assertTrue($contract->managers->contains($manager2));
    }

    public function testManyToManyExtraLazySliceWithFilterOnCTI(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();

        $contract = $this->_em->find(CompanyFlexUltraContract::class, $this->contractId1);

        self::assertFalse($contract->managers->isInitialized());
        self::assertCount(2, $contract->managers->slice(0, 10));

        // Enable the filter
        $this->usePersonNameFilter('Benjamin');

        self::assertFalse($contract->managers->isInitialized());
        self::assertCount(1, $contract->managers->slice(0, 10));
    }

    public function testOneToManyExtraLazyCountWithFilterOnSTI(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();

        $manager = $this->_em->find(CompanyManager::class, $this->managerId);

        self::assertFalse($manager->soldContracts->isInitialized());
        self::assertCount(2, $manager->soldContracts);

        // Enable the filter
        $this->useCompletedContractFilter();

        self::assertFalse($manager->soldContracts->isInitialized());
        self::assertCount(1, $manager->soldContracts);
    }

    public function testOneToManyExtraLazyContainsWithFilterOnSTI(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();

        $manager   = $this->_em->find(CompanyManager::class, $this->managerId);
        $contract1 = $this->_em->find(CompanyContract::class, $this->contractId1);
        $contract2 = $this->_em->find(CompanyContract::class, $this->contractId2);

        self::assertFalse($manager->soldContracts->isInitialized());
        self::assertTrue($manager->soldContracts->contains($contract1));
        self::assertTrue($manager->soldContracts->contains($contract2));

        // Enable the filter
        $this->useCompletedContractFilter();

        self::assertFalse($manager->soldContracts->isInitialized());
        self::assertFalse($manager->soldContracts->contains($contract1));
        self::assertTrue($manager->soldContracts->contains($contract2));
    }

    public function testOneToManyExtraLazySliceWithFilterOnSTI(): void
    {
        $this->loadCompanySingleTableInheritanceFixtureData();

        $manager = $this->_em->find(CompanyManager::class, $this->managerId);

        self::assertFalse($manager->soldContracts->isInitialized());
        self::assertCount(2, $manager->soldContracts->slice(0, 10));

        // Enable the filter
        $this->useCompletedContractFilter();

        self::assertFalse($manager->soldContracts->isInitialized());
        self::assertCount(1, $manager->soldContracts->slice(0, 10));
    }

    private function loadCompanyOrganizationEventJoinedSubclassFixtureData(): void
    {
        $organization = new CompanyOrganization();

        $event1 = new CompanyAuction();
        $event1->setData('foo');

        $event2 = new CompanyAuction();
        $event2->setData('bar');

        $organization->addEvent($event1);
        $organization->addEvent($event2);

        $this->_em->persist($organization);
        $this->_em->flush();
        $this->_em->clear();

        $this->organizationId = $organization->getId();
        $this->eventId1       = $event1->getId();
        $this->eventId2       = $event2->getId();
    }

    private function useCompanyEventIdFilter(): void
    {
        // Enable the filter
        $conf = $this->_em->getConfiguration();
        $conf->addFilter('event_id', CompanyEventFilter::class);
        $this->_em->getFilters()
            ->enable('event_id')
            ->setParameter('id', $this->eventId2);
    }

    public function testOneToManyExtraLazyCountWithFilterOnCTI(): void
    {
        $this->loadCompanyOrganizationEventJoinedSubclassFixtureData();

        $organization = $this->_em->find(CompanyOrganization::class, $this->organizationId);

        self::assertFalse($organization->events->isInitialized());
        self::assertCount(2, $organization->events);

        // Enable the filter
        $this->useCompanyEventIdFilter();

        self::assertFalse($organization->events->isInitialized());
        self::assertCount(1, $organization->events);
    }

    public function testOneToManyExtraLazyContainsWithFilterOnCTI(): void
    {
        $this->loadCompanyOrganizationEventJoinedSubclassFixtureData();

        $organization = $this->_em->find(CompanyOrganization::class, $this->organizationId);

        $event1 = $this->_em->find(CompanyEvent::class, $this->eventId1);
        $event2 = $this->_em->find(CompanyEvent::class, $this->eventId2);

        self::assertFalse($organization->events->isInitialized());
        self::assertTrue($organization->events->contains($event1));
        self::assertTrue($organization->events->contains($event2));

        // Enable the filter
        $this->useCompanyEventIdFilter();

        self::assertFalse($organization->events->isInitialized());
        self::assertFalse($organization->events->contains($event1));
        self::assertTrue($organization->events->contains($event2));
    }

    public function testOneToManyExtraLazySliceWithFilterOnCTI(): void
    {
        $this->loadCompanyOrganizationEventJoinedSubclassFixtureData();

        $organization = $this->_em->find(CompanyOrganization::class, $this->organizationId);

        self::assertFalse($organization->events->isInitialized());
        self::assertCount(2, $organization->events->slice(0, 10));

        // Enable the filter
        $this->useCompanyEventIdFilter();

        self::assertFalse($organization->events->isInitialized());
        self::assertCount(1, $organization->events->slice(0, 10));
    }

    public function testRetrieveSingleAsListThrowsException(): void
    {
        $conf = $this->_em->getConfiguration();
        $conf->addFilter('country', '\Doctrine\Tests\ORM\Functional\CMSCountryFilter');

        $this->_em->getFilters()->enable('country');

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('Cannot convert single SQL filter parameter "country" into a list value.');

        $this->_em->getFilters()->getFilter('country')->setParameter('country', 'DE');
        $this->_em->getFilters()->getFilter('country')->getParameterList('country');
    }

    public function testRetrieveListAsSingleThrowsException(): void
    {
        $conf = $this->_em->getConfiguration();
        $conf->addFilter('country', '\Doctrine\Tests\ORM\Functional\CMSCountryFilter');

        $this->_em->getFilters()->enable('country');

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('Cannot convert list-based SQL filter parameter "country" into a single value.');

        $this->_em->getFilters()->getFilter('country')->setParameterList('country', ['DE']);
        $this->_em->getFilters()->getFilter('country')->getParameter('country');
    }
}

class MySoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->name !== 'MyEntity\SoftDeleteNewsItem') {
            return '';
        }

        return $targetTableAlias . '.deleted = 0';
    }
}

class MyLocaleFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (! in_array('LocaleAware', $targetEntity->reflClass->getInterfaceNames(), true)) {
            return '';
        }

        return $targetTableAlias . '.locale = ' . $this->getParameter('locale'); // getParam uses connection to quote the value.
    }
}

class CMSCountryFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->name !== CmsAddress::class) {
            return '';
        }

        return $targetTableAlias . '.country IN (' . $this->getParameterList('country') . ')'; // getParam uses connection to quote the value.
    }
}

class CMSGroupPrefixFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->name !== CmsGroup::class) {
            return '';
        }

        return $targetTableAlias . '.name LIKE ' . $this->getParameter('prefix'); // getParam uses connection to quote the value.
    }
}

class CMSArticleTopicFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->name !== CmsArticle::class) {
            return '';
        }

        return $targetTableAlias . '.topic = ' . $this->getParameter('topic'); // getParam uses connection to quote the value.
    }
}

class CompanyPersonNameFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->name !== CompanyPerson::class) {
            return '';
        }

        return $targetTableAlias . '.name LIKE ' . $this->getParameter('name');
    }
}

class CompletedContractFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->name !== CompanyContract::class) {
            return '';
        }

        return $targetTableAlias . '.completed = ' . $this->getParameter('completed');
    }
}

class CompanyEventFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->name !== CompanyEvent::class) {
            return '';
        }

        return $targetTableAlias . '.id = ' . $this->getParameter('id');
    }
}
