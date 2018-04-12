<?php

declare(strict_types=1);

namespace Doctrine\Tests;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\Logging\StatisticsCacheLogger;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\MappingDriver;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Proxy\Factory\ProxyFactory;
use Doctrine\ORM\Tools\DebugUnitOfWorkListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\DbalTypes\Rot13Type;
use Doctrine\Tests\EventListener\CacheMetadataListener;
use PHPUnit\Framework\AssertionFailedError;
use const PHP_EOL;
use function array_map;
use function array_reverse;
use function array_slice;
use function count;
use function explode;
use function get_class;
use function getenv;
use function implode;
use function in_array;
use function is_object;
use function realpath;
use function sprintf;
use function strpos;
use function strtolower;
use function var_export;

/**
 * Base testcase class for all functional ORM testcases.
 */
abstract class OrmFunctionalTestCase extends OrmTestCase
{
    /**
     * The metadata cache shared between all functional tests.
     *
     * @var Cache|null
     */
    private static $metadataCacheImpl = null;

    /**
     * The query cache shared between all functional tests.
     *
     * @var Cache|null
     */
    private static $queryCacheImpl = null;

    /**
     * Shared connection when a TestCase is run alone (outside of its functional suite).
     *
     * @var \Doctrine\DBAL\Connection|null
     */
    protected static $sharedConn;

    /** @var EntityManagerInterface */
    protected $em;

    /** @var SchemaTool */
    protected $schemaTool;

    /** @var DebugStack */
    protected $sqlLoggerStack;

    /**
     * The names of the model sets used in this testcase.
     *
     * @var array
     */
    protected $usedModelSets = [];

    /**
     * To be configured by the test that uses result set cache
     *
     * @var Cache|null
     */
    protected $resultCacheImpl;

    /**
     * Whether the database schema has already been created.
     *
     * @var array
     */
    protected static $tablesCreated = [];

    /**
     * Array of entity class name to their tables that were created.
     *
     * @var array
     */
    protected static $entityTablesCreated = [];

