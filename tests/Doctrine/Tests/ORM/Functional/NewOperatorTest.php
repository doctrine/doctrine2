<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Query;
use Doctrine\Tests\Models\CMS\CmsAddressDTO;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUserDTO;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-1574
 */
class NewOperatorTest extends OrmFunctionalTestCase
{
    /**
     * @var array
     */
    private $fixtures;

    protected function setUp()
    {
        $this->useModelSet('cms');
        parent::setUp();

        $this->loadFixtures();
    }

    public function provideDataForHydrationMode()
    {
        return [
            [Query::HYDRATE_ARRAY],
            [Query::HYDRATE_OBJECT],
        ];
    }

    private function loadFixtures()
    {
        $u1 = new CmsUser;
        $u2 = new CmsUser;
        $u3 = new CmsUser;

        $u1->setEmail(new CmsEmail());
        $u1->setAddress(new CmsAddress());
        $u1->addPhonenumber(new CmsPhonenumber());

        $u2->setEmail(new CmsEmail());
        $u2->setAddress(new CmsAddress());
        $u2->addPhonenumber(new CmsPhonenumber());
        $u2->addPhonenumber(new CmsPhonenumber());

        $u3->setEmail(new CmsEmail());
        $u3->setAddress(new CmsAddress());
        $u3->addPhonenumber(new CmsPhonenumber());
        $u3->addPhonenumber(new CmsPhonenumber());
        $u3->addPhonenumber(new CmsPhonenumber());

        $u1->name               = 'Test 1';
        $u1->username           = '1test';
        $u1->status             = 'developer';
        $u1->email->email       = 'email@test1.com';
        $u1->address->zip       = '111111111';
        $u1->address->city      = 'Some City 1';
        $u1->address->country   = 'Some Country 2';
        $u1->phonenumbers[0]->phonenumber = "(11) 1111-1111";

        $u2->name               = 'Test 2';
        $u2->username           = '2test';
        $u2->status             = 'developer';
        $u2->email->email       = 'email@test2.com';
        $u2->address->zip       = '222222222';
        $u2->address->city      = 'Some City 2';
        $u2->address->country   = 'Some Country 2';
        $u2->phonenumbers[0]->phonenumber = "(22) 1111-1111";
        $u2->phonenumbers[1]->phonenumber = "(22) 2222-2222";

        $u3->name               = 'Test 3';
        $u3->username           = '3test';
        $u3->status             = 'developer';
        $u3->email->email       = 'email@test3.com';
        $u3->address->zip       = '33333333';
        $u3->address->city      = 'Some City 3';
        $u3->address->country   = 'Some Country 3';
        $u3->phonenumbers[0]->phonenumber = "(33) 1111-1111";
        $u3->phonenumbers[1]->phonenumber = "(33) 2222-2222";
        $u3->phonenumbers[2]->phonenumber = "(33) 3333-3333";

        $this->em->persist($u1);
        $this->em->persist($u2);
        $this->em->persist($u3);

        $this->em->flush();
        $this->em->clear();

        $this->fixtures = [$u1, $u2, $u3];
    }

