<?php

declare(strict_types=1);

namespace Doctrine\Tests;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\Exception\DatabaseObjectNotFoundException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\NamedObject;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\Logging\StatisticsCacheLogger;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\DebugUnitOfWorkListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolsException;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Tests\DbalExtensions\Connection;
use Doctrine\Tests\DbalExtensions\QueryLog;
use Doctrine\Tests\DbalTypes\Rot13Type;
use Doctrine\Tests\EventListener\CacheMetadataListener;
use Doctrine\Tests\Mocks\AttributeDriverFactory;
use Doctrine\Tests\Models\Cache\Action;
use Doctrine\Tests\Models\Cache\Address;
use Doctrine\Tests\Models\Cache\Attraction;
use Doctrine\Tests\Models\Cache\AttractionContactInfo;
use Doctrine\Tests\Models\Cache\AttractionInfo;
use Doctrine\Tests\Models\Cache\AttractionLocationInfo;
use Doctrine\Tests\Models\Cache\Bar;
use Doctrine\Tests\Models\Cache\Beach;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\Cache\Client;
use Doctrine\Tests\Models\Cache\ComplexAction;
use Doctrine\Tests\Models\Cache\Country;
use Doctrine\Tests\Models\Cache\Flight;
use Doctrine\Tests\Models\Cache\Login;
use Doctrine\Tests\Models\Cache\Person;
use Doctrine\Tests\Models\Cache\Restaurant;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\Models\Cache\Token;
use Doctrine\Tests\Models\Cache\Travel;
use Doctrine\Tests\Models\Cache\Traveler;
use Doctrine\Tests\Models\Cache\TravelerProfile;
use Doctrine\Tests\Models\Cache\TravelerProfileInfo;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsComment;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsTag;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Company\CompanyAuction;
use Doctrine\Tests\Models\Company\CompanyCar;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\Company\CompanyEmployee;
use Doctrine\Tests\Models\Company\CompanyEvent;
use Doctrine\Tests\Models\Company\CompanyManager;
use Doctrine\Tests\Models\Company\CompanyOrganization;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\Models\Company\CompanyRaffle;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedChildClass;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedRootClass;
use Doctrine\Tests\Models\CompositeKeyInheritance\SingleChildClass;
use Doctrine\Tests\Models\CompositeKeyInheritance\SingleRootClass;
use Doctrine\Tests\Models\CustomType\CustomIdObjectTypeChild;
use Doctrine\Tests\Models\CustomType\CustomIdObjectTypeParent;
use Doctrine\Tests\Models\CustomType\CustomTypeChild;
use Doctrine\Tests\Models\CustomType\CustomTypeParent;
use Doctrine\Tests\Models\CustomType\CustomTypeUpperCase;
use Doctrine\Tests\Models\DDC117\DDC117ApproveChanges;
use Doctrine\Tests\Models\DDC117\DDC117Article;
use Doctrine\Tests\Models\DDC117\DDC117ArticleDetails;
use Doctrine\Tests\Models\DDC117\DDC117Editor;
use Doctrine\Tests\Models\DDC117\DDC117Link;
use Doctrine\Tests\Models\DDC117\DDC117Reference;
use Doctrine\Tests\Models\DDC117\DDC117Translation;
use Doctrine\Tests\Models\DDC2504\DDC2504ChildClass;
use Doctrine\Tests\Models\DDC2504\DDC2504OtherClass;
use Doctrine\Tests\Models\DDC2504\DDC2504RootClass;
use Doctrine\Tests\Models\DDC3346\DDC3346Article;
use Doctrine\Tests\Models\DDC3346\DDC3346Author;
use Doctrine\Tests\Models\DDC3699\DDC3699Child;
use Doctrine\Tests\Models\DDC3699\DDC3699Parent;
use Doctrine\Tests\Models\DDC3699\DDC3699RelationMany;
use Doctrine\Tests\Models\DDC3699\DDC3699RelationOne;
use Doctrine\Tests\Models\DirectoryTree\AbstractContentItem;
use Doctrine\Tests\Models\DirectoryTree\Directory;
use Doctrine\Tests\Models\DirectoryTree\File;
use Doctrine\Tests\Models\ECommerce\ECommerceCart;
use Doctrine\Tests\Models\ECommerce\ECommerceCategory;
use Doctrine\Tests\Models\ECommerce\ECommerceCustomer;
use Doctrine\Tests\Models\ECommerce\ECommerceFeature;
use Doctrine\Tests\Models\ECommerce\ECommerceProduct;
use Doctrine\Tests\Models\ECommerce\ECommerceShipping;
use Doctrine\Tests\Models\Generic\BooleanModel;
use Doctrine\Tests\Models\Generic\DateTimeModel;
use Doctrine\Tests\Models\Generic\DecimalModel;
use Doctrine\Tests\Models\GeoNames\Admin1;
use Doctrine\Tests\Models\GeoNames\Admin1AlternateName;
use Doctrine\Tests\Models\Issue5989\Issue5989Employee;
use Doctrine\Tests\Models\Issue5989\Issue5989Manager;
use Doctrine\Tests\Models\Issue5989\Issue5989Person;
use Doctrine\Tests\Models\Legacy\LegacyArticle;
use Doctrine\Tests\Models\Legacy\LegacyCar;
use Doctrine\Tests\Models\Legacy\LegacyUser;
use Doctrine\Tests\Models\Legacy\LegacyUserReference;
use Doctrine\Tests\Models\Navigation\NavCountry;
use Doctrine\Tests\Models\Navigation\NavPhotos;
use Doctrine\Tests\Models\Navigation\NavPointOfInterest;
use Doctrine\Tests\Models\Navigation\NavTour;
use Doctrine\Tests\Models\Navigation\NavUser;
use Doctrine\Tests\Models\Pagination\Company;
use Doctrine\Tests\Models\Pagination\Department;
use Doctrine\Tests\Models\Pagination\Logo;
use Doctrine\Tests\Models\Pagination\User1;
use Doctrine\Tests\Models\Quote\FullAddress;
use Doctrine\Tests\Models\Quote\Group;
use Doctrine\Tests\Models\Quote\NumericEntity;
use Doctrine\Tests\Models\Quote\Phone;
use Doctrine\Tests\Models\Routing\RoutingLeg;
use Doctrine\Tests\Models\Routing\RoutingLocation;
use Doctrine\Tests\Models\Routing\RoutingRoute;
use Doctrine\Tests\Models\Routing\RoutingRouteBooking;
use Doctrine\Tests\Models\StockExchange\Bond;
use Doctrine\Tests\Models\StockExchange\Market;
use Doctrine\Tests\Models\StockExchange\Stock;
use Doctrine\Tests\Models\Taxi\Car;
use Doctrine\Tests\Models\Taxi\Driver;
use Doctrine\Tests\Models\Taxi\PaidRide;
use Doctrine\Tests\Models\Taxi\Ride;
use Doctrine\Tests\Models\Tweet\Tweet;
use Doctrine\Tests\Models\Tweet\User;
use Doctrine\Tests\Models\Tweet\UserList;
use Doctrine\Tests\Models\ValueConversionType\AuxiliaryEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedManyToManyCompositeIdEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedManyToManyCompositeIdForeignKeyEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedManyToManyEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedManyToManyExtraLazyEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedOneToManyCompositeIdEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedOneToManyCompositeIdForeignKeyEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedOneToManyEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedOneToManyExtraLazyEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedOneToOneCompositeIdEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedOneToOneCompositeIdForeignKeyEntity;
use Doctrine\Tests\Models\ValueConversionType\InversedOneToOneEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToManyCompositeIdEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToManyCompositeIdForeignKeyEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToManyEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToManyExtraLazyEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToOneCompositeIdEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToOneCompositeIdForeignKeyEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToOneEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToOneExtraLazyEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningOneToOneCompositeIdEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningOneToOneCompositeIdForeignKeyEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningOneToOneEntity;
use Doctrine\Tests\Models\VersionedManyToOne\Article;
use Doctrine\Tests\Models\VersionedManyToOne\Category;
use Exception;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Constraint\Count;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Throwable;

