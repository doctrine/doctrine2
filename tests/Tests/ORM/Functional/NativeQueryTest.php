<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Internal\Hydration\HydrationException;
use Doctrine\ORM\Internal\SQLResultCasing;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Tests\DbalTypes\UpperCaseStringType;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\CMS\CmsUserDTO;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\Company\CompanyFixContract;
use Doctrine\Tests\Models\CustomType\CustomTypeUpperCase;
use Doctrine\Tests\Models\DDC3899\DDC3899FixContract;
use Doctrine\Tests\Models\DDC3899\DDC3899User;
use Doctrine\Tests\OrmFunctionalTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

class NativeQueryTest extends OrmFunctionalTestCase
{
    use SQLResultCasing;

    private AbstractPlatform|null $platform = null;

    protected function setUp(): void
    {
        $this->useModelSet('cms');
        $this->useModelSet('company');

        parent::setUp();

        $this->platform = $this->_em->getConnection()->getDatabasePlatform();
    }

    public function testBasicNativeQuery(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'dev';
        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->clear();

        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'id'), 'id');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'name'), 'name');

        $query = $this->_em->createNativeQuery('SELECT id, name FROM cms_users WHERE username = ?', $rsm);
        $query->setParameter(1, 'romanb');

        $users = $query->getResult();

        self::assertCount(1, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Roman', $users[0]->name);
    }

    public function testNativeQueryWithArrayParameter(): void
    {
        $user           = new CmsUser();
        $user->name     = 'William Shatner';
        $user->username = 'wshatner';
        $user->status   = 'dev';
        $this->_em->persist($user);
        $user           = new CmsUser();
        $user->name     = 'Leonard Nimoy';
        $user->username = 'lnimoy';
        $user->status   = 'dev';
        $this->_em->persist($user);
        $user           = new CmsUser();
        $user->name     = 'DeForest Kelly';
        $user->username = 'dkelly';
        $user->status   = 'dev';
        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->clear();

        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'id'), 'id');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'name'), 'name');

        $query = $this->_em->createNativeQuery('SELECT id, name FROM cms_users WHERE username IN (?) ORDER BY username', $rsm);
        $query->setParameter(1, ['wshatner', 'lnimoy'], ArrayParameterType::STRING);

        $users = $query->getResult();

        self::assertCount(2, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Leonard Nimoy', $users[0]->name);
        self::assertEquals('William Shatner', $users[1]->name);
    }

    public function testBasicNativeQueryWithMetaResult(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'dev';

        $addr          = new CmsAddress();
        $addr->country = 'germany';
        $addr->zip     = 10827;
        $addr->city    = 'Berlin';

        $user->setAddress($addr);

        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->clear();

        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CmsAddress::class, 'a');
        $rsm->addFieldResult('a', $this->getSQLResultCasing($this->platform, 'id'), 'id');
        $rsm->addFieldResult('a', $this->getSQLResultCasing($this->platform, 'country'), 'country');
        $rsm->addFieldResult('a', $this->getSQLResultCasing($this->platform, 'zip'), 'zip');
        $rsm->addFieldResult('a', $this->getSQLResultCasing($this->platform, 'city'), 'city');
        $rsm->addMetaResult('a', $this->getSQLResultCasing($this->platform, 'user_id'), 'user_id', false, 'integer');

        $query = $this->_em->createNativeQuery('SELECT a.id, a.country, a.zip, a.city, a.user_id FROM cms_addresses a WHERE a.id = ?', $rsm);
        $query->setParameter(1, $addr->id);

        $addresses = $query->getResult();

        self::assertCount(1, $addresses);
        self::assertInstanceOf(CmsAddress::class, $addresses[0]);
        self::assertEquals($addr->country, $addresses[0]->country);
        self::assertEquals($addr->zip, $addresses[0]->zip);
        self::assertEquals($addr->city, $addresses[0]->city);
        self::assertEquals($addr->street, $addresses[0]->street);
        self::assertInstanceOf(CmsUser::class, $addresses[0]->user);
    }

    public function testJoinedOneToManyNativeQuery(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'dev';

        $phone              = new CmsPhonenumber();
        $phone->phonenumber = 424242;

        $user->addPhonenumber($phone);

        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->clear();

        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'id'), 'id');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'name'), 'name');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'status'), 'status');
        $rsm->addJoinedEntityResult(CmsPhonenumber::class, 'p', 'u', 'phonenumbers');
        $rsm->addFieldResult('p', $this->getSQLResultCasing($this->platform, 'phonenumber'), 'phonenumber');

        $query = $this->_em->createNativeQuery('SELECT id, name, status, phonenumber FROM cms_users INNER JOIN cms_phonenumbers ON id = user_id WHERE username = ?', $rsm);
        $query->setParameter(1, 'romanb');

        $users = $query->getResult();
        self::assertCount(1, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Roman', $users[0]->name);
        self::assertInstanceOf(PersistentCollection::class, $users[0]->getPhonenumbers());
        self::assertTrue($users[0]->getPhonenumbers()->isInitialized());
        self::assertCount(1, $users[0]->getPhonenumbers());
        $phones = $users[0]->getPhonenumbers();
        self::assertEquals(424242, $phones[0]->phonenumber);
        self::assertSame($phones[0]->getUser(), $users[0]);
    }

    public function testMappingAsDto(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'dev';

        $phone              = new CmsPhonenumber();
        $phone->phonenumber = 424242;

        $user->addPhonenumber($phone);

        $email        = new CmsEmail();
        $email->email = 'fabio.bat.silva@gmail.com';

        $user->setEmail($email);

        $addr          = new CmsAddress();
        $addr->country = 'germany';
        $addr->zip     = 10827;
        $addr->city    = 'Berlin';

        $user->setAddress($addr);

        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->clear();

        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('name', 1, 'string');
        $rsm->addScalarResult('email', 2, 'string');
        $rsm->addScalarResult('city', 3, 'string');
        $rsm->newObjectMappings['name']  = [
            'className' => CmsUserDTO::class,
            'objIndex'  => 0,
            'argIndex'  => 0,
        ];
        $rsm->newObjectMappings['email'] = [
            'className' => CmsUserDTO::class,
            'objIndex'  => 0,
            'argIndex'  => 1,
        ];
        $rsm->newObjectMappings['city']  = [
            'className' => CmsUserDTO::class,
            'objIndex'  => 0,
            'argIndex'  => 2,
        ];
        $query                           = $this->_em->createNativeQuery(
            <<<'SQL'
    SELECT u.name, e.email, a.city
      FROM cms_users u
INNER JOIN cms_phonenumbers p ON u.id = p.user_id
INNER JOIN cms_emails e ON e.id = u.email_id
INNER JOIN cms_addresses a ON u.id = a.user_id
     WHERE username = ?
SQL
            ,
            $rsm,
        );
        $query->setParameter(1, 'romanb');

        $users = $query->getResult();
        self::assertCount(1, $users);
        $user = $users[0];
        self::assertInstanceOf(CmsUserDTO::class, $user);
        self::assertEquals('Roman', $user->name);
        self::assertEquals('fabio.bat.silva@gmail.com', $user->email);
        self::assertEquals('Berlin', $user->address);
    }

    public function testJoinedOneToOneNativeQuery(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'dev';

        $addr          = new CmsAddress();
        $addr->country = 'germany';
        $addr->zip     = 10827;
        $addr->city    = 'Berlin';

        $user->setAddress($addr);

        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->clear();

        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'id'), 'id');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'name'), 'name');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'status'), 'status');
        $rsm->addJoinedEntityResult(CmsAddress::class, 'a', 'u', 'address');
        $rsm->addFieldResult('a', $this->getSQLResultCasing($this->platform, 'a_id'), 'id');
        $rsm->addFieldResult('a', $this->getSQLResultCasing($this->platform, 'country'), 'country');
        $rsm->addFieldResult('a', $this->getSQLResultCasing($this->platform, 'zip'), 'zip');
        $rsm->addFieldResult('a', $this->getSQLResultCasing($this->platform, 'city'), 'city');

        $query = $this->_em->createNativeQuery('SELECT u.id, u.name, u.status, a.id AS a_id, a.country, a.zip, a.city FROM cms_users u INNER JOIN cms_addresses a ON u.id = a.user_id WHERE u.username = ?', $rsm);
        $query->setParameter(1, 'romanb');

        $users = $query->getResult();

        self::assertCount(1, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Roman', $users[0]->name);
        self::assertInstanceOf(PersistentCollection::class, $users[0]->getPhonenumbers());
        self::assertFalse($users[0]->getPhonenumbers()->isInitialized());
        self::assertInstanceOf(CmsAddress::class, $users[0]->getAddress());
        self::assertEquals($users[0]->getAddress()->getUser(), $users[0]);
        self::assertEquals('germany', $users[0]->getAddress()->getCountry());
        self::assertEquals(10827, $users[0]->getAddress()->getZipCode());
        self::assertEquals('Berlin', $users[0]->getAddress()->getCity());
    }

    public function testFluentInterface(): void
    {
        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(1, 'foo'));
        $parameters->add(new Parameter(2, 'bar'));

        $rsm = new ResultSetMapping();

        $q  = $this->_em->createNativeQuery('SELECT id, name, status, phonenumber FROM cms_users INNER JOIN cms_phonenumbers ON id = user_id WHERE username = ?', $rsm);
        $q2 = $q->setSQL('foo')
          ->setResultSetMapping($rsm)
          ->expireResultCache(true)
          ->setHint('foo', 'bar')
          ->setParameter(1, 'foo')
          ->setParameters($parameters)
          ->setResultCacheLifetime(3500);

        self::assertSame($q, $q2);
    }

    public function testJoinedOneToManyNativeQueryWithRSMBuilder(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'dev';

        $phone              = new CmsPhonenumber();
        $phone->phonenumber = 424242;

        $user->addPhonenumber($phone);

        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->clear();

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CmsUser::class, 'u');
        $rsm->addJoinedEntityFromClassMetadata(CmsPhonenumber::class, 'p', 'u', 'phonenumbers');
        $query = $this->_em->createNativeQuery('SELECT u.*, p.* FROM cms_users u LEFT JOIN cms_phonenumbers p ON u.id = p.user_id WHERE username = ?', $rsm);
        $query->setParameter(1, 'romanb');

        $users = $query->getResult();
        self::assertCount(1, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Roman', $users[0]->name);
        self::assertInstanceOf(PersistentCollection::class, $users[0]->getPhonenumbers());
        self::assertTrue($users[0]->getPhonenumbers()->isInitialized());
        self::assertCount(1, $users[0]->getPhonenumbers());
        $phones = $users[0]->getPhonenumbers();
        self::assertEquals(424242, $phones[0]->phonenumber);
        self::assertSame($phones[0]->getUser(), $users[0]);

        $this->_em->clear();

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CmsPhonenumber::class, 'p');
        $query = $this->_em->createNativeQuery('SELECT p.* FROM cms_phonenumbers p WHERE p.phonenumber = ?', $rsm);
        $query->setParameter(1, $phone->phonenumber);
        $phone = $query->getSingleResult();

        self::assertNotNull($phone->getUser());
        self::assertEquals($user->name, $phone->getUser()->getName());
    }

    public function testJoinedOneToOneNativeQueryWithRSMBuilder(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'dev';

        $addr          = new CmsAddress();
        $addr->country = 'germany';
        $addr->zip     = 10827;
        $addr->city    = 'Berlin';

        $user->setAddress($addr);

        $this->_em->persist($user);
        $this->_em->flush();

        $this->_em->clear();

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CmsUser::class, 'u');
        $rsm->addJoinedEntityFromClassMetadata(CmsAddress::class, 'a', 'u', 'address', ['id' => 'a_id']);

        $query = $this->_em->createNativeQuery('SELECT u.*, a.*, a.id AS a_id FROM cms_users u INNER JOIN cms_addresses a ON u.id = a.user_id WHERE u.username = ?', $rsm);
        $query->setParameter(1, 'romanb');

        $users = $query->getResult();

        self::assertCount(1, $users);
        self::assertInstanceOf(CmsUser::class, $users[0]);
        self::assertEquals('Roman', $users[0]->name);
        self::assertInstanceOf(PersistentCollection::class, $users[0]->getPhonenumbers());
        self::assertFalse($users[0]->getPhonenumbers()->isInitialized());
        self::assertInstanceOf(CmsAddress::class, $users[0]->getAddress());
        self::assertEquals($users[0]->getAddress()->getUser(), $users[0]);
        self::assertEquals('germany', $users[0]->getAddress()->getCountry());
        self::assertEquals(10827, $users[0]->getAddress()->getZipCode());
        self::assertEquals('Berlin', $users[0]->getAddress()->getCity());

        $this->_em->clear();

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CmsAddress::class, 'a');
        $query = $this->_em->createNativeQuery('SELECT a.* FROM cms_addresses a WHERE a.id = ?', $rsm);
        $query->setParameter(1, $addr->getId());
        $address = $query->getSingleResult();

        self::assertNotNull($address->getUser());
        self::assertEquals($user->name, $address->getUser()->getName());
    }

    #[Group('rsm-sti')]
    public function testConcreteClassInSingleTableInheritanceSchemaWithRSMBuilderIsFine(): void
    {
        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CompanyFixContract::class, 'c');

        self::assertSame(CompanyFixContract::class, $rsm->getClassName('c'));
    }

    #[Group('rsm-sti')]
    public function testAbstractClassInSingleTableInheritanceSchemaWithRSMBuilderThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ResultSetMapping builder does not currently support your inheritance scheme.');

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CompanyContract::class, 'c');
    }

    public function testRSMBuilderThrowsExceptionOnColumnConflict(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CmsUser::class, 'u');
        $rsm->addJoinedEntityFromClassMetadata(CmsAddress::class, 'a', 'u', 'address');
    }

    #[Group('PR-39')]
    public function testUnknownParentAliasThrowsException(): void
    {
        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CmsUser::class, 'u');
        $rsm->addJoinedEntityFromClassMetadata(CmsAddress::class, 'a', 'un', 'address', ['id' => 'a_id']);

        $query = $this->_em->createNativeQuery('SELECT u.*, a.*, a.id AS a_id FROM cms_users u INNER JOIN cms_addresses a ON u.id = a.user_id WHERE u.username = ?', $rsm);
        $query->setParameter(1, 'romanb');

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage("The parent object of entity result with alias 'a' was not found. The parent alias is 'un'.");

        $users = $query->getResult();
    }

    #[Group('DDC-2055')]
    public function testGenerateSelectClauseNoRenameSingleEntity(): void
    {
        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CmsUser::class, 'u');

        $selectClause = $rsm->generateSelectClause();

        $this->assertSQLEquals('u.id AS id, u.status AS status, u.username AS username, u.name AS name, u.email_id AS email_id', $selectClause);
    }

    #[Group('DDC-2055')]
    public function testGenerateSelectClauseCustomRenames(): void
    {
        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CmsUser::class, 'u', [
            'id' => 'id1',
            'username' => 'username2',
        ]);

        $selectClause = $rsm->generateSelectClause();

        $this->assertSQLEquals('u.id AS id1, u.status AS status, u.username AS username2, u.name AS name, u.email_id AS email_id', $selectClause);
    }

    #[Group('DDC-2055')]
    public function testGenerateSelectClauseRenameTableAlias(): void
    {
        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(CmsUser::class, 'u');

        $selectClause = $rsm->generateSelectClause(['u' => 'u1']);

        $this->assertSQLEquals('u1.id AS id, u1.status AS status, u1.username AS username, u1.name AS name, u1.email_id AS email_id', $selectClause);
    }

    #[Group('DDC-2055')]
    public function testGenerateSelectClauseIncrement(): void
    {
        $rsm = new ResultSetMappingBuilder($this->_em, ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT);
        $rsm->addRootEntityFromClassMetadata(CmsUser::class, 'u');

        $selectClause = $rsm->generateSelectClause();

        $this->assertSQLEquals('u.id AS id0, u.status AS status1, u.username AS username2, u.name AS name3, u.email_id AS email_id4', $selectClause);
    }

    #[Group('DDC-2055')]
    public function testGenerateSelectClauseToString(): void
    {
        $rsm = new ResultSetMappingBuilder($this->_em, ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT);
        $rsm->addRootEntityFromClassMetadata(CmsUser::class, 'u');

        $this->assertSQLEquals('u.id AS id0, u.status AS status1, u.username AS username2, u.name AS name3, u.email_id AS email_id4', (string) $rsm);
    }

    #[Group('DDC-3899')]
    public function testGenerateSelectClauseWithDiscriminatorColumn(): void
    {
        $rsm = new ResultSetMappingBuilder($this->_em, ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT);
        $rsm->addEntityResult(DDC3899User::class, 'u');
        $rsm->addJoinedEntityResult(DDC3899FixContract::class, 'c', 'u', 'contracts');
        $rsm->addFieldResult('u', $this->getSQLResultCasing($this->platform, 'id'), 'id');
        $rsm->setDiscriminatorColumn('c', $this->getSQLResultCasing($this->platform, 'discr'));

        $selectClause = $rsm->generateSelectClause(['u' => 'u1', 'c' => 'c1']);

        $this->assertSQLEquals('u1.id as id, c1.discr as discr', $selectClause);
    }

    public function testGenerateSelectClauseWithCustomTypeUsingEntityFromClassMetadata(): void
    {
        self::registerDbalType('upper_case_string', UpperCaseStringType::class);

        $rsm = new ResultSetMappingBuilder($this->_em, ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT);

        $rsm->addRootEntityFromClassMetadata(CustomTypeUpperCase::class, 'ct');

        $selectClause = $rsm->generateSelectClause(['ct' => 'ct1']);

        $this->assertSQLEquals('ct1.id as id0, lower(ct1.lowercasestring) as lowercasestring1, lower(ct1.named_lower_case_string) as named_lower_case_string2', $selectClause);
    }

    public function testGenerateSelectClauseWithCustomTypeUsingAddFieldResult(): void
    {
        self::registerDbalType('upper_case_string', UpperCaseStringType::class);

        $rsm = new ResultSetMappingBuilder($this->_em, ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT);
        $rsm->addEntityResult(CustomTypeUpperCase::class, 'ct');
        $rsm->addFieldResult('ct', $this->getSQLResultCasing($this->platform, 'lowercasestring'), 'lowerCaseString');

        $selectClause = $rsm->generateSelectClause(['ct' => 'ct1']);

        $this->assertSQLEquals('lower(ct1.lowercasestring) as lowercasestring', $selectClause);
    }
}