    /**
     * @dataProvider provideDataForHydrationMode
     */
    public function testShouldSupportsBasicUsage($hydrationMode)
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\CMS\CmsUserDTO(
                    u.name,
                    e.email,
                    a.city
                )
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult($hydrationMode);

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]);

        self::assertEquals($this->fixtures[0]->name, $result[0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]->email);

        self::assertEquals($this->fixtures[0]->address->city, $result[0]->address);
        self::assertEquals($this->fixtures[1]->address->city, $result[1]->address);
        self::assertEquals($this->fixtures[2]->address->city, $result[2]->address);
    }

    /**
     * @dataProvider provideDataForHydrationMode
     */
    public function testShouldIgnoreAliasesForSingleObject($hydrationMode)
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\CMS\CmsUserDTO(
                    u.name,
                    e.email,
                    a.city
                ) as cmsUser
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult($hydrationMode);

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]);

        self::assertEquals($this->fixtures[0]->name, $result[0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]->email);

        self::assertEquals($this->fixtures[0]->address->city, $result[0]->address);
        self::assertEquals($this->fixtures[1]->address->city, $result[1]->address);
        self::assertEquals($this->fixtures[2]->address->city, $result[2]->address);
    }

    public function testShouldAssumeFromEntityNamespaceWhenNotGiven()
    {
        $dql = "
            SELECT
                new CmsUserDTO(u.name, e.email, a.city)
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]);
    }

    public function testShouldSupportLiteralExpression()
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\CMS\CmsUserDTO(
                    u.name,
                    'fabio.bat.silva@gmail.com',
                    FALSE,
                    123
                )
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            JOIN
                u.phonenumbers p
            GROUP BY
                u, e, a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]);


        self::assertEquals($this->fixtures[0]->name, $result[0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]->name);

        self::assertEquals('fabio.bat.silva@gmail.com', $result[0]->email);
        self::assertEquals('fabio.bat.silva@gmail.com', $result[1]->email);
        self::assertEquals('fabio.bat.silva@gmail.com', $result[2]->email);

        self::assertFalse($result[0]->address);
        self::assertFalse($result[1]->address);
        self::assertFalse($result[2]->address);

        self::assertEquals(123, $result[0]->phonenumbers);
        self::assertEquals(123, $result[1]->phonenumbers);
        self::assertEquals(123, $result[2]->phonenumbers);
    }

    public function testShouldSupportCaseExpression()
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\CMS\CmsUserDTO(
                    u.name,
                    CASE WHEN (e.email = 'email@test1.com') THEN 'TEST1' ELSE 'OTHER_TEST' END
                )
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            JOIN
                u.phonenumbers p
            GROUP BY
                u, e, a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]);


        self::assertEquals($this->fixtures[0]->name, $result[0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]->name);

        self::assertEquals('TEST1', $result[0]->email);
        self::assertEquals('OTHER_TEST', $result[1]->email);
        self::assertEquals('OTHER_TEST', $result[2]->email);
    }

    public function testShouldSupportSimpleArithmeticExpression()
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\CMS\CmsUserDTO(
                    u.name,
                    e.email,
                    a.city,
                    a.id + u.id
                )
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            JOIN
                u.phonenumbers p
            GROUP BY
                u, e, a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]);

        self::assertEquals($this->fixtures[0]->name, $result[0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]->email);

        self::assertEquals($this->fixtures[0]->address->city, $result[0]->address);
        self::assertEquals($this->fixtures[1]->address->city, $result[1]->address);
        self::assertEquals($this->fixtures[2]->address->city, $result[2]->address);

        self::assertEquals(
            ($this->fixtures[0]->address->id + $this->fixtures[0]->id),
            $result[0]->phonenumbers
        );

        self::assertEquals(
            ($this->fixtures[1]->address->id + $this->fixtures[1]->id),
            $result[1]->phonenumbers
        );

        self::assertEquals(
            ($this->fixtures[2]->address->id + $this->fixtures[2]->id),
            $result[2]->phonenumbers
        );
    }

    public function testShouldSupportAggregateFunctions()
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\CMS\CmsUserDTO(
                    u.name,
                    e.email,
                    a.city,
                    COUNT(p)
                )
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            JOIN
                u.phonenumbers p
            GROUP BY
                u, e, a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]);

        self::assertEquals($this->fixtures[0]->name, $result[0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]->email);

        self::assertEquals($this->fixtures[0]->address->city, $result[0]->address);
        self::assertEquals($this->fixtures[1]->address->city, $result[1]->address);
        self::assertEquals($this->fixtures[2]->address->city, $result[2]->address);

        self::assertEquals(
            (count($this->fixtures[0]->phonenumbers)),
            $result[0]->phonenumbers
        );

        self::assertEquals(
            (count($this->fixtures[1]->phonenumbers)),
            $result[1]->phonenumbers
        );

        self::assertEquals(
            (count($this->fixtures[2]->phonenumbers)),
            $result[2]->phonenumbers
        );
    }

    public function testShouldSupportArithmeticExpression()
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\CMS\CmsUserDTO(
                    u.name,
                    e.email,
                    a.city,
                    COUNT(p) + u.id
                )
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            JOIN
                u.phonenumbers p
            GROUP BY
                u, e, a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]);

        self::assertEquals($this->fixtures[0]->name, $result[0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]->email);

        self::assertEquals($this->fixtures[0]->address->city, $result[0]->address);
        self::assertEquals($this->fixtures[1]->address->city, $result[1]->address);
        self::assertEquals($this->fixtures[2]->address->city, $result[2]->address);

        self::assertEquals(
            (count($this->fixtures[0]->phonenumbers) + $this->fixtures[0]->id),
            $result[0]->phonenumbers
        );

        self::assertEquals(
            (count($this->fixtures[1]->phonenumbers) + $this->fixtures[1]->id),
            $result[1]->phonenumbers
        );

        self::assertEquals(
            (count($this->fixtures[2]->phonenumbers) + $this->fixtures[2]->id),
            $result[2]->phonenumbers
        );
    }

    public function testShouldSupportMultipleNewOperators()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email
                ),
                new CmsAddressDTO(
                    a.country,
                    a.city
                )
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0][0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1][0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2][0]);

        self::assertInstanceOf(CmsAddressDTO::class, $result[0][1]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[1][1]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[2][1]);

        self::assertEquals($this->fixtures[0]->name, $result[0][0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1][0]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2][0]->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0][0]->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1][0]->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2][0]->email);


        self::assertEquals($this->fixtures[0]->address->city, $result[0][1]->city);
        self::assertEquals($this->fixtures[1]->address->city, $result[1][1]->city);
        self::assertEquals($this->fixtures[2]->address->city, $result[2][1]->city);

        self::assertEquals($this->fixtures[0]->address->country, $result[0][1]->country);
        self::assertEquals($this->fixtures[1]->address->country, $result[1][1]->country);
        self::assertEquals($this->fixtures[2]->address->country, $result[2][1]->country);
    }

    public function testShouldSupportMultipleNewOperatorsWithAliases()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email
                ) as cmsUser,
                new CmsAddressDTO(
                    a.country,
                    a.city
                ) as cmsAddress
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]['cmsUser']);

        self::assertInstanceOf(CmsAddressDTO::class, $result[0]['cmsAddress']);
        self::assertInstanceOf(CmsAddressDTO::class, $result[1]['cmsAddress']);
        self::assertInstanceOf(CmsAddressDTO::class, $result[2]['cmsAddress']);

        self::assertEquals($this->fixtures[0]->name, $result[0]['cmsUser']->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]['cmsUser']->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]['cmsUser']->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]['cmsUser']->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]['cmsUser']->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]['cmsUser']->email);


        self::assertEquals($this->fixtures[0]->address->city, $result[0]['cmsAddress']->city);
        self::assertEquals($this->fixtures[1]->address->city, $result[1]['cmsAddress']->city);
        self::assertEquals($this->fixtures[2]->address->city, $result[2]['cmsAddress']->city);

        self::assertEquals($this->fixtures[0]->address->country, $result[0]['cmsAddress']->country);
        self::assertEquals($this->fixtures[1]->address->country, $result[1]['cmsAddress']->country);
        self::assertEquals($this->fixtures[2]->address->country, $result[2]['cmsAddress']->country);
    }

    public function testShouldSupportMultipleNewOperatorsWithAndWithoutAliases()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email
                ) as cmsUser,
                new CmsAddressDTO(
                    a.country,
                    a.city
                )
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]['cmsUser']);

        self::assertInstanceOf(CmsAddressDTO::class, $result[0][0]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[1][0]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[2][0]);

        self::assertEquals($this->fixtures[0]->name, $result[0]['cmsUser']->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]['cmsUser']->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]['cmsUser']->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]['cmsUser']->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]['cmsUser']->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]['cmsUser']->email);


        self::assertEquals($this->fixtures[0]->address->city, $result[0][0]->city);
        self::assertEquals($this->fixtures[1]->address->city, $result[1][0]->city);
        self::assertEquals($this->fixtures[2]->address->city, $result[2][0]->city);

        self::assertEquals($this->fixtures[0]->address->country, $result[0][0]->country);
        self::assertEquals($this->fixtures[1]->address->country, $result[1][0]->country);
        self::assertEquals($this->fixtures[2]->address->country, $result[2][0]->country);
    }

    public function testShouldSupportMultipleNewOperatorsAndSingleScalar()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email
                ),
                new CmsAddressDTO(
                    a.country,
                    a.city
                ),
                u.status
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0][0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1][0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2][0]);

        self::assertInstanceOf(CmsAddressDTO::class, $result[0][1]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[1][1]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[2][1]);

        self::assertEquals($this->fixtures[0]->name, $result[0][0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1][0]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2][0]->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0][0]->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1][0]->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2][0]->email);


        self::assertEquals($this->fixtures[0]->address->city, $result[0][1]->city);
        self::assertEquals($this->fixtures[1]->address->city, $result[1][1]->city);
        self::assertEquals($this->fixtures[2]->address->city, $result[2][1]->city);

        self::assertEquals($this->fixtures[0]->address->country, $result[0][1]->country);
        self::assertEquals($this->fixtures[1]->address->country, $result[1][1]->country);
        self::assertEquals($this->fixtures[2]->address->country, $result[2][1]->country);

        self::assertEquals($this->fixtures[0]->status, $result[0]['status']);
        self::assertEquals($this->fixtures[1]->status, $result[1]['status']);
        self::assertEquals($this->fixtures[2]->status, $result[2]['status']);
    }

    public function testShouldSupportMultipleNewOperatorsAndSingleScalarWithAliases()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email
                ) as cmsUser,
                new CmsAddressDTO(
                    a.country,
                    a.city
                ) as cmsAddress,
                u.status as cmsUserStatus
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]['cmsUser']);

        self::assertInstanceOf(CmsAddressDTO::class, $result[0]['cmsAddress']);
        self::assertInstanceOf(CmsAddressDTO::class, $result[1]['cmsAddress']);
        self::assertInstanceOf(CmsAddressDTO::class, $result[2]['cmsAddress']);

        self::assertEquals($this->fixtures[0]->name, $result[0]['cmsUser']->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]['cmsUser']->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]['cmsUser']->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]['cmsUser']->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]['cmsUser']->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]['cmsUser']->email);


        self::assertEquals($this->fixtures[0]->address->city, $result[0]['cmsAddress']->city);
        self::assertEquals($this->fixtures[1]->address->city, $result[1]['cmsAddress']->city);
        self::assertEquals($this->fixtures[2]->address->city, $result[2]['cmsAddress']->city);

        self::assertEquals($this->fixtures[0]->address->country, $result[0]['cmsAddress']->country);
        self::assertEquals($this->fixtures[1]->address->country, $result[1]['cmsAddress']->country);
        self::assertEquals($this->fixtures[2]->address->country, $result[2]['cmsAddress']->country);

        self::assertEquals($this->fixtures[0]->status, $result[0]['cmsUserStatus']);
        self::assertEquals($this->fixtures[1]->status, $result[1]['cmsUserStatus']);
        self::assertEquals($this->fixtures[2]->status, $result[2]['cmsUserStatus']);
    }

    public function testShouldSupportMultipleNewOperatorsAndSingleScalarWithAndWithoutAliases()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email
                ) as cmsUser,
                new CmsAddressDTO(
                    a.country,
                    a.city
                ),
                u.status
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]['cmsUser']);

        self::assertInstanceOf(CmsAddressDTO::class, $result[0][0]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[1][0]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[2][0]);

        self::assertEquals($this->fixtures[0]->name, $result[0]['cmsUser']->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]['cmsUser']->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]['cmsUser']->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]['cmsUser']->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]['cmsUser']->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]['cmsUser']->email);


        self::assertEquals($this->fixtures[0]->address->city, $result[0][0]->city);
        self::assertEquals($this->fixtures[1]->address->city, $result[1][0]->city);
        self::assertEquals($this->fixtures[2]->address->city, $result[2][0]->city);

        self::assertEquals($this->fixtures[0]->address->country, $result[0][0]->country);
        self::assertEquals($this->fixtures[1]->address->country, $result[1][0]->country);
        self::assertEquals($this->fixtures[2]->address->country, $result[2][0]->country);

        self::assertEquals($this->fixtures[0]->status, $result[0]['status']);
        self::assertEquals($this->fixtures[1]->status, $result[1]['status']);
        self::assertEquals($this->fixtures[2]->status, $result[2]['status']);
    }

    public function testShouldSupportMultipleNewOperatorsAndMultipleScalars()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email
                ),
                new CmsAddressDTO(
                    a.country,
                    a.city
                ),
                u.status,
                u.username
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0][0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[1][0]);
        self::assertInstanceOf(CmsUserDTO::class, $result[2][0]);

        self::assertInstanceOf(CmsAddressDTO::class, $result[0][1]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[1][1]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[2][1]);

        self::assertEquals($this->fixtures[0]->name, $result[0][0]->name);
        self::assertEquals($this->fixtures[1]->name, $result[1][0]->name);
        self::assertEquals($this->fixtures[2]->name, $result[2][0]->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0][0]->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1][0]->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2][0]->email);


        self::assertEquals($this->fixtures[0]->address->city, $result[0][1]->city);
        self::assertEquals($this->fixtures[1]->address->city, $result[1][1]->city);
        self::assertEquals($this->fixtures[2]->address->city, $result[2][1]->city);

        self::assertEquals($this->fixtures[0]->address->country, $result[0][1]->country);
        self::assertEquals($this->fixtures[1]->address->country, $result[1][1]->country);
        self::assertEquals($this->fixtures[2]->address->country, $result[2][1]->country);

        self::assertEquals($this->fixtures[0]->status, $result[0]['status']);
        self::assertEquals($this->fixtures[1]->status, $result[1]['status']);
        self::assertEquals($this->fixtures[2]->status, $result[2]['status']);

        self::assertEquals($this->fixtures[0]->username, $result[0]['username']);
        self::assertEquals($this->fixtures[1]->username, $result[1]['username']);
        self::assertEquals($this->fixtures[2]->username, $result[2]['username']);
    }

    public function testShouldSupportMultipleNewOperatorsAndMultipleScalarsWithAliases()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email
                ) as cmsUser,
                new CmsAddressDTO(
                    a.country,
                    a.city
                ) as cmsAddress,
                u.status as cmsUserStatus,
                u.username as cmsUserUsername
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]['cmsUser']);

        self::assertInstanceOf(CmsAddressDTO::class, $result[0]['cmsAddress']);
        self::assertInstanceOf(CmsAddressDTO::class, $result[1]['cmsAddress']);
        self::assertInstanceOf(CmsAddressDTO::class, $result[2]['cmsAddress']);

        self::assertEquals($this->fixtures[0]->name, $result[0]['cmsUser']->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]['cmsUser']->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]['cmsUser']->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]['cmsUser']->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]['cmsUser']->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]['cmsUser']->email);


        self::assertEquals($this->fixtures[0]->address->city, $result[0]['cmsAddress']->city);
        self::assertEquals($this->fixtures[1]->address->city, $result[1]['cmsAddress']->city);
        self::assertEquals($this->fixtures[2]->address->city, $result[2]['cmsAddress']->city);

        self::assertEquals($this->fixtures[0]->address->country, $result[0]['cmsAddress']->country);
        self::assertEquals($this->fixtures[1]->address->country, $result[1]['cmsAddress']->country);
        self::assertEquals($this->fixtures[2]->address->country, $result[2]['cmsAddress']->country);

        self::assertEquals($this->fixtures[0]->status, $result[0]['cmsUserStatus']);
        self::assertEquals($this->fixtures[1]->status, $result[1]['cmsUserStatus']);
        self::assertEquals($this->fixtures[2]->status, $result[2]['cmsUserStatus']);

        self::assertEquals($this->fixtures[0]->username, $result[0]['cmsUserUsername']);
        self::assertEquals($this->fixtures[1]->username, $result[1]['cmsUserUsername']);
        self::assertEquals($this->fixtures[2]->username, $result[2]['cmsUserUsername']);
    }

    public function testShouldSupportMultipleNewOperatorsAndMultipleScalarsWithAndWithoutAliases()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email
                ) as cmsUser,
                new CmsAddressDTO(
                    a.country,
                    a.city
                ),
                u.status,
                u.username as cmsUserUsername
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);

        self::assertInstanceOf(CmsUserDTO::class, $result[0]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[1]['cmsUser']);
        self::assertInstanceOf(CmsUserDTO::class, $result[2]['cmsUser']);

        self::assertInstanceOf(CmsAddressDTO::class, $result[0][0]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[1][0]);
        self::assertInstanceOf(CmsAddressDTO::class, $result[2][0]);

        self::assertEquals($this->fixtures[0]->name, $result[0]['cmsUser']->name);
        self::assertEquals($this->fixtures[1]->name, $result[1]['cmsUser']->name);
        self::assertEquals($this->fixtures[2]->name, $result[2]['cmsUser']->name);

        self::assertEquals($this->fixtures[0]->email->email, $result[0]['cmsUser']->email);
        self::assertEquals($this->fixtures[1]->email->email, $result[1]['cmsUser']->email);
        self::assertEquals($this->fixtures[2]->email->email, $result[2]['cmsUser']->email);


        self::assertEquals($this->fixtures[0]->address->city, $result[0][0]->city);
        self::assertEquals($this->fixtures[1]->address->city, $result[1][0]->city);
        self::assertEquals($this->fixtures[2]->address->city, $result[2][0]->city);

        self::assertEquals($this->fixtures[0]->address->country, $result[0][0]->country);
        self::assertEquals($this->fixtures[1]->address->country, $result[1][0]->country);
        self::assertEquals($this->fixtures[2]->address->country, $result[2][0]->country);

        self::assertEquals($this->fixtures[0]->status, $result[0]['status']);
        self::assertEquals($this->fixtures[1]->status, $result[1]['status']);
        self::assertEquals($this->fixtures[2]->status, $result[2]['status']);

        self::assertEquals($this->fixtures[0]->username, $result[0]['cmsUserUsername']);
        self::assertEquals($this->fixtures[1]->username, $result[1]['cmsUserUsername']);
        self::assertEquals($this->fixtures[2]->username, $result[2]['cmsUserUsername']);
    }

    /**
     * @expectedException Doctrine\ORM\Query\QueryException
     * @expectedExceptionMessage [Semantical Error] line 0, col 11 near '\InvalidClass(u.name)': Error: Class "\InvalidClass" is not defined.
     */
    public function testInvalidClassException()
    {
        $dql = "SELECT new \InvalidClass(u.name) FROM Doctrine\Tests\Models\CMS\CmsUser u";
        $this->em->createQuery($dql)->getResult();
    }

    /**
     * @expectedException Doctrine\ORM\Query\QueryException
     * @expectedExceptionMessage [Semantical Error] line 0, col 11 near '\stdClass(u.name)': Error: Class "\stdClass" has not a valid constructor.
     */
    public function testInvalidClassConstructorException()
    {
        $dql = "SELECT new \stdClass(u.name) FROM Doctrine\Tests\Models\CMS\CmsUser u";
        $this->em->createQuery($dql)->getResult();
    }

    /**
     * @expectedException Doctrine\ORM\Query\QueryException
     * @expectedExceptionMessage [Semantical Error] line 0, col 11 near 'Doctrine\Tests\ORM\Functional\ClassWithTooMuchArgs(u.name)': Error: Number of arguments does not match with "Doctrine\Tests\ORM\Functional\ClassWithTooMuchArgs" constructor declaration.
     */
    public function testInvalidClassWithoutConstructorException()
    {
        $dql = "SELECT new Doctrine\Tests\ORM\Functional\ClassWithTooMuchArgs(u.name) FROM Doctrine\Tests\Models\CMS\CmsUser u";
        $this->em->createQuery($dql)->getResult();
    }

    /**
     * @expectedException Doctrine\ORM\Query\QueryException
     * @expectedExceptionMessage [Semantical Error] line 0, col 11 near 'Doctrine\Tests\ORM\Functional\ClassWithPrivateConstructor(u.name)': Error: Class "Doctrine\Tests\ORM\Functional\ClassWithPrivateConstructor" can not be instantiated.
     */
    public function testClassCantBeInstantiatedException()
    {
        $dql = "SELECT new Doctrine\Tests\ORM\Functional\ClassWithPrivateConstructor(u.name) FROM Doctrine\Tests\Models\CMS\CmsUser u";
        $this->em->createQuery($dql)->getResult();
    }

    public function testShouldSupportNestedNewOperators()
    {
        $dql = "
            SELECT
                new CmsUserDTO(
                    u.name,
                    e.email,
                    new CmsAddressDTO(
                        a.country,
                        a.city,
                        new CmsAddressDTO(
                            a.country,
                            a.city
                        )
                    )
                ) as user,
                u.status,
                u.username as cmsUserUsername
            FROM
                Doctrine\Tests\Models\CMS\CmsUser u
            JOIN
                u.email e
            JOIN
                u.address a
            ORDER BY
                u.name";

        $query  = $this->getEntityManager()->createQuery($dql);
        $result = $query->getResult();

        $this->assertCount(3, $result);

        $this->assertInstanceOf(CmsUserDTO::class, $result[0]['user']);
        $this->assertInstanceOf(CmsUserDTO::class, $result[1]['user']);
        $this->assertInstanceOf(CmsUserDTO::class, $result[2]['user']);

        $this->assertInstanceOf(CmsAddressDTO::class, $result[0]['user']->address);
        $this->assertInstanceOf(CmsAddressDTO::class, $result[1]['user']->address);
        $this->assertInstanceOf(CmsAddressDTO::class, $result[2]['user']->address);

        $this->assertEquals($this->fixtures[0]->name, $result[0]['user']->name);
        $this->assertEquals($this->fixtures[1]->name, $result[1]['user']->name);
        $this->assertEquals($this->fixtures[2]->name, $result[2]['user']->name);

        $this->assertEquals($this->fixtures[0]->email->email, $result[0]['user']->email);
        $this->assertEquals($this->fixtures[1]->email->email, $result[1]['user']->email);
        $this->assertEquals($this->fixtures[2]->email->email, $result[2]['user']->email);

        $this->assertEquals($this->fixtures[0]->address->city, $result[0]['user']->address->city);
        $this->assertEquals($this->fixtures[1]->address->city, $result[1]['user']->address->city);
        $this->assertEquals($this->fixtures[2]->address->city, $result[2]['user']->address->city);

        $this->assertEquals($this->fixtures[0]->address->country, $result[0]['user']->address->country);
        $this->assertEquals($this->fixtures[1]->address->country, $result[1]['user']->address->country);
        $this->assertEquals($this->fixtures[2]->address->country, $result[2]['user']->address->country);

        $this->assertEquals($this->fixtures[0]->status,$result[0]['status']);
        $this->assertEquals($this->fixtures[1]->status,$result[1]['status']);
        $this->assertEquals($this->fixtures[2]->status,$result[2]['status']);

        $this->assertEquals($this->fixtures[0]->username,$result[0]['cmsUserUsername']);
        $this->assertEquals($this->fixtures[1]->username,$result[1]['cmsUserUsername']);
        $this->assertEquals($this->fixtures[2]->username,$result[2]['cmsUserUsername']);
    }

    private function dumpResultSetMapping(Query $query)
    {
        $rsm = (\Closure::bind(function ($q) {
            return $q->getResultSetMapping();
        }, null, Query::class))($query);
        echo json_encode(get_object_vars($rsm), JSON_PRETTY_PRINT);
    }
}

class ClassWithTooMuchArgs
{
    public function __construct($foo, $bar)
    {
        $this->foo = $foo;
        $this->bor = $bar;
    }
}

class ClassWithPrivateConstructor
{
    private function __construct($foo)
    {
        $this->foo = $foo;
    }
}