use function array_map;
use function array_pop;
use function array_reverse;
use function array_slice;
use function assert;
use function explode;
use function get_debug_type;
use function implode;
use function is_object;
use function method_exists;
use function sprintf;
use function str_contains;
use function strtolower;
use function var_export;

use const PHP_EOL;

/**
 * Base testcase class for all functional ORM testcases.
 */
abstract class OrmFunctionalTestCase extends OrmTestCase
{
    /**
     * The metadata cache shared between all functional tests.
     *
     * @var CacheItemPoolInterface|null
     */
    private static $metadataCache = null;

    /**
     * The query cache shared between all functional tests.
     *
     * @var CacheItemPoolInterface|null
     */
    protected static $queryCache = null;

    /**
     * Shared connection when a TestCase is run alone (outside of its functional suite).
     *
     * @var DbalExtensions\Connection|null
     */
    protected static $sharedConn;

    /** @var EntityManagerInterface */
    protected $_em;

    /** @var SchemaTool */
    protected $_schemaTool;

    /**
     * The names of the model sets used in this testcase.
     *
     * @var array
     */
    protected $_usedModelSets = [];

    /**
     * To be configured by the test that uses result set cache
     *
     * @var CacheItemPoolInterface|null
     */
    protected $resultCache;

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
    protected static $_entityTablesCreated = [];