    /**
     * List of model sets and their classes.
     *
     * @var array
     */
    protected static $modelSets = [
        'cms' => [
            Models\CMS\CmsUser::class,
            Models\CMS\CmsPhonenumber::class,
            Models\CMS\CmsAddress::class,
            Models\CMS\CmsEmail::class,
            Models\CMS\CmsGroup::class,
            Models\CMS\CmsTag::class,
            Models\CMS\CmsArticle::class,
            Models\CMS\CmsComment::class,
        ],
        'company' => [
            Models\Company\CompanyPerson::class,
            Models\Company\CompanyEmployee::class,
            Models\Company\CompanyManager::class,
            Models\Company\CompanyOrganization::class,
            Models\Company\CompanyEvent::class,
            Models\Company\CompanyAuction::class,
            Models\Company\CompanyRaffle::class,
            Models\Company\CompanyCar::class,
            Models\Company\CompanyContract::class,
        ],
        'ecommerce' => [
            Models\ECommerce\ECommerceCart::class,
            Models\ECommerce\ECommerceCustomer::class,
            Models\ECommerce\ECommerceProduct::class,
            Models\ECommerce\ECommerceShipping::class,
            Models\ECommerce\ECommerceFeature::class,
            Models\ECommerce\ECommerceCategory::class,
        ],
        'generic' => [
            Models\Generic\BooleanModel::class,
            Models\Generic\DateTimeModel::class,
            Models\Generic\DecimalModel::class,
            Models\Generic\SerializationModel::class,
        ],
        'routing' => [
            Models\Routing\RoutingLeg::class,
            Models\Routing\RoutingLocation::class,
            Models\Routing\RoutingRoute::class,
            Models\Routing\RoutingRouteBooking::class,
        ],
        'navigation' => [
            Models\Navigation\NavUser::class,
            Models\Navigation\NavCountry::class,
            Models\Navigation\NavPhotos::class,
            Models\Navigation\NavTour::class,
            Models\Navigation\NavPointOfInterest::class,
        ],
        'directorytree' => [
            Models\DirectoryTree\AbstractContentItem::class,
            Models\DirectoryTree\File::class,
            Models\DirectoryTree\Directory::class,
        ],
        'ddc117' => [
            Models\DDC117\DDC117Article::class,
            Models\DDC117\DDC117Reference::class,
            Models\DDC117\DDC117Translation::class,
            Models\DDC117\DDC117ArticleDetails::class,
            Models\DDC117\DDC117ApproveChanges::class,
            Models\DDC117\DDC117Editor::class,
            Models\DDC117\DDC117Link::class,
        ],
        'ddc3699' => [
            Models\DDC3699\DDC3699Parent::class,
            Models\DDC3699\DDC3699RelationOne::class,
            Models\DDC3699\DDC3699RelationMany::class,
            Models\DDC3699\DDC3699Child::class,
        ],
        'stockexchange' => [
            Models\StockExchange\Bond::class,
            Models\StockExchange\Stock::class,
            Models\StockExchange\Market::class,
        ],
        'legacy' => [
            Models\Legacy\LegacyUser::class,
            Models\Legacy\LegacyUserReference::class,
            Models\Legacy\LegacyArticle::class,
            Models\Legacy\LegacyCar::class,
        ],
        'customtype' => [
            Models\CustomType\CustomTypeChild::class,
            Models\CustomType\CustomTypeParent::class,
            Models\CustomType\CustomTypeUpperCase::class,
        ],
        'compositekeyinheritance' => [
            Models\CompositeKeyInheritance\JoinedRootClass::class,
            Models\CompositeKeyInheritance\JoinedChildClass::class,
            Models\CompositeKeyInheritance\SingleRootClass::class,
            Models\CompositeKeyInheritance\SingleChildClass::class,
        ],
        'taxi' => [
            Models\Taxi\PaidRide::class,
            Models\Taxi\Ride::class,
            Models\Taxi\Car::class,
            Models\Taxi\Driver::class,
        ],
        'cache' => [
            Models\Cache\Country::class,
            Models\Cache\State::class,
            Models\Cache\City::class,
            Models\Cache\Traveler::class,
            Models\Cache\TravelerProfileInfo::class,
            Models\Cache\TravelerProfile::class,
            Models\Cache\Travel::class,
            Models\Cache\Attraction::class,
            Models\Cache\Restaurant::class,
            Models\Cache\Beach::class,
            Models\Cache\Bar::class,
            Models\Cache\Flight::class,
            Models\Cache\Token::class,
            Models\Cache\Login::class,
            Models\Cache\Client::class,
            Models\Cache\Person::class,
            Models\Cache\Address::class,
            Models\Cache\Action::class,
            Models\Cache\ComplexAction::class,
            Models\Cache\AttractionInfo::class,
            Models\Cache\AttractionContactInfo::class,
            Models\Cache\AttractionLocationInfo::class,
        ],
        'tweet' => [
            Models\Tweet\User::class,
            Models\Tweet\Tweet::class,
            Models\Tweet\UserList::class,
        ],
        'ddc2504' => [
            Models\DDC2504\DDC2504RootClass::class,
            Models\DDC2504\DDC2504ChildClass::class,
            Models\DDC2504\DDC2504OtherClass::class,
        ],
        'ddc3346' => [
            Models\DDC3346\DDC3346Author::class,
            Models\DDC3346\DDC3346Article::class,
        ],
        'quote' => [
            Models\Quote\Address::class,
            Models\Quote\City::class,
            Models\Quote\FullAddress::class,
            Models\Quote\Group::class,
            Models\Quote\NumericEntity::class,
            Models\Quote\Phone::class,
            Models\Quote\User::class,
        ],
        'vct_onetoone' => [
            Models\ValueConversionType\InversedOneToOneEntity::class,
            Models\ValueConversionType\OwningOneToOneEntity::class,
        ],
        'vct_onetoone_compositeid' => [
            Models\ValueConversionType\InversedOneToOneCompositeIdEntity::class,
            Models\ValueConversionType\OwningOneToOneCompositeIdEntity::class,
        ],
        'vct_onetoone_compositeid_foreignkey' => [
            Models\ValueConversionType\AuxiliaryEntity::class,
            Models\ValueConversionType\InversedOneToOneCompositeIdForeignKeyEntity::class,
            Models\ValueConversionType\OwningOneToOneCompositeIdForeignKeyEntity::class,
        ],
        'vct_onetomany' => [
            Models\ValueConversionType\InversedOneToManyEntity::class,
            Models\ValueConversionType\OwningManyToOneEntity::class,
        ],
        'vct_onetomany_compositeid' => [
            Models\ValueConversionType\InversedOneToManyCompositeIdEntity::class,
            Models\ValueConversionType\OwningManyToOneCompositeIdEntity::class,
        ],
        'vct_onetomany_compositeid_foreignkey' => [
            Models\ValueConversionType\AuxiliaryEntity::class,
            Models\ValueConversionType\InversedOneToManyCompositeIdForeignKeyEntity::class,
            Models\ValueConversionType\OwningManyToOneCompositeIdForeignKeyEntity::class,
        ],
        'vct_onetomany_extralazy' => [
            Models\ValueConversionType\InversedOneToManyExtraLazyEntity::class,
            Models\ValueConversionType\OwningManyToOneExtraLazyEntity::class,
        ],
        'vct_manytomany' => [
            Models\ValueConversionType\InversedManyToManyEntity::class,
            Models\ValueConversionType\OwningManyToManyEntity::class,
        ],
        'vct_manytomany_compositeid' => [
            Models\ValueConversionType\InversedManyToManyCompositeIdEntity::class,
            Models\ValueConversionType\OwningManyToManyCompositeIdEntity::class,
        ],
        'vct_manytomany_compositeid_foreignkey' => [
            Models\ValueConversionType\AuxiliaryEntity::class,
            Models\ValueConversionType\InversedManyToManyCompositeIdForeignKeyEntity::class,
            Models\ValueConversionType\OwningManyToManyCompositeIdForeignKeyEntity::class,
        ],
        'vct_manytomany_extralazy' => [
            Models\ValueConversionType\InversedManyToManyExtraLazyEntity::class,
            Models\ValueConversionType\OwningManyToManyExtraLazyEntity::class,
        ],
        'geonames' => [
            Models\GeoNames\Country::class,
            Models\GeoNames\Admin1::class,
            Models\GeoNames\Admin1AlternateName::class,
            Models\GeoNames\City::class,
        ],
        'custom_id_object_type' => [
            Models\CustomType\CustomIdObjectTypeParent::class,
            Models\CustomType\CustomIdObjectTypeChild::class,
        ],
        'pagination' => [
            Models\Pagination\Company::class,
            Models\Pagination\Logo::class,
            Models\Pagination\Department::class,
            Models\Pagination\User::class,
            Models\Pagination\User1::class,
        ],
        'versioned_many_to_one' => [
            Models\VersionedManyToOne\Category::class,
            Models\VersionedManyToOne\Article::class,
        ],
        'issue5989' => [
            Models\Issue5989\Issue5989Person::class,
            Models\Issue5989\Issue5989Employee::class,
            Models\Issue5989\Issue5989Manager::class,
        ],
    ];