    /**
     * List of model sets and their classes.
     *
     * @var array
     */
    protected static $modelSets = [
        'cms' => [
            CmsUser::class,
            CmsPhonenumber::class,
            CmsAddress::class,
            CmsEmail::class,
            CmsGroup::class,
            CmsTag::class,
            CmsArticle::class,
            CmsComment::class,
        ],
        'company' => [
            CompanyPerson::class,
            CompanyEmployee::class,
            CompanyManager::class,
            CompanyOrganization::class,
            CompanyEvent::class,
            CompanyAuction::class,
            CompanyRaffle::class,
            CompanyCar::class,
            CompanyContract::class,
        ],
        'ecommerce' => [
            ECommerceCart::class,
            ECommerceCustomer::class,
            ECommerceProduct::class,
            ECommerceShipping::class,
            ECommerceFeature::class,
            ECommerceCategory::class,
        ],
        'generic' => [
            BooleanModel::class,
            DateTimeModel::class,
            DecimalModel::class,
        ],
        'routing' => [
            RoutingLeg::class,
            RoutingLocation::class,
            RoutingRoute::class,
            RoutingRouteBooking::class,
        ],
        'navigation' => [
            NavUser::class,
            NavCountry::class,
            NavPhotos::class,
            NavTour::class,
            NavPointOfInterest::class,
        ],
        'directorytree' => [
            AbstractContentItem::class,
            File::class,
            Directory::class,
        ],
        'ddc117' => [
            DDC117Article::class,
            DDC117Reference::class,
            DDC117Translation::class,
            DDC117ArticleDetails::class,
            DDC117ApproveChanges::class,
            DDC117Editor::class,
            DDC117Link::class,
        ],
        'ddc3699' => [
            DDC3699Parent::class,
            DDC3699RelationOne::class,
            DDC3699RelationMany::class,
            DDC3699Child::class,
        ],
        'stockexchange' => [
            Bond::class,
            Stock::class,
            Market::class,
        ],
        'legacy' => [
            LegacyUser::class,
            LegacyUserReference::class,
            LegacyArticle::class,
            LegacyCar::class,
        ],
        'customtype' => [
            CustomTypeChild::class,
            CustomTypeParent::class,
            CustomTypeUpperCase::class,
        ],
        'compositekeyinheritance' => [
            JoinedRootClass::class,
            JoinedChildClass::class,
            SingleRootClass::class,
            SingleChildClass::class,
        ],
        'compositekeyrelations' => [
            Models\CompositeKeyRelations\InvoiceClass::class,
            Models\CompositeKeyRelations\CustomerClass::class,
        ],
        'taxi' => [
            PaidRide::class,
            Ride::class,
            Car::class,
            Driver::class,
        ],
        'cache' => [
            Country::class,
            State::class,
            City::class,
            Traveler::class,
            TravelerProfileInfo::class,
            TravelerProfile::class,
            Travel::class,
            Attraction::class,
            Restaurant::class,
            Beach::class,
            Bar::class,
            Flight::class,
            Token::class,
            Login::class,
            Client::class,
            Person::class,
            Address::class,
            Action::class,
            ComplexAction::class,
            AttractionInfo::class,
            AttractionContactInfo::class,
            AttractionLocationInfo::class,
        ],
        'tweet' => [
            User::class,
            Tweet::class,
            UserList::class,
        ],
        'ddc2504' => [
            DDC2504RootClass::class,
            DDC2504ChildClass::class,
            DDC2504OtherClass::class,
        ],
        'ddc3346' => [
            DDC3346Author::class,
            DDC3346Article::class,
        ],
        'quote' => [
            Models\Quote\Address::class,
            Models\Quote\City::class,
            FullAddress::class,
            Group::class,
            NumericEntity::class,
            Phone::class,
            Models\Quote\User::class,
        ],
        'vct_onetoone' => [
            InversedOneToOneEntity::class,
            OwningOneToOneEntity::class,
        ],
        'vct_onetoone_compositeid' => [
            InversedOneToOneCompositeIdEntity::class,
            OwningOneToOneCompositeIdEntity::class,
        ],
        'vct_onetoone_compositeid_foreignkey' => [
            AuxiliaryEntity::class,
            InversedOneToOneCompositeIdForeignKeyEntity::class,
            OwningOneToOneCompositeIdForeignKeyEntity::class,
        ],
        'vct_onetomany' => [
            InversedOneToManyEntity::class,
            OwningManyToOneEntity::class,
        ],
        'vct_onetomany_compositeid' => [
            InversedOneToManyCompositeIdEntity::class,
            OwningManyToOneCompositeIdEntity::class,
        ],
        'vct_onetomany_compositeid_foreignkey' => [
            AuxiliaryEntity::class,
            InversedOneToManyCompositeIdForeignKeyEntity::class,
            OwningManyToOneCompositeIdForeignKeyEntity::class,
        ],
        'vct_onetomany_extralazy' => [
            InversedOneToManyExtraLazyEntity::class,
            OwningManyToOneExtraLazyEntity::class,
        ],
        'vct_manytomany' => [
            InversedManyToManyEntity::class,
            OwningManyToManyEntity::class,
        ],
        'vct_manytomany_compositeid' => [
            InversedManyToManyCompositeIdEntity::class,
            OwningManyToManyCompositeIdEntity::class,
        ],
        'vct_manytomany_compositeid_foreignkey' => [
            AuxiliaryEntity::class,
            InversedManyToManyCompositeIdForeignKeyEntity::class,
            OwningManyToManyCompositeIdForeignKeyEntity::class,
        ],
        'vct_manytomany_extralazy' => [
            InversedManyToManyExtraLazyEntity::class,
            OwningManyToManyExtraLazyEntity::class,
        ],
        'geonames' => [
            Models\GeoNames\Country::class,
            Admin1::class,
            Admin1AlternateName::class,
            Models\GeoNames\City::class,
        ],
        'custom_id_object_type' => [
            CustomIdObjectTypeParent::class,
            CustomIdObjectTypeChild::class,
        ],
        'pagination' => [
            Company::class,
            Logo::class,
            Department::class,
            Models\Pagination\User::class,
            User1::class,
        ],
        'versioned_many_to_one' => [
            Category::class,
            Article::class,
        ],
        'issue5989' => [
            Issue5989Person::class,
            Issue5989Employee::class,
            Issue5989Manager::class,
        ],
        'issue9300' => [
            Models\Issue9300\Issue9300Child::class,
            Models\Issue9300\Issue9300Parent::class,
        ],
    ];

    /** @param class-string ...$models */
    final protected function createSchemaForModels(string ...$models): void
    {
        try {
            $this->_schemaTool->createSchema($this->getMetadataForModels($models));
        } catch (ToolsException) {
        }
    }

    /**
     * @param class-string ...$models
     *
     * @return string[]
     */
    final protected function getUpdateSchemaSqlForModels(string ...$models): array
    {
        return $this->_schemaTool->getUpdateSchemaSql($this->getMetadataForModels($models));
    }

    /** @param class-string ...$models */
    final protected function getSchemaForModels(string ...$models): Schema
    {
        return $this->_schemaTool->getSchemaFromMetadata($this->getMetadataForModels($models));
    }

    /**
     * @param class-string[] $models
     *
     * @return ClassMetadata[]
     */
    private function getMetadataForModels(array $models): array
    {
        return array_map(
            function (string $className): ClassMetadata {
                return $this->_em->getClassMetadata($className);
            },
            $models,
        );
    }

    protected function useModelSet(string $setName): void
    {
        $this->_usedModelSets[$setName] = true;
    }

    /**
     * Sweeps the database tables and clears the EntityManager.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $conn = static::$sharedConn;

        // In case test is skipped, tearDown is called, but no setup may have run
        if (! $conn || ! isset($this->_em)) {
            return;
        }

        $platform = $conn->getDatabasePlatform();

        if ($this->isQueryLogAvailable()) {
            $this->getQueryLog()->reset();
        }

        if (isset($this->_usedModelSets['cms'])) {
            $conn->executeStatement('DELETE FROM cms_users_groups');
            $conn->executeStatement('DELETE FROM cms_groups');
            $conn->executeStatement('DELETE FROM cms_users_tags');
            $conn->executeStatement('DELETE FROM cms_tags');
            $conn->executeStatement('DELETE FROM cms_addresses');
            $conn->executeStatement('DELETE FROM cms_phonenumbers');
            $conn->executeStatement('DELETE FROM cms_comments');
            $conn->executeStatement('DELETE FROM cms_articles');
            $conn->executeStatement('DELETE FROM cms_users');
            $conn->executeStatement('DELETE FROM cms_emails');
        }

        if (isset($this->_usedModelSets['ecommerce'])) {
            $conn->executeStatement('DELETE FROM ecommerce_carts_products');
            $conn->executeStatement('DELETE FROM ecommerce_products_categories');
            $conn->executeStatement('DELETE FROM ecommerce_products_related');
            $conn->executeStatement('DELETE FROM ecommerce_carts');
            $conn->executeStatement('DELETE FROM ecommerce_customers WHERE mentor_id IS NOT NULL');
            $conn->executeStatement('DELETE FROM ecommerce_customers');
            $conn->executeStatement('DELETE FROM ecommerce_features');
            $conn->executeStatement('DELETE FROM ecommerce_products');
            $conn->executeStatement('DELETE FROM ecommerce_shippings');
            $conn->executeStatement('UPDATE ecommerce_categories SET parent_id = NULL');
            $conn->executeStatement('DELETE FROM ecommerce_categories');
        }

        if (isset($this->_usedModelSets['company'])) {
            $conn->executeStatement('DELETE FROM company_contract_employees');
            $conn->executeStatement('DELETE FROM company_contract_managers');
            $conn->executeStatement('DELETE FROM company_contracts');
            $conn->executeStatement('DELETE FROM company_persons_friends');
            $conn->executeStatement('DELETE FROM company_managers');
            $conn->executeStatement('DELETE FROM company_employees');
            $conn->executeStatement('UPDATE company_persons SET spouse_id = NULL');
            $conn->executeStatement('DELETE FROM company_persons');
            $conn->executeStatement('DELETE FROM company_raffles');
            $conn->executeStatement('DELETE FROM company_auctions');
            $conn->executeStatement('UPDATE company_organizations SET main_event_id = NULL');
            $conn->executeStatement('DELETE FROM company_events');
            $conn->executeStatement('DELETE FROM company_organizations');
        }

        if (isset($this->_usedModelSets['generic'])) {
            $conn->executeStatement('DELETE FROM boolean_model');
            $conn->executeStatement('DELETE FROM date_time_model');
            $conn->executeStatement('DELETE FROM decimal_model');
        }

        if (isset($this->_usedModelSets['routing'])) {
            $conn->executeStatement('DELETE FROM RoutingRouteLegs');
            $conn->executeStatement('DELETE FROM RoutingRouteBooking');
            $conn->executeStatement('DELETE FROM RoutingRoute');
            $conn->executeStatement('DELETE FROM RoutingLeg');
            $conn->executeStatement('DELETE FROM RoutingLocation');
        }

        if (isset($this->_usedModelSets['navigation'])) {
            $conn->executeStatement('DELETE FROM navigation_tour_pois');
            $conn->executeStatement('DELETE FROM navigation_photos');
            $conn->executeStatement('DELETE FROM navigation_pois');
            $conn->executeStatement('DELETE FROM navigation_tours');
            $conn->executeStatement('DELETE FROM navigation_countries');
        }

        if (isset($this->_usedModelSets['directorytree'])) {
            $conn->executeStatement('DELETE FROM ' . $platform->quoteSingleIdentifier('file'));
            // MySQL doesn't know deferred deletions therefore only executing the second query gives errors.
            $conn->executeStatement('DELETE FROM Directory WHERE parentDirectory_id IS NOT NULL');
            $conn->executeStatement('DELETE FROM Directory');
        }

        if (isset($this->_usedModelSets['ddc117'])) {
            $conn->executeStatement('DELETE FROM ddc117editor_ddc117translation');
            $conn->executeStatement('DELETE FROM DDC117Editor');
            $conn->executeStatement('DELETE FROM DDC117ApproveChanges');
            $conn->executeStatement('DELETE FROM DDC117Link');
            $conn->executeStatement('DELETE FROM DDC117Reference');
            $conn->executeStatement('DELETE FROM DDC117ArticleDetails');
            $conn->executeStatement('DELETE FROM DDC117Translation');
            $conn->executeStatement('DELETE FROM DDC117Article');
        }

        if (isset($this->_usedModelSets['stockexchange'])) {
            $conn->executeStatement('DELETE FROM exchange_bonds_stocks');
            $conn->executeStatement('DELETE FROM exchange_bonds');
            $conn->executeStatement('DELETE FROM exchange_stocks');
            $conn->executeStatement('DELETE FROM exchange_markets');
        }

        if (isset($this->_usedModelSets['legacy'])) {
            $conn->executeStatement('DELETE FROM legacy_users_cars');
            $conn->executeStatement('DELETE FROM legacy_users_reference');
            $conn->executeStatement('DELETE FROM legacy_articles');
            $conn->executeStatement('DELETE FROM legacy_cars');
            $conn->executeStatement('DELETE FROM legacy_users');
        }

        if (isset($this->_usedModelSets['customtype'])) {
            $conn->executeStatement('DELETE FROM customtype_parent_friends');
            $conn->executeStatement('DELETE FROM customtype_parents');
            $conn->executeStatement('DELETE FROM customtype_children');
            $conn->executeStatement('DELETE FROM customtype_uppercases');
        }

        if (isset($this->_usedModelSets['compositekeyinheritance'])) {
            $conn->executeStatement('DELETE FROM JoinedChildClass');
            $conn->executeStatement('DELETE FROM JoinedRootClass');
            $conn->executeStatement('DELETE FROM SingleRootClass');
        }

        if (isset($this->_usedModelSets['taxi'])) {
            $conn->executeStatement('DELETE FROM taxi_paid_ride');
            $conn->executeStatement('DELETE FROM taxi_ride');
            $conn->executeStatement('DELETE FROM taxi_car');
            $conn->executeStatement('DELETE FROM taxi_driver');
        }

        if (isset($this->_usedModelSets['tweet'])) {
            $conn->executeStatement('DELETE FROM tweet_tweet');
            $conn->executeStatement('DELETE FROM tweet_user_list');
            $conn->executeStatement('DELETE FROM tweet_user');
        }

        if (isset($this->_usedModelSets['cache'])) {
            $conn->executeStatement('DELETE FROM cache_attraction_location_info');
            $conn->executeStatement('DELETE FROM cache_attraction_contact_info');
            $conn->executeStatement('DELETE FROM cache_attraction_info');
            $conn->executeStatement('DELETE FROM cache_visited_cities');
            $conn->executeStatement('DELETE FROM cache_flight');
            $conn->executeStatement('DELETE FROM cache_attraction');
            $conn->executeStatement('DELETE FROM cache_travel');
            $conn->executeStatement('DELETE FROM cache_traveler');
            $conn->executeStatement('DELETE FROM cache_traveler_profile_info');
            $conn->executeStatement('DELETE FROM cache_traveler_profile');
            $conn->executeStatement('DELETE FROM cache_city');
            $conn->executeStatement('DELETE FROM cache_state');
            $conn->executeStatement('DELETE FROM cache_country');
            $conn->executeStatement('DELETE FROM cache_login');
            $conn->executeStatement('DELETE FROM cache_token');
            $conn->executeStatement('DELETE FROM cache_complex_action');
            $conn->executeStatement('DELETE FROM cache_action');
            $conn->executeStatement('DELETE FROM cache_client');
        }

        if (isset($this->_usedModelSets['ddc3346'])) {
            $conn->executeStatement('DELETE FROM ddc3346_articles');
            $conn->executeStatement('DELETE FROM ddc3346_users');
        }

        if (isset($this->_usedModelSets['ornemental_orphan_removal'])) {
            $conn->executeStatement('DELETE FROM ornemental_orphan_removal_person');
            $conn->executeStatement('DELETE FROM ornemental_orphan_removal_phone_number');
        }

        if (isset($this->_usedModelSets['quote'])) {
            $conn->executeStatement(
                sprintf(
                    'UPDATE %s SET %s = NULL',
                    $platform->quoteSingleIdentifier('quote-address'),
                    $platform->quoteSingleIdentifier('user-id'),
                ),
            );

            $conn->executeStatement('DELETE FROM ' . $platform->quoteSingleIdentifier('quote-users-groups'));
            $conn->executeStatement('DELETE FROM ' . $platform->quoteSingleIdentifier('quote-group'));
            $conn->executeStatement('DELETE FROM ' . $platform->quoteSingleIdentifier('quote-phone'));
            $conn->executeStatement('DELETE FROM ' . $platform->quoteSingleIdentifier('quote-user'));
            $conn->executeStatement('DELETE FROM ' . $platform->quoteSingleIdentifier('quote-address'));
            $conn->executeStatement('DELETE FROM ' . $platform->quoteSingleIdentifier('quote-city'));
        }

        if (isset($this->_usedModelSets['vct_onetoone'])) {
            $conn->executeStatement('DELETE FROM vct_owning_onetoone');
            $conn->executeStatement('DELETE FROM vct_inversed_onetoone');
        }

        if (isset($this->_usedModelSets['vct_onetoone_compositeid'])) {
            $conn->executeStatement('DELETE FROM vct_owning_onetoone_compositeid');
            $conn->executeStatement('DELETE FROM vct_inversed_onetoone_compositeid');
        }

        if (isset($this->_usedModelSets['vct_onetoone_compositeid_foreignkey'])) {
            $conn->executeStatement('DELETE FROM vct_owning_onetoone_compositeid_foreignkey');
            $conn->executeStatement('DELETE FROM vct_inversed_onetoone_compositeid_foreignkey');
            $conn->executeStatement('DELETE FROM vct_auxiliary');
        }

        if (isset($this->_usedModelSets['vct_onetomany'])) {
            $conn->executeStatement('DELETE FROM vct_owning_manytoone');
            $conn->executeStatement('DELETE FROM vct_inversed_onetomany');
        }

        if (isset($this->_usedModelSets['vct_onetomany_compositeid'])) {
            $conn->executeStatement('DELETE FROM vct_owning_manytoone_compositeid');
            $conn->executeStatement('DELETE FROM vct_inversed_onetomany_compositeid');
        }

        if (isset($this->_usedModelSets['vct_onetomany_compositeid_foreignkey'])) {
            $conn->executeStatement('DELETE FROM vct_owning_manytoone_compositeid_foreignkey');
            $conn->executeStatement('DELETE FROM vct_inversed_onetomany_compositeid_foreignkey');
            $conn->executeStatement('DELETE FROM vct_auxiliary');
        }

        if (isset($this->_usedModelSets['vct_onetomany_extralazy'])) {
            $conn->executeStatement('DELETE FROM vct_owning_manytoone_extralazy');
            $conn->executeStatement('DELETE FROM vct_inversed_onetomany_extralazy');
        }

        if (isset($this->_usedModelSets['vct_manytomany'])) {
            $conn->executeStatement('DELETE FROM vct_xref_manytomany');
            $conn->executeStatement('DELETE FROM vct_owning_manytomany');
            $conn->executeStatement('DELETE FROM vct_inversed_manytomany');
        }

        if (isset($this->_usedModelSets['vct_manytomany_compositeid'])) {
            $conn->executeStatement('DELETE FROM vct_xref_manytomany_compositeid');
            $conn->executeStatement('DELETE FROM vct_owning_manytomany_compositeid');
            $conn->executeStatement('DELETE FROM vct_inversed_manytomany_compositeid');
        }

        if (isset($this->_usedModelSets['vct_manytomany_compositeid_foreignkey'])) {
            $conn->executeStatement('DELETE FROM vct_xref_manytomany_compositeid_foreignkey');
            $conn->executeStatement('DELETE FROM vct_owning_manytomany_compositeid_foreignkey');
            $conn->executeStatement('DELETE FROM vct_inversed_manytomany_compositeid_foreignkey');
            $conn->executeStatement('DELETE FROM vct_auxiliary');
        }

        if (isset($this->_usedModelSets['vct_manytomany_extralazy'])) {
            $conn->executeStatement('DELETE FROM vct_xref_manytomany_extralazy');
            $conn->executeStatement('DELETE FROM vct_owning_manytomany_extralazy');
            $conn->executeStatement('DELETE FROM vct_inversed_manytomany_extralazy');
        }

        if (isset($this->_usedModelSets['geonames'])) {
            $conn->executeStatement('DELETE FROM geonames_admin1_alternate_name');
            $conn->executeStatement('DELETE FROM geonames_admin1');
            $conn->executeStatement('DELETE FROM geonames_city');
            $conn->executeStatement('DELETE FROM geonames_country');
        }

        if (isset($this->_usedModelSets['custom_id_object_type'])) {
            $conn->executeStatement('DELETE FROM custom_id_type_child');
            $conn->executeStatement('DELETE FROM custom_id_type_parent');
        }

        if (isset($this->_usedModelSets['pagination'])) {
            $conn->executeStatement('DELETE FROM pagination_logo');
            $conn->executeStatement('DELETE FROM pagination_department');
            $conn->executeStatement('DELETE FROM pagination_company');
            $conn->executeStatement('DELETE FROM pagination_user');
        }

        if (isset($this->_usedModelSets['versioned_many_to_one'])) {
            $conn->executeStatement('DELETE FROM versioned_many_to_one_article');
            $conn->executeStatement('DELETE FROM versioned_many_to_one_category');
        }

        if (isset($this->_usedModelSets['issue5989'])) {
            $conn->executeStatement('DELETE FROM issue5989_persons');
            $conn->executeStatement('DELETE FROM issue5989_employees');
            $conn->executeStatement('DELETE FROM issue5989_managers');
        }

        $this->_em->clear();
    }

    /** @throws RuntimeException */
    protected function setUpEntitySchema(array $classNames): void
    {
        if ($this->_em === null) {
            throw new RuntimeException('EntityManager not set, you have to call parent::setUp() before invoking this method.');
        }

        $classes = [];
        foreach ($classNames as $className) {
            if (! isset(static::$_entityTablesCreated[$className])) {
                static::$_entityTablesCreated[$className] = true;
                $classes[]                                = $this->_em->getClassMetadata($className);
            }
        }

        if ($classes) {
            $this->_schemaTool->createSchema($classes);
        }
    }