    /**
     * @param string $setName
     *
     */
    protected function useModelSet($setName)
    {
        $this->usedModelSets[$setName] = true;
    }

    /**
     * Sweeps the database tables and clears the EntityManager.
     *
     */
    protected function tearDown() : void
    {
        $conn = static::$sharedConn;

        // In case test is skipped, tearDown is called, but no setup may have run
        if (! $conn) {
            return;
        }

        $platform = $conn->getDatabasePlatform();

        $this->sqlLoggerStack->enabled = false;

        if (isset($this->usedModelSets['cms'])) {
            $conn->executeUpdate('DELETE FROM cms_users_groups');
            $conn->executeUpdate('DELETE FROM cms_groups');
            $conn->executeUpdate('DELETE FROM cms_users_tags');
            $conn->executeUpdate('DELETE FROM cms_tags');
            $conn->executeUpdate('DELETE FROM cms_addresses');
            $conn->executeUpdate('DELETE FROM cms_phonenumbers');
            $conn->executeUpdate('DELETE FROM cms_comments');
            $conn->executeUpdate('DELETE FROM cms_articles');
            $conn->executeUpdate('DELETE FROM cms_users');
            $conn->executeUpdate('DELETE FROM cms_emails');
        }

        if (isset($this->usedModelSets['ecommerce'])) {
            $conn->executeUpdate('DELETE FROM ecommerce_carts_products');
            $conn->executeUpdate('DELETE FROM ecommerce_products_categories');
            $conn->executeUpdate('DELETE FROM ecommerce_products_related');
            $conn->executeUpdate('DELETE FROM ecommerce_carts');
            $conn->executeUpdate('DELETE FROM ecommerce_customers');
            $conn->executeUpdate('DELETE FROM ecommerce_features');
            $conn->executeUpdate('DELETE FROM ecommerce_products');
            $conn->executeUpdate('DELETE FROM ecommerce_shippings');
            $conn->executeUpdate('UPDATE ecommerce_categories SET parent_id = NULL');
            $conn->executeUpdate('DELETE FROM ecommerce_categories');
        }

        if (isset($this->usedModelSets['company'])) {
            $conn->executeUpdate('DELETE FROM company_contract_employees');
            $conn->executeUpdate('DELETE FROM company_contract_managers');
            $conn->executeUpdate('DELETE FROM company_contracts');
            $conn->executeUpdate('DELETE FROM company_persons_friends');
            $conn->executeUpdate('DELETE FROM company_managers');
            $conn->executeUpdate('DELETE FROM company_employees');
            $conn->executeUpdate('UPDATE company_persons SET spouse_id = NULL');
            $conn->executeUpdate('DELETE FROM company_persons');
            $conn->executeUpdate('DELETE FROM company_raffles');
            $conn->executeUpdate('DELETE FROM company_auctions');
            $conn->executeUpdate('UPDATE company_organizations SET main_event_id = NULL');
            $conn->executeUpdate('DELETE FROM company_events');
            $conn->executeUpdate('DELETE FROM company_organizations');
        }

        if (isset($this->usedModelSets['generic'])) {
            $conn->executeUpdate('DELETE FROM boolean_model');
            $conn->executeUpdate('DELETE FROM date_time_model');
            $conn->executeUpdate('DELETE FROM decimal_model');
            $conn->executeUpdate('DELETE FROM serialize_model');
        }

        if (isset($this->usedModelSets['routing'])) {
            $conn->executeUpdate('DELETE FROM RoutingRouteLegs');
            $conn->executeUpdate('DELETE FROM RoutingRouteBooking');
            $conn->executeUpdate('DELETE FROM RoutingRoute');
            $conn->executeUpdate('DELETE FROM RoutingLeg');
            $conn->executeUpdate('DELETE FROM RoutingLocation');
        }

        if (isset($this->usedModelSets['navigation'])) {
            $conn->executeUpdate('DELETE FROM navigation_tour_pois');
            $conn->executeUpdate('DELETE FROM navigation_photos');
            $conn->executeUpdate('DELETE FROM navigation_pois');
            $conn->executeUpdate('DELETE FROM navigation_tours');
            $conn->executeUpdate('DELETE FROM navigation_countries');
        }

        if (isset($this->usedModelSets['directorytree'])) {
            $conn->executeUpdate('DELETE FROM ' . $platform->quoteIdentifier('file'));
            // MySQL doesn't know deferred deletions therefore only executing the second query gives errors.
            $conn->executeUpdate('DELETE FROM Directory WHERE parentDirectory_id IS NOT NULL');
            $conn->executeUpdate('DELETE FROM Directory');
        }

        if (isset($this->usedModelSets['ddc117'])) {
            $conn->executeUpdate('DELETE FROM ddc117editor_ddc117translation');
            $conn->executeUpdate('DELETE FROM DDC117Editor');
            $conn->executeUpdate('DELETE FROM DDC117ApproveChanges');
            $conn->executeUpdate('DELETE FROM DDC117Link');
            $conn->executeUpdate('DELETE FROM DDC117Reference');
            $conn->executeUpdate('DELETE FROM DDC117ArticleDetails');
            $conn->executeUpdate('DELETE FROM DDC117Translation');
            $conn->executeUpdate('DELETE FROM DDC117Article');
        }

        if (isset($this->usedModelSets['stockexchange'])) {
            $conn->executeUpdate('DELETE FROM exchange_bonds_stocks');
            $conn->executeUpdate('DELETE FROM exchange_bonds');
            $conn->executeUpdate('DELETE FROM exchange_stocks');
            $conn->executeUpdate('DELETE FROM exchange_markets');
        }

        if (isset($this->usedModelSets['legacy'])) {
            $conn->executeUpdate('DELETE FROM legacy_users_cars');
            $conn->executeUpdate('DELETE FROM legacy_users_reference');
            $conn->executeUpdate('DELETE FROM legacy_articles');
            $conn->executeUpdate('DELETE FROM legacy_cars');
            $conn->executeUpdate('DELETE FROM legacy_users');
        }

        if (isset($this->usedModelSets['customtype'])) {
            $conn->executeUpdate('DELETE FROM customtype_parent_friends');
            $conn->executeUpdate('DELETE FROM customtype_parents');
            $conn->executeUpdate('DELETE FROM customtype_children');
            $conn->executeUpdate('DELETE FROM customtype_uppercases');
        }

        if (isset($this->usedModelSets['compositekeyinheritance'])) {
            $conn->executeUpdate('DELETE FROM JoinedChildClass');
            $conn->executeUpdate('DELETE FROM JoinedRootClass');
            $conn->executeUpdate('DELETE FROM SingleRootClass');
        }

        if (isset($this->usedModelSets['taxi'])) {
            $conn->executeUpdate('DELETE FROM taxi_paid_ride');
            $conn->executeUpdate('DELETE FROM taxi_ride');
            $conn->executeUpdate('DELETE FROM taxi_car');
            $conn->executeUpdate('DELETE FROM taxi_driver');
        }

        if (isset($this->usedModelSets['tweet'])) {
            $conn->executeUpdate('DELETE FROM tweet_tweet');
            $conn->executeUpdate('DELETE FROM tweet_user_list');
            $conn->executeUpdate('DELETE FROM tweet_user');
        }

        if (isset($this->usedModelSets['cache'])) {
            $conn->executeUpdate('DELETE FROM cache_attraction_location_info');
            $conn->executeUpdate('DELETE FROM cache_attraction_contact_info');
            $conn->executeUpdate('DELETE FROM cache_attraction_info');
            $conn->executeUpdate('DELETE FROM cache_visited_cities');
            $conn->executeUpdate('DELETE FROM cache_flight');
            $conn->executeUpdate('DELETE FROM cache_attraction');
            $conn->executeUpdate('DELETE FROM cache_travel');
            $conn->executeUpdate('DELETE FROM cache_traveler');
            $conn->executeUpdate('DELETE FROM cache_traveler_profile_info');
            $conn->executeUpdate('DELETE FROM cache_traveler_profile');
            $conn->executeUpdate('DELETE FROM cache_city');
            $conn->executeUpdate('DELETE FROM cache_state');
            $conn->executeUpdate('DELETE FROM cache_country');
            $conn->executeUpdate('DELETE FROM cache_login');
            $conn->executeUpdate('DELETE FROM cache_token');
            $conn->executeUpdate('DELETE FROM cache_complex_action');
            $conn->executeUpdate('DELETE FROM cache_action');
            $conn->executeUpdate('DELETE FROM cache_client');
        }

        if (isset($this->usedModelSets['ddc3346'])) {
            $conn->executeUpdate('DELETE FROM ddc3346_articles');
            $conn->executeUpdate('DELETE FROM ddc3346_users');
        }

        if (isset($this->usedModelSets['quote'])) {
            $conn->executeUpdate(
                sprintf(
                    'UPDATE %s SET %s = NULL',
                    $platform->quoteIdentifier('quote-address'),
                    $platform->quoteIdentifier('user-id')
                )
            );

            $conn->executeUpdate('DELETE FROM ' . $platform->quoteIdentifier('quote-users-groups'));
            $conn->executeUpdate('DELETE FROM ' . $platform->quoteIdentifier('quote-group'));
            $conn->executeUpdate('DELETE FROM ' . $platform->quoteIdentifier('quote-phone'));
            $conn->executeUpdate('DELETE FROM ' . $platform->quoteIdentifier('quote-user'));
            $conn->executeUpdate('DELETE FROM ' . $platform->quoteIdentifier('quote-address'));
            $conn->executeUpdate('DELETE FROM ' . $platform->quoteIdentifier('quote-city'));
        }

        if (isset($this->usedModelSets['vct_onetoone'])) {
            $conn->executeUpdate('DELETE FROM vct_owning_onetoone');
            $conn->executeUpdate('DELETE FROM vct_inversed_onetoone');
        }

        if (isset($this->usedModelSets['vct_onetoone_compositeid'])) {
            $conn->executeUpdate('DELETE FROM vct_owning_onetoone_compositeid');
            $conn->executeUpdate('DELETE FROM vct_inversed_onetoone_compositeid');
        }

        if (isset($this->usedModelSets['vct_onetoone_compositeid_foreignkey'])) {
            $conn->executeUpdate('DELETE FROM vct_owning_onetoone_compositeid_foreignkey');
            $conn->executeUpdate('DELETE FROM vct_inversed_onetoone_compositeid_foreignkey');
            $conn->executeUpdate('DELETE FROM vct_auxiliary');
        }

        if (isset($this->usedModelSets['vct_onetomany'])) {
            $conn->executeUpdate('DELETE FROM vct_owning_manytoone');
            $conn->executeUpdate('DELETE FROM vct_inversed_onetomany');
        }

        if (isset($this->usedModelSets['vct_onetomany_compositeid'])) {
            $conn->executeUpdate('DELETE FROM vct_owning_manytoone_compositeid');
            $conn->executeUpdate('DELETE FROM vct_inversed_onetomany_compositeid');
        }

        if (isset($this->usedModelSets['vct_onetomany_compositeid_foreignkey'])) {
            $conn->executeUpdate('DELETE FROM vct_owning_manytoone_compositeid_foreignkey');
            $conn->executeUpdate('DELETE FROM vct_inversed_onetomany_compositeid_foreignkey');
            $conn->executeUpdate('DELETE FROM vct_auxiliary');
        }

        if (isset($this->usedModelSets['vct_onetomany_extralazy'])) {
            $conn->executeUpdate('DELETE FROM vct_owning_manytoone_extralazy');
            $conn->executeUpdate('DELETE FROM vct_inversed_onetomany_extralazy');
        }

        if (isset($this->usedModelSets['vct_manytomany'])) {
            $conn->executeUpdate('DELETE FROM vct_xref_manytomany');
            $conn->executeUpdate('DELETE FROM vct_owning_manytomany');
            $conn->executeUpdate('DELETE FROM vct_inversed_manytomany');
        }

        if (isset($this->usedModelSets['vct_manytomany_compositeid'])) {
            $conn->executeUpdate('DELETE FROM vct_xref_manytomany_compositeid');
            $conn->executeUpdate('DELETE FROM vct_owning_manytomany_compositeid');
            $conn->executeUpdate('DELETE FROM vct_inversed_manytomany_compositeid');
        }

        if (isset($this->usedModelSets['vct_manytomany_compositeid_foreignkey'])) {
            $conn->executeUpdate('DELETE FROM vct_xref_manytomany_compositeid_foreignkey');
            $conn->executeUpdate('DELETE FROM vct_owning_manytomany_compositeid_foreignkey');
            $conn->executeUpdate('DELETE FROM vct_inversed_manytomany_compositeid_foreignkey');
            $conn->executeUpdate('DELETE FROM vct_auxiliary');
        }

        if (isset($this->usedModelSets['vct_manytomany_extralazy'])) {
            $conn->executeUpdate('DELETE FROM vct_xref_manytomany_extralazy');
            $conn->executeUpdate('DELETE FROM vct_owning_manytomany_extralazy');
            $conn->executeUpdate('DELETE FROM vct_inversed_manytomany_extralazy');
        }

        if (isset($this->usedModelSets['geonames'])) {
            $conn->executeUpdate('DELETE FROM geonames_admin1_alternate_name');
            $conn->executeUpdate('DELETE FROM geonames_admin1');
            $conn->executeUpdate('DELETE FROM geonames_city');
            $conn->executeUpdate('DELETE FROM geonames_country');
        }

        if (isset($this->usedModelSets['custom_id_object_type'])) {
            $conn->executeUpdate('DELETE FROM custom_id_type_child');
            $conn->executeUpdate('DELETE FROM custom_id_type_parent');
        }

        if (isset($this->usedModelSets['pagination'])) {
            $conn->executeUpdate('DELETE FROM pagination_logo');
            $conn->executeUpdate('DELETE FROM pagination_department');
            $conn->executeUpdate('DELETE FROM pagination_company');
            $conn->executeUpdate('DELETE FROM pagination_user');
        }

        if (isset($this->usedModelSets['versioned_many_to_one'])) {
            $conn->executeUpdate('DELETE FROM versioned_many_to_one_article');
            $conn->executeUpdate('DELETE FROM versioned_many_to_one_category');
        }

        if (isset($this->usedModelSets['issue5989'])) {
            $conn->executeUpdate('DELETE FROM issue5989_persons');
            $conn->executeUpdate('DELETE FROM issue5989_employees');
            $conn->executeUpdate('DELETE FROM issue5989_managers');
        }

        $this->em->clear();
    }