    /**
     * Creates a connection to the test database, if there is none yet, and
     * creates the necessary tables.
     */
    protected function setUp(): void
    {
        $this->setUpDBALTypes();

        if (! isset(static::$sharedConn)) {
            static::$sharedConn = TestUtil::getConnection(self::createDbalConfiguration());
        } elseif (method_exists(DBALConfiguration::class, 'setTypeProvider')) {
            // The registry is discarded between tests, see OrmTestCase::tearDown().
            // The platform keeps its own reference, set when the connection created it.
            $typeRegistry = self::getTypeRegistry();

            static::$sharedConn->getConfiguration()->setTypeProvider($typeRegistry);
            static::$sharedConn->getDatabasePlatform()->setTypeProvider($typeRegistry);
        }

        if (isset($GLOBALS['DOCTRINE_MARK_SQL_LOGS'])) {
            $platform = static::$sharedConn->getDatabasePlatform();
            if (
                $platform instanceof MySQLPlatform
                || $platform instanceof PostgreSQLPlatform
            ) {
                static::$sharedConn->executeQuery('SELECT 1 /*' . static::class . '*/');
            } elseif ($platform instanceof OraclePlatform) {
                static::$sharedConn->executeQuery('SELECT 1 /*' . static::class . '*/ FROM dual');
            }
        }

        if (! $this->_em) {
            $this->_em         = $this->getEntityManager();
            $this->_schemaTool = new SchemaTool($this->_em);
        }

        $classes = [];

        foreach ($this->_usedModelSets as $setName => $bool) {
            if (! isset(static::$tablesCreated[$setName])) {
                foreach (static::$modelSets[$setName] as $className) {
                    $classes[] = $this->_em->getClassMetadata($className);
                }

                static::$tablesCreated[$setName] = true;
            }
        }

        if ($classes) {
            $this->_schemaTool->createSchema($classes);
        }

        $this->getQueryLog()->enable();
    }