    /**
     * @param array $classNames
     *
     *
     * @throws \RuntimeException
     */
    protected function setUpEntitySchema(array $classNames)
    {
        if ($this->em === null) {
            throw new \RuntimeException('EntityManager not set, you have to call parent::setUp() before invoking this method.');
        }

        $classes = [];

        foreach ($classNames as $className) {
            if (! isset(static::$entityTablesCreated[$className])) {
                static::$entityTablesCreated[$className] = true;
                $classes[]                               = $this->em->getClassMetadata($className);
            }
        }

        if ($classes) {
            $this->schemaTool->createSchema($classes);
        }
    }

    /**
     * Creates a connection to the test database, if there is none yet, and
     * creates the necessary tables.
     *
     */
    protected function setUp() : void
    {
        $this->setUpDBALTypes();

        if (! isset(static::$sharedConn)) {
            static::$sharedConn = TestUtil::getConnection();
        }

        if (isset($GLOBALS['DOCTRINE_MARK_SQL_LOGS'])) {
            if (in_array(static::$sharedConn->getDatabasePlatform()->getName(), ['mysql', 'postgresql'], true)) {
                static::$sharedConn->executeQuery('SELECT 1 /*' . get_class($this) . '*/');
            } elseif (static::$sharedConn->getDatabasePlatform()->getName() === 'oracle') {
                static::$sharedConn->executeQuery('SELECT 1 /*' . get_class($this) . '*/ FROM dual');
            }
        }

        if (! $this->em) {
            $this->em         = $this->getEntityManager();
            $this->schemaTool = new SchemaTool($this->em);
        }

        foreach ($this->usedModelSets as $setName => $bool) {
            if (! isset(static::$tablesCreated[$setName])) {
                $this->setUpEntitySchema(static::$modelSets[$setName]);

                static::$tablesCreated[$setName] = true;
            }
        }

        $this->sqlLoggerStack->enabled = true;
    }

    /**
     * Gets an EntityManager for testing purposes.
     *
     * @return EntityManagerInterface
     *
     * @throws ORMException
     */
    protected function getEntityManager(?Connection $connection = null, ?MappingDriver $mappingDriver = null)
    {
        // NOTE: Functional tests use their own shared metadata cache, because
        // the actual database platform used during execution has effect on some
        // metadata mapping behaviors (like the choice of the ID generation).
        if (self::$metadataCacheImpl === null) {
            if (isset($GLOBALS['DOCTRINE_CACHE_IMPL'])) {
                self::$metadataCacheImpl = new $GLOBALS['DOCTRINE_CACHE_IMPL']();
            } else {
                self::$metadataCacheImpl = new ArrayCache();
            }
        }

        if (self::$queryCacheImpl === null) {
            self::$queryCacheImpl = new ArrayCache();
        }

        $this->sqlLoggerStack          = new DebugStack();
        $this->sqlLoggerStack->enabled = false;

        //FIXME: two different configs! $conn and the created entity manager have
        // different configs.
        $config = new Configuration();

        $config->setMetadataCacheImpl(self::$metadataCacheImpl);
        $config->setQueryCacheImpl(self::$queryCacheImpl);
        $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        $config->setProxyNamespace('Doctrine\Tests\Proxies');

        if ($this->resultCacheImpl !== null) {
            $config->setResultCacheImpl($this->resultCacheImpl);
        }

        $enableSecondLevelCache = getenv('ENABLE_SECOND_LEVEL_CACHE');

        if ($this->isSecondLevelCacheEnabled || $enableSecondLevelCache) {
            $cacheConfig = new CacheConfiguration();
            $cache       = $this->getSharedSecondLevelCacheDriverImpl();
            $factory     = new DefaultCacheFactory($cacheConfig->getRegionsConfiguration(), $cache);

            $this->secondLevelCacheFactory = $factory;

            if ($this->isSecondLevelCacheLogEnabled) {
                $this->secondLevelCacheLogger = new StatisticsCacheLogger();
                $cacheConfig->setCacheLogger($this->secondLevelCacheLogger);
            }

            $cacheConfig->setCacheFactory($factory);
            $config->setSecondLevelCacheEnabled(true);
            $config->setSecondLevelCacheConfiguration($cacheConfig);

            $this->isSecondLevelCacheEnabled = true;
        }

        $conn = $connection ?: static::$sharedConn;

        $config->setMetadataDriverImpl(
            $mappingDriver ?? $config->newDefaultAnnotationDriver([
                realpath(__DIR__ . '/Models/CMS'),
                realpath(__DIR__ . '/Models/Cache'),

                realpath(__DIR__ . '/Models/Company'),
                realpath(__DIR__ . '/Models/CompositeKeyInheritance'),
                //realpath(__DIR__ . '/Models/CustomType'),
                realpath(__DIR__ . '/Models/DDC117'),
                realpath(__DIR__ . '/Models/DDC1476'),
                realpath(__DIR__ . '/Models/DDC1590'),
                realpath(__DIR__ . '/Models/DDC1872'),
                //realpath(__DIR__ . '/Models/DDC2372'),
                realpath(__DIR__ . '/Models/DDC2504'),
                realpath(__DIR__ . '/Models/DDC2825'),
                realpath(__DIR__ . '/Models/DDC3231'),
                realpath(__DIR__ . '/Models/DDC3293'),
                realpath(__DIR__ . '/Models/DDC3346'),
                realpath(__DIR__ . '/Models/DDC3579'),
                realpath(__DIR__ . '/Models/DDC3597'),
                realpath(__DIR__ . '/Models/DDC3699'),
                realpath(__DIR__ . '/Models/DDC3711'),
                realpath(__DIR__ . '/Models/DDC3899'),
                //realpath(__DIR__ . '/Models/DDC4006'),
                //realpath(__DIR__ . '/Models/DDC5934'),
                realpath(__DIR__ . '/Models/DDC6412'),
                realpath(__DIR__ . '/Models/DDC753'),
                realpath(__DIR__ . '/Models/DDC869'),
                //realpath(__DIR__ . '/Models/DDC889'),
                realpath(__DIR__ . '/Models/DDC964'),
                realpath(__DIR__ . '/Models/DirectoryTree'),
                realpath(__DIR__ . '/Models/ECommerce'),
                realpath(__DIR__ . '/Models/Forum'),
                realpath(__DIR__ . '/Models/FriendObject'),
                realpath(__DIR__ . '/Models/Generic'),
                realpath(__DIR__ . '/Models/GeoNames'),
                realpath(__DIR__ . '/Models/Global'),
                realpath(__DIR__ . '/Models/Hydration'),
                realpath(__DIR__ . '/Models/IdentityIsAssociation'),
                realpath(__DIR__ . '/Models/Issue5989'),
                realpath(__DIR__ . '/Models/JoinedInheritanceType'),
                realpath(__DIR__ . '/Models/Legacy'),
                realpath(__DIR__ . '/Models/ManyToManyPersister'),
                realpath(__DIR__ . '/Models/MixedToOneIdentity'),
                realpath(__DIR__ . '/Models/Navigation'),
                realpath(__DIR__ . '/Models/NonPublicSchemaJoins'),
                realpath(__DIR__ . '/Models/NullDefault'),
                realpath(__DIR__ . '/Models/OneToOneInverseSideLoad'),
                realpath(__DIR__ . '/Models/OneToOneSingleTableInheritance'),
                realpath(__DIR__ . '/Models/Pagination'),
                realpath(__DIR__ . '/Models/ProxySpecifics'),
                realpath(__DIR__ . '/Models/Quote'),
                realpath(__DIR__ . '/Models/Reflection'),
                realpath(__DIR__ . '/Models/Routing'),
                realpath(__DIR__ . '/Models/StockExchange'),
                realpath(__DIR__ . '/Models/Taxi'),
                realpath(__DIR__ . '/Models/Tweet'),
                realpath(__DIR__ . '/Models/ValueConversionType'),
                realpath(__DIR__ . '/Models/ValueObjects'),
                realpath(__DIR__ . '/Models/VersionedManyToOne'),
                realpath(__DIR__ . '/Models/VersionedOneToOne'),

            ])
        );

        $conn->getConfiguration()->setSQLLogger($this->sqlLoggerStack);

        // get rid of more global state
        $evm = $conn->getEventManager();

        foreach ($evm->getListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $evm->removeEventListener([$event], $listener);
            }
        }