    /**
     * Gets an EntityManager for testing purposes.
     *
     * @throws ORMException
     */
    protected function getEntityManager(
        Connection|null $connection = null,
        MappingDriver|null $mappingDriver = null,
        string|null $metadataFactoryClassName = null,
    ): EntityManagerInterface {
        // NOTE: Functional tests use their own shared metadata cache, because
        // the actual database platform used during execution has effect on some
        // metadata mapping behaviors (like the choice of the ID generation).
        if (self::$metadataCache === null) {
            self::$metadataCache = new ArrayAdapter();
        }

        if (self::$queryCache === null) {
            self::$queryCache = new ArrayAdapter();
        }

        //FIXME: two different configs! $conn and the created entity manager have
        // different configs.
        $config = new Configuration();
        $config->setMetadataCache(self::$metadataCache);
        $config->setQueryCache(self::$queryCache);

        if ($metadataFactoryClassName !== null) {
            $config->setClassMetadataFactoryName($metadataFactoryClassName);
        }

        if (method_exists(Schema::class, 'edit') && $this->getEnv('ENABLE_SCHEMA_EDITOR_API', true)) {
            $config->setUseSchemaEditorApi(true);
        }

        if ($this->resultCache !== null) {
            $config->setResultCache($this->resultCache);
        }

        $enableSecondLevelCache = $this->getEnv('ENABLE_SECOND_LEVEL_CACHE', false);

        if ($this->isSecondLevelCacheEnabled || $enableSecondLevelCache) {
            $cacheConfig = new CacheConfiguration();
            $factory     = new DefaultCacheFactory(
                $cacheConfig->getRegionsConfiguration(),
                $this->getSharedSecondLevelCache(),
            );

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

        $mappingDriver ??= AttributeDriverFactory::createAttributeDriver([
            __DIR__ . '/Models/Cache',
            __DIR__ . '/Models/GeoNames',
        ]);

        $config->setMetadataDriverImpl($mappingDriver);

        $conn = $connection ?: static::$sharedConn;
        assert($conn !== null);
        $conn->queryLog->reset();

        $evm = new EventManager();

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

        return new EntityManager($conn, $config, $evm);
    }

    final protected function createSchemaManager(): AbstractSchemaManager
    {
        return $this->_em->getConnection()->createSchemaManager();
    }

    /** @throws Throwable */
    protected function onNotSuccessfulTest(Throwable $e): never
    {
        if ($e instanceof AssertionFailedError) {
            throw $e;
        }

        if ($this->isQueryLogAvailable() && $this->getQueryLog()->queries !== []) {
            $queries       = '';
            $last25queries = array_slice(array_reverse($this->getQueryLog()->queries, true), 0, 25, true);
            foreach ($last25queries as $i => $query) {
                $params   = array_map(static fn ($p) => is_object($p) ? get_debug_type($p) : var_export($p, true), $query['params'] ?: []);
                $queries .= $i . ". SQL: '" . $query['sql'] . "' Params: " . implode(', ', $params) . PHP_EOL;
            }

            $trace    = $e->getTrace();
            $traceMsg = '';
            foreach ($trace as $part) {
                if (isset($part['file'])) {
                    if (str_contains($part['file'], 'PHPUnit/')) {
                        // Beginning with PHPUnit files we don't print the trace anymore.
                        break;
                    }

                    $traceMsg .= $part['file'] . ':' . $part['line'] . PHP_EOL;
                }
            }

            $message = '[' . get_debug_type($e) . '] ' . $e->getMessage() . PHP_EOL . PHP_EOL . 'With queries:' . PHP_EOL . $queries . PHP_EOL . 'Trace:' . PHP_EOL . $traceMsg;

            throw new Exception($message, (int) $e->getCode(), $e);
        }

        throw $e;
    }

    public function assertSQLEquals(string $expectedSql, string $actualSql): void
    {
        self::assertEquals(
            strtolower($expectedSql),
            strtolower($actualSql),
            'Lowercase comparison of SQL statements failed.',
        );
    }

    /**
     * Configures DBAL types required in tests
     */
    protected function setUpDBALTypes(): void
    {
        self::registerDbalType('rot13', Rot13Type::class);
    }

    final protected function isQueryLogAvailable(): bool
    {
        return $this->_em?->getConnection() instanceof Connection;
    }

    final protected function getQueryLog(): QueryLog
    {
        $connection = $this->_em->getConnection();
        if (! $connection instanceof Connection) {
            throw new RuntimeException(sprintf(
                'The query log is only available if %s is used as wrapper class. Got %s.',
                Connection::class,
                get_debug_type($connection),
            ));
        }

        return $connection->queryLog;
    }

    final protected function assertQueryCount(int $expectedCount, string $message = ''): void
    {
        self::assertThat($this->getQueryLog()->queries, new Count($expectedCount), $message);
    }

    /** @phpstan-return array{sql: string, params: array|null, types: array|null} */
    final protected function getLastLoggedQuery(int $index = 0): array
    {
        $queries   = $this->getQueryLog()->queries;
        $lastQuery = null;
        for ($i = $index; $i >= 0; $i--) {
            $lastQuery = array_pop($queries);
        }

        if ($lastQuery === null) {
            throw new RuntimeException('The query log was empty.');
        }

        return $lastQuery;
    }

    /**
     * Drops the table with the specified name, if it exists.
     *
     * @throws Exception
     */
    protected function dropTableIfExists(string $name): void
    {
        $schemaManager = $this->createSchemaManager();

        try {
            $schemaManager->dropTable($name);
        } catch (DatabaseObjectNotFoundException) {
        }
    }

    /**
     * Drops and creates a new table.
     *
     * @throws Exception
     */
    protected function dropAndCreateTable(Table $table): void
    {
        $schemaManager = $this->createSchemaManager();
        $platform      = $this->_em->getConnection()->getDatabasePlatform();

        if ($table instanceof NamedObject) {
            $tableName = $table->getObjectName()->toSQL($platform);
        } else {
            $tableName = $table->getQuotedName($platform);
        }

        $this->dropTableIfExists($tableName);
        $schemaManager->createTable($table);
    }

    final protected function isUninitializedObject(object $entity): bool
    {
        return $this->_em->getUnitOfWork()->isUninitializedObject($entity);
    }

    final protected function initializeObject(object $entity): void
    {
        $this->_em->getUnitOfWork()->initializeObject($entity);
    }
}