        if ($enableSecondLevelCache) {
            $evm->addEventListener('loadClassMetadata', new CacheMetadataListener());
        }

        if (isset($GLOBALS['db_event_subscribers'])) {
            foreach (explode(',', $GLOBALS['db_event_subscribers']) as $subscriberClass) {
                $subscriberInstance = new $subscriberClass();
                $evm->addEventSubscriber($subscriberInstance);
            }
        }

        if (isset($GLOBALS['debug_uow_listener'])) {
            $evm->addEventListener(['onFlush'], new DebugUnitOfWorkListener());
        }

        return EntityManager::create($conn, $config, $conn->getEventManager());
    }

    /**
     *
     *
     * @throws \Throwable
     */
    protected function onNotSuccessfulTest(\Throwable $e)
    {
        if ($e instanceof AssertionFailedError) {
            throw $e;
        }

        if (isset($this->sqlLoggerStack->queries) && count($this->sqlLoggerStack->queries)) {
            $queries       = '';
            $last25queries = array_slice(array_reverse($this->sqlLoggerStack->queries, true), 0, 25, true);

            foreach ($last25queries as $i => $query) {
                $params = array_map(
                    function ($p) {
                        return is_object($p) ? get_class($p) : var_export($p, true);
                    },
                    $query['params'] ?: []
                );

                $queries .= $i . ". SQL: '" . $query['sql'] . "' Params: " . implode(', ', $params) . PHP_EOL;
            }

            $trace    = $e->getTrace();
            $traceMsg = '';

            foreach ($trace as $part) {
                if (isset($part['file'])) {
                    if (strpos($part['file'], 'PHPUnit/') !== false) {
                        // Beginning with PHPUnit files we don't print the trace anymore.
                        break;
                    }

                    $traceMsg .= $part['file'] . ':' . $part['line'] . PHP_EOL;
                }
            }

            $message = '[' . get_class($e) . '] ' . $e->getMessage() . PHP_EOL . PHP_EOL . 'With queries:' . PHP_EOL . $queries . PHP_EOL . 'Trace:' . PHP_EOL . $traceMsg;

            throw new \Exception($message, (int) $e->getCode(), $e);
        }

        throw $e;
    }

    public static function assertSQLEquals($expectedSql, $actualSql)
    {
        self::assertEquals(
            strtolower((string) $expectedSql),
            strtolower((string) $actualSql),
            'Lowercase comparison of SQL statements failed.'
        );
    }

    /**
     * Using the SQL Logger Stack this method retrieves the current query count executed in this test.
     *
     * @return int
     */
    protected function getCurrentQueryCount()
    {
        return count($this->sqlLoggerStack->queries);
    }

    /**
     * Configures DBAL types required in tests
     */
    protected function setUpDBALTypes()
    {
        if (Type::hasType('rot13')) {
            Type::overrideType('rot13', Rot13Type::class);
        } else {
            Type::addType('rot13', Rot13Type::class);
        }
    }
}
