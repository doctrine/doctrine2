<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQL100Platform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServer2012Platform;
use Doctrine\DBAL\Types\Type as DBALType;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\Query as ORMQuery;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\Tests\DbalTypes\NegativeToPositiveType;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\Company\CompanyEmployee;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\OrmTestCase;
use Exception;
use function get_class;

class SelectSqlGenerationTest extends OrmTestCase
{
    private $em;

    protected function setUp() : void
    {
        $this->em = $this->getTestEntityManager();
    }

    /**
     * Assert a valid SQL generation.
     *
     * @param string $dqlToBeTested
     * @param string $sqlToBeConfirmed
     * @param array  $queryHints
     * @param array  $queryParams
     */
    public function assertSqlGeneration($dqlToBeTested, $sqlToBeConfirmed, array $queryHints = [], array $queryParams = [])
    {
        try {
            $query = $this->em->createQuery($dqlToBeTested);

            foreach ($queryParams as $name => $value) {
                $query->setParameter($name, $value);
            }

            $query
                ->setHint(ORMQuery::HINT_FORCE_PARTIAL_LOAD, true)
                ->useQueryCache(false);

            foreach ($queryHints as $name => $value) {
                $query->setHint($name, $value);
            }

            $sqlGenerated = $query->getSQL();

            $query->free();
        } catch (Exception $e) {
            $this->fail($e->getMessage() . "\n" . $e->getTraceAsString());
        }

        self::assertEquals($sqlToBeConfirmed, $sqlGenerated);
    }

    /**
     * Asser an invalid SQL generation.
     *
     * @param string $dqlToBeTested
     * @param string $expectedException
     * @param array  $queryHints
     * @param array  $queryParams
     */
    public function assertInvalidSqlGeneration($dqlToBeTested, $expectedException, array $queryHints = [], array $queryParams = [])
    {
        $this->expectException($expectedException);

        $query = $this->em->createQuery($dqlToBeTested);

        foreach ($queryParams as $name => $value) {
            $query->setParameter($name, $value);
        }

        $query
            ->setHint(ORMQuery::HINT_FORCE_PARTIAL_LOAD, true)
            ->useQueryCache(false);

        foreach ($queryHints as $name => $value) {
            $query->setHint($name, $value);
        }

        $sql = $query->getSql();
        $query->free();

        // If we reached here, test failed
        $this->fail($sql);
    }

    /**
     * @group DDC-3697
     */
    public function testJoinWithRangeVariablePutsConditionIntoSqlWhereClause() : void
    {
        $this->assertSqlGeneration(
            'SELECT c.id FROM Doctrine\Tests\Models\Company\CompanyPerson c JOIN Doctrine\Tests\Models\Company\CompanyPerson r WHERE c.spouse = r AND r.id = 42',
            'SELECT t0."id" AS c0 FROM "company_persons" t0 INNER JOIN "company_persons" t1 WHERE t0."spouse_id" = t1."id" AND t1."id" = 42',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    /**
     * @group DDC-3697
     */
    public function testJoinWithRangeVariableAndInheritancePutsConditionIntoSqlWhereClause() : void
    {
        /*
         * Basically like the previous test, but this time load data for the inherited objects as well.
         * The important thing is that the ON clauses in LEFT JOINs only contain the conditions necessary to join the appropriate inheritance table
         * whereas the filtering condition must remain in the SQL WHERE clause.
         */
        $this->assertSqlGeneration(
            'SELECT c.id FROM Doctrine\Tests\Models\Company\CompanyPerson c JOIN Doctrine\Tests\Models\Company\CompanyPerson r WHERE c.spouse = r AND r.id = 42',
            'SELECT t0."id" AS c0 FROM "company_persons" t0 LEFT JOIN "company_managers" t1 ON t0."id" = t1."id" LEFT JOIN "company_employees" t2 ON t0."id" = t2."id" INNER JOIN "company_persons" t3 LEFT JOIN "company_managers" t4 ON t3."id" = t4."id" LEFT JOIN "company_employees" t5 ON t3."id" = t5."id" WHERE t0."spouse_id" = t3."id" AND t3."id" = 42',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => false]
        );
    }

    public function testSupportsSelectForAllFields() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0'
        );
    }

    public function testSupportsSelectForOneField() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT t0."id" AS c0 FROM "cms_users" t0'
        );
    }

    public function testSupportsSelectForOneNestedField() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsArticle a JOIN a.user u',
            'SELECT t0."id" AS c0 FROM "cms_articles" t1 INNER JOIN "cms_users" t0 ON t1."user_id" = t0."id"'
        );
    }

    public function testSupportsSelectForAllNestedField() : void
    {
        $this->assertSqlGeneration(
            'SELECT a FROM Doctrine\Tests\Models\CMS\CmsArticle a JOIN a.user u ORDER BY u.name ASC',
            'SELECT t0."id" AS c0, t0."topic" AS c1, t0."text" AS c2, t0."version" AS c3 FROM "cms_articles" t0 INNER JOIN "cms_users" t1 ON t0."user_id" = t1."id" ORDER BY t1."name" ASC'
        );
    }

    public function testNotExistsExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE NOT EXISTS (SELECT p.phonenumber FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE p.phonenumber = 1234)',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE NOT EXISTS (SELECT t1."phonenumber" FROM "cms_phonenumbers" t1 WHERE t1."phonenumber" = 1234)'
        );
    }

    public function testSupportsSelectForMultipleColumnsOfASingleComponent() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.username, u.name FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT t0."username" AS c0, t0."name" AS c1 FROM "cms_users" t0'
        );
    }

    public function testSupportsSelectUsingMultipleFromComponents() : void
    {
        $this->assertSqlGeneration(
            'SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE u = p.user',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, t1."phonenumber" AS c4 FROM "cms_users" t0, "cms_phonenumbers" t1 WHERE t0."id" = t1."user_id"'
        );
    }

    public function testSupportsJoinOnMultipleComponents() : void
    {
        $this->assertSqlGeneration(
            'SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN Doctrine\Tests\Models\CMS\CmsPhonenumber p WITH u = p.user',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, t1."phonenumber" AS c4 FROM "cms_users" t0 INNER JOIN "cms_phonenumbers" t1 ON (t0."id" = t1."user_id")'
        );
    }

    public function testSupportsJoinOnMultipleComponentsWithJoinedInheritanceType() : void
    {
        $this->assertSqlGeneration(
            'SELECT e FROM Doctrine\Tests\Models\Company\CompanyEmployee e JOIN Doctrine\Tests\Models\Company\CompanyManager m WITH e.id = m.id',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t0."discr" AS c5 FROM "company_employees" t1 INNER JOIN "company_persons" t0 ON t1."id" = t0."id" INNER JOIN ("company_managers" t2 INNER JOIN "company_employees" t4 ON t2."id" = t4."id" INNER JOIN "company_persons" t3 ON t2."id" = t3."id") ON (t0."id" = t3."id")'
        );

        $this->assertSqlGeneration(
            'SELECT e FROM Doctrine\Tests\Models\Company\CompanyEmployee e LEFT JOIN Doctrine\Tests\Models\Company\CompanyManager m WITH e.id = m.id',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t0."discr" AS c5 FROM "company_employees" t1 INNER JOIN "company_persons" t0 ON t1."id" = t0."id" LEFT JOIN ("company_managers" t2 INNER JOIN "company_employees" t4 ON t2."id" = t4."id" INNER JOIN "company_persons" t3 ON t2."id" = t3."id") ON (t0."id" = t3."id")'
        );

        $this->assertSqlGeneration(
            'SELECT c FROM Doctrine\Tests\Models\Company\CompanyContract c JOIN c.salesPerson s LEFT JOIN Doctrine\Tests\Models\Company\CompanyEvent e WITH s.id = e.id',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."fixPrice" AS c2, t0."hoursWorked" AS c3, t0."pricePerHour" AS c4, t0."maxPrice" AS c5, t0."discr" AS c6 FROM "company_contracts" t0 INNER JOIN "company_employees" t1 ON t0."salesPerson_id" = t1."id" LEFT JOIN "company_persons" t2 ON t1."id" = t2."id" LEFT JOIN "company_events" t3 ON (t2."id" = t3."id") WHERE t0."discr" IN (\'fix\', \'flexible\', \'flexultra\')'
        );
    }

    public function testSupportsSelectWithCollectionAssociationJoin() : void
    {
        $this->assertSqlGeneration(
            'SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.phonenumbers p',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, t1."phonenumber" AS c4 FROM "cms_users" t0 INNER JOIN "cms_phonenumbers" t1 ON t0."id" = t1."user_id"'
        );
    }

    public function testSupportsSelectWithSingleValuedAssociationJoin() : void
    {
        $this->assertSqlGeneration(
            'SELECT u, a FROM Doctrine\Tests\Models\Forum\ForumUser u JOIN u.avatar a',
            'SELECT t0."id" AS c0, t0."username" AS c1, t1."id" AS c2 FROM "forum_users" t0 INNER JOIN "forum_avatars" t1 ON t0."avatar_id" = t1."id"'
        );
    }

    public function testSelectCorrelatedSubqueryComplexMathematicalExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT (SELECT (count(p.phonenumber)+5)*10 FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p JOIN p.user ui WHERE ui.id = u.id) AS c FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT (SELECT (count(t0."phonenumber") + 5) * 10 AS c1 FROM "cms_phonenumbers" t0 INNER JOIN "cms_users" t1 ON t0."user_id" = t1."id" WHERE t1."id" = t2."id") AS c0 FROM "cms_users" t2'
        );
    }

    public function testSelectComplexMathematicalExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT (count(p.phonenumber)+5)*10 FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p JOIN p.user ui WHERE ui.id = ?1',
            'SELECT (count(t0."phonenumber") + 5) * 10 AS c0 FROM "cms_phonenumbers" t0 INNER JOIN "cms_users" t1 ON t0."user_id" = t1."id" WHERE t1."id" = ?'
        );
    }

    /* NOT (YET?) SUPPORTED.
       Can be supported if SimpleSelectExpression supports SingleValuedPathExpression instead of StateFieldPathExpression.

    public function testSingleAssociationPathExpressionInSubselect() : void
    {
        $this->assertSqlGeneration(
            'SELECT (SELECT p.user FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE p.user = u) user_id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1',
            'SELECT (SELECT t0."user_id" FROM "cms_phonenumbers" t0 WHERE t0."user_id" = t1."id") AS c0 FROM "cms_users" t1 WHERE t1."id" = ?'
        );
    }*/

    /**
     * @group DDC-1077
     */
    public function testConstantValueInSelect() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name, \'foo\' AS bar FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT t0."name" AS c0, \'foo\' AS c1 FROM "cms_users" t0'
        );
    }

    public function testSupportsOrderByWithAscAsDefault() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Forum\ForumUser u ORDER BY u.id',
            'SELECT t0."id" AS c0, t0."username" AS c1 FROM "forum_users" t0 ORDER BY t0."id" ASC'
        );
    }

    public function testSupportsOrderByAsc() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Forum\ForumUser u ORDER BY u.id asc',
            'SELECT t0."id" AS c0, t0."username" AS c1 FROM "forum_users" t0 ORDER BY t0."id" ASC'
        );
    }

    public function testSupportsOrderByDesc() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Forum\ForumUser u ORDER BY u.id desc',
            'SELECT t0."id" AS c0, t0."username" AS c1 FROM "forum_users" t0 ORDER BY t0."id" DESC'
        );
    }

    public function testSupportsSelectDistinct() : void
    {
        $this->assertSqlGeneration(
            'SELECT DISTINCT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT DISTINCT t0."name" AS c0 FROM "cms_users" t0'
        );
    }

    public function testSupportsAggregateFunctionInSelectedFields() : void
    {
        $this->assertSqlGeneration(
            'SELECT COUNT(u.id) FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id',
            'SELECT COUNT(t0."id") AS c0 FROM "cms_users" t0 GROUP BY t0."id"'
        );
    }

    public function testSupportsAggregateFunctionWithSimpleArithmetic() : void
    {
        $this->assertSqlGeneration(
            'SELECT MAX(u.id + 4) * 2 FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT MAX(t0."id" + 4) * 2 AS c0 FROM "cms_users" t0'
        );
    }

    /**
     * @group DDC-3276
     */
    public function testSupportsAggregateCountFunctionWithSimpleArithmeticMySql() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new MySqlPlatform());

        $this->assertSqlGeneration(
            'SELECT COUNT(CONCAT(u.id, u.name)) FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id',
            'SELECT COUNT(CONCAT(t0.`id`, t0.`name`)) AS c0 FROM `cms_users` t0 GROUP BY t0.`id`'
        );
    }

    public function testSupportsWhereClauseWithPositionalParameter() : void
    {
        $this->assertSqlGeneration(
            'select u from Doctrine\Tests\Models\Forum\ForumUser u where u.id = ?1',
            'SELECT t0."id" AS c0, t0."username" AS c1 FROM "forum_users" t0 WHERE t0."id" = ?'
        );
    }

    public function testSupportsWhereClauseWithNamedParameter() : void
    {
        $this->assertSqlGeneration(
            'select u from Doctrine\Tests\Models\Forum\ForumUser u where u.username = :name',
            'SELECT t0."id" AS c0, t0."username" AS c1 FROM "forum_users" t0 WHERE t0."username" = ?'
        );
    }

    public function testSupportsWhereAndClauseWithNamedParameters() : void
    {
        $this->assertSqlGeneration(
            'select u from Doctrine\Tests\Models\Forum\ForumUser u where u.username = :name and u.username = :name2',
            'SELECT t0."id" AS c0, t0."username" AS c1 FROM "forum_users" t0 WHERE t0."username" = ? AND t0."username" = ?'
        );
    }

    public function testSupportsCombinedWhereClauseWithNamedParameter() : void
    {
        $this->assertSqlGeneration(
            'select u from Doctrine\Tests\Models\Forum\ForumUser u where (u.username = :name OR u.username = :name2) AND u.id = :id',
            'SELECT t0."id" AS c0, t0."username" AS c1 FROM "forum_users" t0 WHERE (t0."username" = ? OR t0."username" = ?) AND t0."id" = ?'
        );
    }

    public function testSupportsAggregateFunctionInASelectDistinct() : void
    {
        $this->assertSqlGeneration(
            'SELECT COUNT(DISTINCT u.name) FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT COUNT(DISTINCT t0."name") AS c0 FROM "cms_users" t0'
        );
    }

    /** Ticket #668 */
    public function testSupportsASqlKeywordInAStringLiteralParam() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name LIKE \'%foo OR bar%\'',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE t0."name" LIKE \'%foo OR bar%\''
        );
    }

    public function testSupportsArithmeticExpressionsInWherePart() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE ((u.id + 5000) * u.id + 3) < 10000000',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE ((t0."id" + 5000) * t0."id" + 3) < 10000000'
        );
    }

    public function testSupportsMultipleEntitiesInFromClause() : void
    {
        $this->assertSqlGeneration(
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a JOIN a.user u2 WHERE u.id = u2.id',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, t1."id" AS c4, t1."topic" AS c5, t1."text" AS c6, t1."version" AS c7 FROM "cms_users" t0, "cms_articles" t1 INNER JOIN "cms_users" t2 ON t1."user_id" = t2."id" WHERE t0."id" = t2."id"'
        );
    }

    public function testSupportsMultipleEntitiesInFromClauseUsingPathExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a WHERE u.id = a.user',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, t1."id" AS c4, t1."topic" AS c5, t1."text" AS c6, t1."version" AS c7 FROM "cms_users" t0, "cms_articles" t1 WHERE t0."id" = t1."user_id"'
        );
    }

    public function testSupportsPlainJoinWithoutClause() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.id, a.id from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a',
            'SELECT t0."id" AS c0, t1."id" AS c1 FROM "cms_users" t0 LEFT JOIN "cms_articles" t1 ON t0."id" = t1."user_id"'
        );

        $this->assertSqlGeneration(
            'SELECT u.id, a.id from Doctrine\Tests\Models\CMS\CmsUser u JOIN u.articles a',
            'SELECT t0."id" AS c0, t1."id" AS c1 FROM "cms_users" t0 INNER JOIN "cms_articles" t1 ON t0."id" = t1."user_id"'
        );
    }

    /**
     * @group DDC-135
     */
    public function testSupportsLeftJoinAndWithClauseRestriction() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a WITH a.topic LIKE \'%foo%\'',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 LEFT JOIN "cms_articles" t1 ON t0."id" = t1."user_id" AND (t1."topic" LIKE \'%foo%\')'
        );
    }

    /**
     * @group DDC-135
     */
    public function testSupportsInnerJoinAndWithClauseRestriction() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a WITH a.topic LIKE \'%foo%\'',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 INNER JOIN "cms_articles" t1 ON t0."id" = t1."user_id" AND (t1."topic" LIKE \'%foo%\')'
        );
    }

    /**
     * @group DDC-135
     * @group DDC-177
     */
    public function testJoinOnClauseNotYetSupportedThrowsException() : void
    {
        $this->expectException(QueryException::class);

        $query = $this->em->createQuery(
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a ON a.topic LIKE \'%foo%\''
        );

        $query->getSql();
    }

    public function testSupportsMultipleJoins() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.id, a.id, p.phonenumber, c.id from Doctrine\Tests\Models\CMS\CmsUser u JOIN u.articles a JOIN u.phonenumbers p JOIN a.comments c',
            'SELECT t0."id" AS c0, t1."id" AS c1, t2."phonenumber" AS c2, t3."id" AS c3 FROM "cms_users" t0 INNER JOIN "cms_articles" t1 ON t0."id" = t1."user_id" INNER JOIN "cms_phonenumbers" t2 ON t0."id" = t2."user_id" INNER JOIN "cms_comments" t3 ON t1."id" = t3."article_id"'
        );
    }

    public function testSupportsTrimFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE TRIM(TRAILING \' \' FROM u.name) = \'someone\'',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE TRIM(TRAILING \' \' FROM t0."name") = \'someone\''
        );
    }

    /**
     * @group DDC-2668
     */
    public function testSupportsTrimLeadingZeroString() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE TRIM(TRAILING \'0\' FROM u.name) != \'\'',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE TRIM(TRAILING \'0\' FROM t0."name") <> \'\''
        );
    }

    /** Ticket 894 */
    public function testSupportsBetweenClauseWithPositionalParameters() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id BETWEEN ?1 AND ?2',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE t0."id" BETWEEN ? AND ?'
        );
    }

    /**
     * @group DDC-1802
     */
    public function testSupportsNotBetweenForSizeFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT m.name FROM Doctrine\Tests\Models\StockExchange\Market m WHERE SIZE(m.stocks) NOT BETWEEN ?1 AND ?2',
            'SELECT t0."name" AS c0 FROM "exchange_markets" t0 WHERE (SELECT COUNT(*) FROM "exchange_stocks" t1 WHERE t1."market_id" = t0."id") NOT BETWEEN ? AND ?'
        );
    }

    public function testSupportsFunctionalExpressionsInWherePart() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE TRIM(u.name) = \'someone\'',
            // String quoting in the SQL usually depends on the database platform.
            // This test works with a mock connection which uses ' for string quoting.
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE TRIM(t0."name") = \'someone\''
        );
    }

    public function testSupportsInstanceOfExpressionsInWherePart() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Company\CompanyPerson u WHERE u INSTANCE OF Doctrine\Tests\Models\Company\CompanyEmployee',
            'SELECT t0."id" AS c0, t0."name" AS c1, t0."discr" AS c2 FROM "company_persons" t0 WHERE t0."discr" IN (\'manager\', \'employee\')'
        );
    }

    public function testSupportsInstanceOfExpressionInWherePartWithMultipleValues() : void
    {
        // This also uses FQCNs starting with or without a backslash in the INSTANCE OF parameter
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Company\CompanyPerson u WHERE u INSTANCE OF (Doctrine\Tests\Models\Company\CompanyEmployee, \Doctrine\Tests\Models\Company\CompanyManager)',
            'SELECT t0."id" AS c0, t0."name" AS c1, t0."discr" AS c2 FROM "company_persons" t0 WHERE t0."discr" IN (\'manager\', \'employee\')'
        );
    }

    /**
     * @group DDC-1194
     */
    public function testSupportsInstanceOfExpressionsInWherePartPrefixedSlash() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Company\CompanyPerson u WHERE u INSTANCE OF \Doctrine\Tests\Models\Company\CompanyEmployee',
            'SELECT t0."id" AS c0, t0."name" AS c1, t0."discr" AS c2 FROM "company_persons" t0 WHERE t0."discr" IN (\'manager\', \'employee\')'
        );
    }

    /**
     * @group DDC-1194
     */
    public function testSupportsInstanceOfExpressionsInWherePartWithUnrelatedClass() : void
    {
        $this->assertInvalidSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Company\CompanyPerson u WHERE u INSTANCE OF \Doctrine\Tests\Models\CMS\CmsUser',
            'Doctrine\\ORM\\Query\\QueryException'
        );
    }

    public function testSupportsInstanceOfExpressionsInWherePartInDeeperLevel() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Company\CompanyEmployee u WHERE u INSTANCE OF Doctrine\Tests\Models\Company\CompanyManager',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t0."discr" AS c5 FROM "company_employees" t1 INNER JOIN "company_persons" t0 ON t1."id" = t0."id" WHERE t0."discr" IN (\'manager\')'
        );
    }

    public function testSupportsInstanceOfExpressionsInWherePartInDeepestLevel() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Company\CompanyManager u WHERE u INSTANCE OF Doctrine\Tests\Models\Company\CompanyManager',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t2."title" AS c5, t0."discr" AS c6 FROM "company_managers" t2 INNER JOIN "company_employees" t1 ON t2."id" = t1."id" INNER JOIN "company_persons" t0 ON t2."id" = t0."id" WHERE t0."discr" IN (\'manager\')'
        );
    }

    public function testSupportsInstanceOfExpressionsUsingInputParameterInWherePart() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Company\CompanyPerson u WHERE u INSTANCE OF ?1',
            'SELECT t0."id" AS c0, t0."name" AS c1, t0."discr" AS c2 FROM "company_persons" t0 WHERE t0."discr" IN (?)',
            [],
            [1 => $this->em->getClassMetadata(CompanyEmployee::class)]
        );
    }

    /** Ticket #973 */
    public function testSupportsSingleValuedInExpressionWithoutSpacesInWherePart() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE IDENTITY(u.email) IN(46)',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE t0."email_id" IN (46)'
        );
    }

    public function testSupportsMultipleValuedInExpressionInWherePart() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id IN (1, 2)',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."id" IN (1, 2)'
        );
    }

    public function testSupportsNotInExpressionInWherePart() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE :id NOT IN (1)',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE ? NOT IN (1)'
        );
    }

    /**
     * @group DDC-1802
     */
    public function testSupportsNotInExpressionForModFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE MOD(u.id, 5) NOT IN(1,3,4)',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE MOD(t0."id", 5) NOT IN (1, 3, 4)'
        );
    }

    public function testInExpressionWithSingleValuedAssociationPathExpressionInWherePart() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Forum\ForumUser u WHERE u.avatar IN (?1, ?2)',
            'SELECT t0."id" AS c0, t0."username" AS c1 FROM "forum_users" t0 WHERE t0."avatar_id" IN (?, ?)'
        );
    }

    public function testCustomFunctionWithinInExpression() : void
    {
        $this->em->getConfiguration()->addCustomStringFunction('FOO', MyAbsFunction::class);
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Forum\ForumUser u WHERE u.username IN (FOO(\'Lo\'), \'Lo\', :name)',
            'SELECT t0."id" AS c0, t0."username" AS c1 FROM "forum_users" t0 WHERE t0."username" IN (ABS(\'Lo\'), \'Lo\', ?)'
        );
    }

    public function testInvalidInExpressionWithSingleValuedAssociationPathExpressionOnInverseSide() : void
    {
        // We do not support SingleValuedAssociationPathExpression on inverse side
        $this->assertInvalidSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.address IN (?1, ?2)',
            'Doctrine\ORM\Query\QueryException'
        );
    }

    public function testSupportsConcatFunctionMysql() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new MySqlPlatform());

        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE CONCAT(u.name, \'s\') = ?1',
            'SELECT t0.`id` AS c0 FROM `cms_users` t0 WHERE CONCAT(t0.`name`, \'s\') = ?'
        );

        $this->assertSqlGeneration(
            'SELECT CONCAT(u.id, u.name) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1',
            'SELECT CONCAT(t0.`id`, t0.`name`) AS c0 FROM `cms_users` t0 WHERE t0.`id` = ?'
        );
    }

    public function testSupportsConcatFunctionPgSql94() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new PostgreSQL94Platform());

        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE CONCAT(u.name, \'s\') = ?1',
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE t0."name" || \'s\' = ?'
        );

        $this->assertSqlGeneration(
            'SELECT CONCAT(u.id, u.name) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1',
            'SELECT t0."id" || t0."name" AS c0 FROM "cms_users" t0 WHERE t0."id" = ?'
        );
    }

    public function testSupportsConcatFunctionPgSql100() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new PostgreSQL100Platform());

        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE CONCAT(u.name, \'s\') = ?1',
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE t0."name" || \'s\' = ?'
        );

        $this->assertSqlGeneration(
            'SELECT CONCAT(u.id, u.name) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1',
            'SELECT t0."id" || t0."name" AS c0 FROM "cms_users" t0 WHERE t0."id" = ?'
        );
    }

    public function testSupportsExistsExpressionInWherePartWithCorrelatedSubquery() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE EXISTS (SELECT p.phonenumber FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE p.phonenumber = u.id)',
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE EXISTS (SELECT t1."phonenumber" FROM "cms_phonenumbers" t1 WHERE t1."phonenumber" = t0."id")'
        );
    }

    /**
     * @group DDC-593
     */
    public function testSubqueriesInComparisonExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE (u.id >= (SELECT u2.id FROM Doctrine\Tests\Models\CMS\CmsUser u2 WHERE u2.name = :name)) AND (u.id <= (SELECT u3.id FROM Doctrine\Tests\Models\CMS\CmsUser u3 WHERE u3.name = :name))',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE (t0."id" >= (SELECT t1."id" FROM "cms_users" t1 WHERE t1."name" = ?)) AND (t0."id" <= (SELECT t2."id" FROM "cms_users" t2 WHERE t2."name" = ?))'
        );
    }

    public function testSupportsMemberOfExpressionOneToMany() : void
    {
        // "Get all users who have $phone as a phonenumber." (*cough* doesnt really make sense...)
        $q = $this->em->createQuery('SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE :param MEMBER OF u.phonenumbers');

        $q->setHint(ORMQuery::HINT_FORCE_PARTIAL_LOAD, true);

        $phone              = new CmsPhonenumber();
        $phone->phonenumber = 101;
        $q->setParameter('param', $phone);

        self::assertEquals(
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE EXISTS (SELECT 1 FROM "cms_phonenumbers" t1 WHERE t0."id" = t1."user_id" AND t1."phonenumber" = ?)',
            $q->getSql()
        );
    }

    public function testSupportsMemberOfExpressionManyToMany() : void
    {
        // "Get all users who are members of $group."
        $q = $this->em->createQuery('SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE :param MEMBER OF u.groups');

        $q->setHint(ORMQuery::HINT_FORCE_PARTIAL_LOAD, true);

        $group     = new CmsGroup();
        $group->id = 101;
        $q->setParameter('param', $group);

        self::assertEquals(
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE EXISTS (SELECT 1 FROM "cms_users_groups" t1 INNER JOIN "cms_groups" t2 ON t1."group_id" = t2."id" WHERE t1."user_id" = t0."id" AND t2."id" = ?)',
            $q->getSql()
        );
    }

    public function testSupportsMemberOfExpressionManyToManyParameterArray() : void
    {
        $q = $this->em->createQuery('SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE :param MEMBER OF u.groups');
        $q->setHint(ORMQuery::HINT_FORCE_PARTIAL_LOAD, true);

        $group      = new CmsGroup();
        $group->id  = 101;
        $group2     = new CmsGroup();
        $group2->id = 105;
        $q->setParameter('param', [$group, $group2]);

        self::assertEquals(
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE EXISTS (SELECT 1 FROM "cms_users_groups" t1 INNER JOIN "cms_groups" t2 ON t1."group_id" = t2."id" WHERE t1."user_id" = t0."id" AND t2."id" = ?)',
            $q->getSql()
        );
    }

    public function testSupportsMemberOfExpressionSelfReferencing() : void
    {
        // "Get all persons who have $person as a friend."
        // Tough one: Many-many self-referencing ("friends") with class table inheritance
        $q = $this->em->createQuery('SELECT p FROM Doctrine\Tests\Models\Company\CompanyPerson p WHERE :param MEMBER OF p.friends');

        $person = new CompanyPerson();

        $unitOfWork = $this->em->getUnitOfWork();
        $persister  = $unitOfWork->getEntityPersister(get_class($person));

        $persister->setIdentifier($person, ['id' => 101]);

        $q->setParameter('param', $person);

        self::assertEquals(
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."title" AS c2, t2."salary" AS c3, t2."department" AS c4, t2."startDate" AS c5, t0."discr" AS c6, t0."spouse_id" AS c7, t1."car_id" AS c8 FROM "company_persons" t0 LEFT JOIN "company_managers" t1 ON t0."id" = t1."id" LEFT JOIN "company_employees" t2 ON t0."id" = t2."id" WHERE EXISTS (SELECT 1 FROM "company_persons_friends" t3 INNER JOIN "company_persons" t4 ON t3."friend_id" = t4."id" WHERE t3."person_id" = t0."id" AND t4."id" = ?)',
            $q->getSql()
        );
    }

    public function testSupportsMemberOfWithSingleValuedAssociation() : void
    {
        // Impossible example, but it illustrates the purpose
        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.email MEMBER OF u.groups',
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE EXISTS (SELECT 1 FROM "cms_users_groups" t1 INNER JOIN "cms_groups" t2 ON t1."group_id" = t2."id" WHERE t1."user_id" = t0."id" AND t2."id" = t0."email_id")'
        );
    }

    public function testSupportsMemberOfWithIdentificationVariable() : void
    {
        // Impossible example, but it illustrates the purpose
        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u MEMBER OF u.groups',
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE EXISTS (SELECT 1 FROM "cms_users_groups" t1 INNER JOIN "cms_groups" t2 ON t1."group_id" = t2."id" WHERE t1."user_id" = t0."id" AND t2."id" = t0."id")'
        );
    }

    public function testSupportsCurrentDateFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT d.id FROM Doctrine\Tests\Models\Generic\DateTimeModel d WHERE d.datetime > current_date()',
            'SELECT t0."id" AS c0 FROM "date_time_model" t0 WHERE t0."col_datetime" > CURRENT_DATE',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    public function testSupportsCurrentTimeFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT d.id FROM Doctrine\Tests\Models\Generic\DateTimeModel d WHERE d.time > current_time()',
            'SELECT t0."id" AS c0 FROM "date_time_model" t0 WHERE t0."col_time" > CURRENT_TIME',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    public function testSupportsCurrentTimestampFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT d.id FROM Doctrine\Tests\Models\Generic\DateTimeModel d WHERE d.datetime > current_timestamp()',
            'SELECT t0."id" AS c0 FROM "date_time_model" t0 WHERE t0."col_datetime" > CURRENT_TIMESTAMP',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    public function testExistsExpressionInWhereCorrelatedSubqueryAssocCondition() : void
    {
        $this->assertSqlGeneration(
            // DQL
            // The result of this query consists of all employees whose spouses are also employees.
            'SELECT DISTINCT emp FROM Doctrine\Tests\Models\CMS\CmsEmployee emp
                WHERE EXISTS (
                    SELECT spouseEmp
                    FROM Doctrine\Tests\Models\CMS\CmsEmployee spouseEmp
                    WHERE spouseEmp = emp.spouse)',
            // SQL
            'SELECT DISTINCT t0."id" AS c0, t0."name" AS c1 FROM "cms_employees" t0'
                . ' WHERE EXISTS ('
                    . 'SELECT t1."id" FROM "cms_employees" t1 WHERE t1."id" = t0."spouse_id"'
                    . ')'
        );
    }

    public function testExistsExpressionWithSimpleSelectReturningScalar() : void
    {
        $this->assertSqlGeneration(
            // DQL
            // The result of this query consists of all employees whose spouses are also employees.
            'SELECT DISTINCT emp FROM Doctrine\Tests\Models\CMS\CmsEmployee emp
                WHERE EXISTS (
                    SELECT 1
                    FROM Doctrine\Tests\Models\CMS\CmsEmployee spouseEmp
                    WHERE spouseEmp = emp.spouse)',
            // SQL
            'SELECT DISTINCT t0."id" AS c0, t0."name" AS c1 FROM "cms_employees" t0'
                . ' WHERE EXISTS ('
                    . 'SELECT 1 AS c2 FROM "cms_employees" t1 WHERE t1."id" = t0."spouse_id"'
                    . ')'
        );
    }

    public function testLimitFromQueryClass() : void
    {
        $q = $this->em
            ->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u')
            ->setMaxResults(10);

        self::assertEquals(
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, t0."email_id" AS c4 FROM "cms_users" t0 LIMIT 10',
            $q->getSql()
        );
    }

    public function testLimitAndOffsetFromQueryClass() : void
    {
        $q = $this->em
            ->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u')
            ->setMaxResults(10)
            ->setFirstResult(0);

        // DBAL 2.8+ doesn't add OFFSET part when offset is 0
        self::assertThat(
            $q->getSql(),
            self::logicalOr(
                self::identicalTo('SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, t0."email_id" AS c4 FROM "cms_users" t0 LIMIT 10'),
                self::identicalTo('SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, t0."email_id" AS c4 FROM "cms_users" t0 LIMIT 10 OFFSET 0')
            )
        );
    }

    public function testSizeFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE SIZE(u.phonenumbers) > 1',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE (SELECT COUNT(*) FROM "cms_phonenumbers" t1 WHERE t1."user_id" = t0."id") > 1'
        );
    }

    public function testSizeFunctionSupportsManyToMany() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE SIZE(u.groups) > 1',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE (SELECT COUNT(*) FROM "cms_users_groups" t1 WHERE t1."user_id" = t0."id") > 1'
        );
    }

    public function testEmptyCollectionComparisonExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.phonenumbers IS EMPTY',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE (SELECT COUNT(*) FROM "cms_phonenumbers" t1 WHERE t1."user_id" = t0."id") = 0'
        );
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.phonenumbers IS NOT EMPTY',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE (SELECT COUNT(*) FROM "cms_phonenumbers" t1 WHERE t1."user_id" = t0."id") > 0'
        );
    }

    public function testNestedExpressions() : void
    {
        $this->assertSqlGeneration(
            'select u from Doctrine\Tests\Models\CMS\CmsUser u where u.id > 10 and u.id < 42 and ((u.id * 2) > 5)',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."id" > 10 AND t0."id" < 42 AND ((t0."id" * 2) > 5)'
        );
    }

    public function testNestedExpressions2() : void
    {
        $this->assertSqlGeneration(
            'select u from Doctrine\Tests\Models\CMS\CmsUser u where (u.id > 10) and (u.id < 42 and ((u.id * 2) > 5)) or u.id <> 42',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE (t0."id" > 10) AND (t0."id" < 42 AND ((t0."id" * 2) > 5)) OR t0."id" <> 42'
        );
    }

    public function testNestedExpressions3() : void
    {
        $this->assertSqlGeneration(
            'select u from Doctrine\Tests\Models\CMS\CmsUser u where (u.id > 10) and (u.id between 1 and 10 or u.id in (1, 2, 3, 4, 5))',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE (t0."id" > 10) AND (t0."id" BETWEEN 1 AND 10 OR t0."id" IN (1, 2, 3, 4, 5))'
        );
    }

    public function testOrderByCollectionAssociationSize() : void
    {
        $this->assertSqlGeneration(
            'select u, size(u.articles) as numArticles from Doctrine\Tests\Models\CMS\CmsUser u order by numArticles',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, (SELECT COUNT(*) FROM "cms_articles" t1 WHERE t1."user_id" = t0."id") AS c4 FROM "cms_users" t0 ORDER BY c4 ASC'
        );
    }

    public function testOrderBySupportsSingleValuedPathExpressionOwningSide() : void
    {
        $this->assertSqlGeneration(
            'select a from Doctrine\Tests\Models\CMS\CmsArticle a order by a.user',
            'SELECT t0."id" AS c0, t0."topic" AS c1, t0."text" AS c2, t0."version" AS c3 FROM "cms_articles" t0 ORDER BY t0."user_id" ASC'
        );
    }

    /**
     * @expectedException \Doctrine\ORM\Query\QueryException
     */
    public function testOrderBySupportsSingleValuedPathExpressionInverseSide() : void
    {
        $q = $this->em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u order by u.address');

        $q->getSQL();
    }

    public function testBooleanLiteralInWhereOnSqlite() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new SqlitePlatform());

        $this->assertSqlGeneration(
            'SELECT b FROM Doctrine\Tests\Models\Generic\BooleanModel b WHERE b.booleanField = true',
            'SELECT t0."id" AS c0, t0."booleanField" AS c1 FROM "boolean_model" t0 WHERE t0."booleanField" = 1'
        );

        $this->assertSqlGeneration(
            'SELECT b FROM Doctrine\Tests\Models\Generic\BooleanModel b WHERE b.booleanField = false',
            'SELECT t0."id" AS c0, t0."booleanField" AS c1 FROM "boolean_model" t0 WHERE t0."booleanField" = 0'
        );
    }

    public function testBooleanLiteralInWhereOnPostgres94() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new PostgreSQL94Platform());

        $this->assertSqlGeneration(
            'SELECT b FROM Doctrine\Tests\Models\Generic\BooleanModel b WHERE b.booleanField = true',
            'SELECT t0."id" AS c0, t0."booleanField" AS c1 FROM "boolean_model" t0 WHERE t0."booleanField" = true'
        );

        $this->assertSqlGeneration(
            'SELECT b FROM Doctrine\Tests\Models\Generic\BooleanModel b WHERE b.booleanField = false',
            'SELECT t0."id" AS c0, t0."booleanField" AS c1 FROM "boolean_model" t0 WHERE t0."booleanField" = false'
        );
    }

    public function testBooleanLiteralInWhereOnPostgres100() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new PostgreSQL100Platform());

        $this->assertSqlGeneration(
            'SELECT b FROM Doctrine\Tests\Models\Generic\BooleanModel b WHERE b.booleanField = true',
            'SELECT t0."id" AS c0, t0."booleanField" AS c1 FROM "boolean_model" t0 WHERE t0."booleanField" = true'
        );

        $this->assertSqlGeneration(
            'SELECT b FROM Doctrine\Tests\Models\Generic\BooleanModel b WHERE b.booleanField = false',
            'SELECT t0."id" AS c0, t0."booleanField" AS c1 FROM "boolean_model" t0 WHERE t0."booleanField" = false'
        );
    }

    public function testSingleValuedAssociationFieldInWhere() : void
    {
        $this->assertSqlGeneration(
            'SELECT p FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE p.user = ?1',
            'SELECT t0."phonenumber" AS c0 FROM "cms_phonenumbers" t0 WHERE t0."user_id" = ?'
        );
    }

    public function testSingleValuedAssociationNullCheckOnOwningSide() : void
    {
        $this->assertSqlGeneration(
            'SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.user IS NULL',
            'SELECT t0."id" AS c0, t0."country" AS c1, t0."zip" AS c2, t0."city" AS c3 FROM "cms_addresses" t0 WHERE t0."user_id" IS NULL'
        );
    }

    /**
     * Null check on inverse side has to happen through explicit JOIN.
     * 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.address IS NULL'
     * where the CmsUser is the inverse side is not supported.
     */
    public function testSingleValuedAssociationNullCheckOnInverseSide() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.address a WHERE a.id IS NULL',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 LEFT JOIN "cms_addresses" t1 ON t0."id" = t1."user_id" WHERE t1."id" IS NULL'
        );
    }

    /**
     * @group DDC-339
     * @group DDC-1572
     */
    public function testStringFunctionLikeExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE LOWER(u.name) LIKE \'%foo OR bar%\'',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE LOWER(t0."name") LIKE \'%foo OR bar%\''
        );
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE LOWER(u.name) LIKE :str',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE LOWER(t0."name") LIKE ?'
        );
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE CONCAT(UPPER(u.name), \'_moo\') LIKE :str',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE UPPER(t0."name") || \'_moo\' LIKE ?'
        );

        // DDC-1572
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE UPPER(u.name) LIKE UPPER(:str)',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE UPPER(t0."name") LIKE UPPER(?)'
        );
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE UPPER(LOWER(u.name)) LIKE UPPER(LOWER(:str))',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE UPPER(LOWER(t0."name")) LIKE UPPER(LOWER(?))'
        );
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a WITH a.topic LIKE u.name',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 LEFT JOIN "cms_articles" t1 ON t0."id" = t1."user_id" AND (t1."topic" LIKE t0."name")'
        );
    }

    /**
     * @group DDC-1802
     */
    public function testStringFunctionNotLikeExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE LOWER(u.name) NOT LIKE \'%foo OR bar%\'',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE LOWER(t0."name") NOT LIKE \'%foo OR bar%\''
        );

        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE UPPER(LOWER(u.name)) NOT LIKE UPPER(LOWER(:str))',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 WHERE UPPER(LOWER(t0."name")) NOT LIKE UPPER(LOWER(?))'
        );
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a WITH a.topic NOT LIKE u.name',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 LEFT JOIN "cms_articles" t1 ON t0."id" = t1."user_id" AND (t1."topic" NOT LIKE t0."name")'
        );
    }

    /**
     * @group DDC-338
     */
    public function testOrderedCollectionFetchJoined() : void
    {
        $this->assertSqlGeneration(
            'SELECT r, l FROM Doctrine\Tests\Models\Routing\RoutingRoute r JOIN r.legs l',
            'SELECT t0."id" AS c0, t1."id" AS c1, t1."departureDate" AS c2, t1."arrivalDate" AS c3 FROM "RoutingRoute" t0 INNER JOIN "RoutingRouteLegs" t2 ON t0."id" = t2."route_id" INNER JOIN "RoutingLeg" t1 ON t1."id" = t2."leg_id" ORDER BY t1."departureDate" ASC'
        );
    }

    public function testSubselectInSelect() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name, (SELECT COUNT(p.phonenumber) FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE p.phonenumber = 1234) pcount FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = \'jon\'',
            'SELECT t0."name" AS c0, (SELECT COUNT(t1."phonenumber") AS c2 FROM "cms_phonenumbers" t1 WHERE t1."phonenumber" = 1234) AS c1 FROM "cms_users" t0 WHERE t0."name" = \'jon\''
        );
    }

    /**
     * @group locking
     * @group DDC-178
     */
    public function testPessimisticWriteLockQueryHint() : void
    {
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->markTestSkipped('SqLite does not support Row locking at all.');
        }

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = \'gblanco\'',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."username" = \'gblanco\' FOR UPDATE',
            [ORMQuery::HINT_LOCK_MODE => LockMode::PESSIMISTIC_WRITE]
        );
    }

    /**
     * @group locking
     * @group DDC-178
     */
    public function testPessimisticReadLockQueryHintPostgreSql94() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new PostgreSQL94Platform());

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = \'gblanco\'',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."username" = \'gblanco\' FOR SHARE',
            [ORMQuery::HINT_LOCK_MODE => LockMode::PESSIMISTIC_READ]
        );
    }

    /**
     * @group locking
     * @group DDC-178
     */
    public function testPessimisticReadLockQueryHintPostgreSql100() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new PostgreSQL100Platform());

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = \'gblanco\'',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."username" = \'gblanco\' FOR SHARE',
            [ORMQuery::HINT_LOCK_MODE => LockMode::PESSIMISTIC_READ]
        );
    }

    /**
     * @group DDC-1693
     * @group locking
     */
    public function testLockModeNoneQueryHint() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = \'gblanco\'',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."username" = \'gblanco\'',
            [ORMQuery::HINT_LOCK_MODE => LockMode::NONE]
        );
    }

    /**
     * @group DDC-430
     */
    public function testSupportSelectWithMoreThan10InputParameters() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1 OR u.id = ?2 OR u.id = ?3 OR u.id = ?4 OR u.id = ?5 OR u.id = ?6 OR u.id = ?7 OR u.id = ?8 OR u.id = ?9 OR u.id = ?10 OR u.id = ?11',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."id" = ? OR t0."id" = ? OR t0."id" = ? OR t0."id" = ? OR t0."id" = ? OR t0."id" = ? OR t0."id" = ? OR t0."id" = ? OR t0."id" = ? OR t0."id" = ? OR t0."id" = ?'
        );
    }

    /**
     * @group locking
     * @group DDC-178
     */
    public function testPessimisticReadLockQueryHintMySql() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new MySqlPlatform());

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = \'gblanco\'',
            'SELECT t0.`id` AS c0, t0.`status` AS c1, t0.`username` AS c2, t0.`name` AS c3 FROM `cms_users` t0 WHERE t0.`username` = \'gblanco\' LOCK IN SHARE MODE',
            [ORMQuery::HINT_LOCK_MODE => LockMode::PESSIMISTIC_READ]
        );
    }

    /**
     * @group locking
     * @group DDC-178
     */
    public function testPessimisticReadLockQueryHintOracle() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new OraclePlatform());

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = \'gblanco\'',
            'SELECT t0."id" AS C0, t0."status" AS C1, t0."username" AS C2, t0."name" AS C3 FROM "cms_users" t0 WHERE t0."username" = \'gblanco\' FOR UPDATE',
            [ORMQuery::HINT_LOCK_MODE => LockMode::PESSIMISTIC_READ]
        );
    }

    /**
     * @group DDC-431
     */
    public function testSupportToCustomDQLFunctions() : void
    {
        $config = $this->em->getConfiguration();
        $config->addCustomNumericFunction('MYABS', MyAbsFunction::class);

        $this->assertSqlGeneration(
            'SELECT MYABS(p.phonenumber) FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p',
            'SELECT ABS(t0."phonenumber") AS c0 FROM "cms_phonenumbers" t0'
        );

        $config->setCustomNumericFunctions([]);
    }

    /**
     * @group DDC-826
     */
    public function testMappedSuperclassAssociationJoin() : void
    {
        $this->assertSqlGeneration(
            'SELECT f FROM Doctrine\Tests\Models\DirectoryTree\File f JOIN f.parentDirectory d WHERE f.id = ?1',
            'SELECT t0."id" AS c0, t0."name" AS c1, t0."extension" AS c2 FROM "file" t0 INNER JOIN "Directory" t1 ON t0."parentDirectory_id" = t1."id" WHERE t0."id" = ?'
        );
    }

    /**
     * @group DDC-1053
     */
    public function testGroupBy() : void
    {
        $this->assertSqlGeneration(
            'SELECT g.id, count(u.id) FROM Doctrine\Tests\Models\CMS\CmsGroup g JOIN g.users u GROUP BY g.id',
            'SELECT t0."id" AS c0, count(t1."id") AS c1 FROM "cms_groups" t0 INNER JOIN "cms_users_groups" t2 ON t0."id" = t2."group_id" INNER JOIN "cms_users" t1 ON t1."id" = t2."user_id" GROUP BY t0."id"'
        );
    }

    /**
     * @group DDC-1053
     */
    public function testGroupByIdentificationVariable() : void
    {
        $this->assertSqlGeneration(
            'SELECT g, count(u.id) FROM Doctrine\Tests\Models\CMS\CmsGroup g JOIN g.users u GROUP BY g',
            'SELECT t0."id" AS c0, t0."name" AS c1, count(t1."id") AS c2 FROM "cms_groups" t0 INNER JOIN "cms_users_groups" t2 ON t0."id" = t2."group_id" INNER JOIN "cms_users" t1 ON t1."id" = t2."user_id" GROUP BY t0."id", t0."name"'
        );
    }

    public function testCaseContainingNullIf() : void
    {
        $this->assertSqlGeneration(
            'SELECT NULLIF(g.id, g.name) AS NullIfEqual FROM Doctrine\Tests\Models\CMS\CmsGroup g',
            'SELECT NULLIF(t0."id", t0."name") AS c0 FROM "cms_groups" t0'
        );
    }

    public function testCaseContainingCoalesce() : void
    {
        $this->assertSqlGeneration(
            'SELECT COALESCE(NULLIF(u.name, \'\'), u.username) as Display FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT COALESCE(NULLIF(t0."name", \'\'), t0."username") AS c0 FROM "cms_users" t0'
        );
    }

    /**
     * Test that the right discriminator data is inserted in a subquery.
     */
    public function testSubSelectDiscriminator() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name, (SELECT COUNT(cfc.id) total FROM Doctrine\Tests\Models\Company\CompanyFixContract cfc) as cfc_count FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT t0."name" AS c0, (SELECT COUNT(t1."id") AS c2 FROM "company_contracts" t1 WHERE t1."discr" IN (\'fix\')) AS c1 FROM "cms_users" t0'
        );
    }

    public function testIdVariableResultVariableReuse() : void
    {
        $exceptionThrown = false;

        try {
            $query = $this->em->createQuery('SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name IN (SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u)');

            $query->getSql();
            $query->free();
        } catch (Exception $e) {
            $exceptionThrown = true;
        }

        self::assertTrue($exceptionThrown);
    }

    public function testSubSelectAliasesFromOuterQuery() : void
    {
        $this->assertSqlGeneration(
            'SELECT uo, (SELECT ui.name FROM Doctrine\Tests\Models\CMS\CmsUser ui WHERE ui.id = uo.id) AS bar FROM Doctrine\Tests\Models\CMS\CmsUser uo',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, (SELECT t1."name" FROM "cms_users" t1 WHERE t1."id" = t0."id") AS c4 FROM "cms_users" t0'
        );
    }

    public function testSubSelectAliasesFromOuterQueryWithSubquery() : void
    {
        $this->assertSqlGeneration(
            'SELECT uo, (SELECT ui.name FROM Doctrine\Tests\Models\CMS\CmsUser ui WHERE ui.id = uo.id AND ui.name IN (SELECT uii.name FROM Doctrine\Tests\Models\CMS\CmsUser uii)) AS bar FROM Doctrine\Tests\Models\CMS\CmsUser uo',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, (SELECT t1."name" FROM "cms_users" t1 WHERE t1."id" = t0."id" AND t1."name" IN (SELECT t2."name" FROM "cms_users" t2)) AS c4 FROM "cms_users" t0'
        );
    }

    public function testSubSelectAliasesFromOuterQueryReuseInWhereClause() : void
    {
        $this->assertSqlGeneration(
            'SELECT uo, (SELECT ui.name FROM Doctrine\Tests\Models\CMS\CmsUser ui WHERE ui.id = uo.id) AS bar FROM Doctrine\Tests\Models\CMS\CmsUser uo WHERE bar = ?0',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, (SELECT t1."name" FROM "cms_users" t1 WHERE t1."id" = t0."id") AS c4 FROM "cms_users" t0 WHERE c4 = ?'
        );
    }

    /**
     * @group DDC-1298
     */
    public function testSelectForeignKeyPKWithoutFields() : void
    {
        $this->assertSqlGeneration(
            'SELECT t, s, l FROM Doctrine\Tests\Models\DDC117\DDC117Link l INNER JOIN l.target t INNER JOIN l.source s',
            'SELECT t0."article_id" AS c0, t0."title" AS c1, t1."article_id" AS c2, t1."title" AS c3, t2."source_id" AS c4, t2."target_id" AS c5 FROM "DDC117Link" t2 INNER JOIN "DDC117Article" t0 ON t2."target_id" = t0."article_id" INNER JOIN "DDC117Article" t1 ON t2."source_id" = t1."article_id"'
        );
    }

    public function testGeneralCaseWithSingleWhenClause() : void
    {
        $this->assertSqlGeneration(
            'SELECT g.id, CASE WHEN ((g.id / 2) > 18) THEN 1 ELSE 0 END AS test FROM Doctrine\Tests\Models\CMS\CmsGroup g',
            'SELECT t0."id" AS c0, CASE WHEN ((t0."id" / 2) > 18) THEN 1 ELSE 0 END AS c1 FROM "cms_groups" t0'
        );
    }

    public function testGeneralCaseWithMultipleWhenClause() : void
    {
        $this->assertSqlGeneration(
            'SELECT g.id, CASE WHEN (g.id / 2 < 10) THEN 2 WHEN ((g.id / 2) > 20) THEN 1 ELSE 0 END AS test FROM Doctrine\Tests\Models\CMS\CmsGroup g',
            'SELECT t0."id" AS c0, CASE WHEN (t0."id" / 2 < 10) THEN 2 WHEN ((t0."id" / 2) > 20) THEN 1 ELSE 0 END AS c1 FROM "cms_groups" t0'
        );
    }

    public function testSimpleCaseWithSingleWhenClause() : void
    {
        $this->assertSqlGeneration(
            'SELECT g FROM Doctrine\Tests\Models\CMS\CmsGroup g WHERE g.id = CASE g.name WHEN \'admin\' THEN 1 ELSE 2 END',
            'SELECT t0."id" AS c0, t0."name" AS c1 FROM "cms_groups" t0 WHERE t0."id" = CASE t0."name" WHEN \'admin\' THEN 1 ELSE 2 END'
        );
    }

    public function testSimpleCaseWithMultipleWhenClause() : void
    {
        $this->assertSqlGeneration(
            'SELECT g FROM Doctrine\Tests\Models\CMS\CmsGroup g WHERE g.id = (CASE g.name WHEN \'admin\' THEN 1 WHEN \'moderator\' THEN 2 ELSE 3 END)',
            'SELECT t0."id" AS c0, t0."name" AS c1 FROM "cms_groups" t0 WHERE t0."id" = (CASE t0."name" WHEN \'admin\' THEN 1 WHEN \'moderator\' THEN 2 ELSE 3 END)'
        );
    }

    public function testGeneralCaseWithSingleWhenClauseInSubselect() : void
    {
        $this->assertSqlGeneration(
            'SELECT g FROM Doctrine\Tests\Models\CMS\CmsGroup g WHERE g.id IN (SELECT CASE WHEN ((g2.id / 2) > 18) THEN 2 ELSE 1 END FROM Doctrine\Tests\Models\CMS\CmsGroup g2)',
            'SELECT t0."id" AS c0, t0."name" AS c1 FROM "cms_groups" t0 WHERE t0."id" IN (SELECT CASE WHEN ((t1."id" / 2) > 18) THEN 2 ELSE 1 END AS c2 FROM "cms_groups" t1)'
        );
    }

    public function testGeneralCaseWithMultipleWhenClauseInSubselect() : void
    {
        $this->assertSqlGeneration(
            'SELECT g FROM Doctrine\Tests\Models\CMS\CmsGroup g WHERE g.id IN (SELECT CASE WHEN (g.id / 2 < 10) THEN 3 WHEN ((g.id / 2) > 20) THEN 2 ELSE 1 END FROM Doctrine\Tests\Models\CMS\CmsGroup g2)',
            'SELECT t0."id" AS c0, t0."name" AS c1 FROM "cms_groups" t0 WHERE t0."id" IN (SELECT CASE WHEN (t0."id" / 2 < 10) THEN 3 WHEN ((t0."id" / 2) > 20) THEN 2 ELSE 1 END AS c2 FROM "cms_groups" t1)'
        );
    }

    public function testSimpleCaseWithSingleWhenClauseInSubselect() : void
    {
        $this->assertSqlGeneration(
            'SELECT g FROM Doctrine\Tests\Models\CMS\CmsGroup g WHERE g.id IN (SELECT CASE g2.name WHEN \'admin\' THEN 1 ELSE 2 END FROM Doctrine\Tests\Models\CMS\CmsGroup g2)',
            'SELECT t0."id" AS c0, t0."name" AS c1 FROM "cms_groups" t0 WHERE t0."id" IN (SELECT CASE t1."name" WHEN \'admin\' THEN 1 ELSE 2 END AS c2 FROM "cms_groups" t1)'
        );
    }

    public function testSimpleCaseWithMultipleWhenClauseInSubselect() : void
    {
        $this->assertSqlGeneration(
            'SELECT g FROM Doctrine\Tests\Models\CMS\CmsGroup g WHERE g.id IN (SELECT CASE g2.name WHEN \'admin\' THEN 1 WHEN \'moderator\' THEN 2 ELSE 3 END FROM Doctrine\Tests\Models\CMS\CmsGroup g2)',
            'SELECT t0."id" AS c0, t0."name" AS c1 FROM "cms_groups" t0 WHERE t0."id" IN (SELECT CASE t1."name" WHEN \'admin\' THEN 1 WHEN \'moderator\' THEN 2 ELSE 3 END AS c2 FROM "cms_groups" t1)'
        );
    }

    /**
     * @group DDC-1696
     */
    public function testSimpleCaseWithStringPrimary() : void
    {
        $this->assertSqlGeneration(
            'SELECT g.id, CASE WHEN ((g.id / 2) > 18) THEN \'Foo\' ELSE \'Bar\' END AS test FROM Doctrine\Tests\Models\CMS\CmsGroup g',
            'SELECT t0."id" AS c0, CASE WHEN ((t0."id" / 2) > 18) THEN \'Foo\' ELSE \'Bar\' END AS c1 FROM "cms_groups" t0'
        );
    }

    /**
     * @group DDC-2205
     */
    public function testCaseNegativeValuesInThenExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT CASE g.name WHEN \'admin\' THEN - 1 ELSE - 2 END FROM Doctrine\Tests\Models\CMS\CmsGroup g',
            'SELECT CASE t0."name" WHEN \'admin\' THEN -1 ELSE -2 END AS c0 FROM "cms_groups" t0'
        );

        $this->assertSqlGeneration(
            'SELECT CASE g.name WHEN \'admin\' THEN  - 2 WHEN \'guest\' THEN - 1 ELSE 0 END FROM Doctrine\Tests\Models\CMS\CmsGroup g',
            'SELECT CASE t0."name" WHEN \'admin\' THEN -2 WHEN \'guest\' THEN -1 ELSE 0 END AS c0 FROM "cms_groups" t0'
        );

        $this->assertSqlGeneration(
            'SELECT CASE g.name WHEN \'admin\' THEN (- 1) ELSE (- 2) END FROM Doctrine\Tests\Models\CMS\CmsGroup g',
            'SELECT CASE t0."name" WHEN \'admin\' THEN (-1) ELSE (-2) END AS c0 FROM "cms_groups" t0'
        );

        $this->assertSqlGeneration(
            'SELECT CASE g.name WHEN \'admin\' THEN ( - :value) ELSE ( + :value) END FROM Doctrine\Tests\Models\CMS\CmsGroup g',
            'SELECT CASE t0."name" WHEN \'admin\' THEN (-?) ELSE (+?) END AS c0 FROM "cms_groups" t0'
        );

        $this->assertSqlGeneration(
            'SELECT CASE g.name WHEN \'admin\' THEN ( - g.id) ELSE ( + g.id) END FROM Doctrine\Tests\Models\CMS\CmsGroup g',
            'SELECT CASE t0."name" WHEN \'admin\' THEN (-t0."id") ELSE (+t0."id") END AS c0 FROM "cms_groups" t0'
        );
    }

    public function testIdentityFunctionWithCompositePrimaryKey() : void
    {
        $this->assertSqlGeneration(
            'SELECT IDENTITY(p.poi, \'long\') AS long FROM Doctrine\Tests\Models\Navigation\NavPhotos p',
            'SELECT t0."poi_long" AS c0 FROM "navigation_photos" t0'
        );

        $this->assertSqlGeneration(
            'SELECT IDENTITY(p.poi, \'lat\') AS lat FROM Doctrine\Tests\Models\Navigation\NavPhotos p',
            'SELECT t0."poi_lat" AS c0 FROM "navigation_photos" t0'
        );

        $this->assertSqlGeneration(
            'SELECT IDENTITY(p.poi, \'long\') AS long, IDENTITY(p.poi, \'lat\') AS lat FROM Doctrine\Tests\Models\Navigation\NavPhotos p',
            'SELECT t0."poi_long" AS c0, t0."poi_lat" AS c1 FROM "navigation_photos" t0'
        );

        $this->assertInvalidSqlGeneration(
            'SELECT IDENTITY(p.poi, \'invalid\') AS invalid FROM Doctrine\Tests\Models\Navigation\NavPhotos p',
            QueryException::class
        );
    }

    /**
     * @group DDC-2519
     */
    public function testPartialWithAssociationIdentifier() : void
    {
        $this->assertSqlGeneration(
            'SELECT PARTIAL l.{source, target} FROM Doctrine\Tests\Models\Legacy\LegacyUserReference l',
            'SELECT t0."iUserIdSource" AS c0, t0."iUserIdTarget" AS c1 FROM "legacy_users_reference" t0'
        );

        $this->assertSqlGeneration(
            'SELECT PARTIAL l.{description, source, target} FROM Doctrine\Tests\Models\Legacy\LegacyUserReference l',
            'SELECT t0."description" AS c0, t0."iUserIdSource" AS c1, t0."iUserIdTarget" AS c2 FROM "legacy_users_reference" t0'
        );
    }

    /**
     * @group DDC-1339
     */
    public function testIdentityFunctionInSelectClause() : void
    {
        $this->assertSqlGeneration(
            'SELECT IDENTITY(u.email) as email_id FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT t0."email_id" AS c0 FROM "cms_users" t0'
        );
    }

    public function testIdentityFunctionInJoinedSubclass() : void
    {
        //relation is in the subclass (CompanyManager) we are querying
        $this->assertSqlGeneration(
            'SELECT m, IDENTITY(m.car) as car_id FROM Doctrine\Tests\Models\Company\CompanyManager m',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t2."title" AS c5, t2."car_id" AS c6, t0."discr" AS c7 FROM "company_managers" t2 INNER JOIN "company_employees" t1 ON t2."id" = t1."id" INNER JOIN "company_persons" t0 ON t2."id" = t0."id"'
        );

        //relation is in the base class (CompanyPerson).
        $this->assertSqlGeneration(
            'SELECT m, IDENTITY(m.spouse) as spouse_id FROM Doctrine\Tests\Models\Company\CompanyManager m',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t2."title" AS c5, t0."spouse_id" AS c6, t0."discr" AS c7 FROM "company_managers" t2 INNER JOIN "company_employees" t1 ON t2."id" = t1."id" INNER JOIN "company_persons" t0 ON t2."id" = t0."id"'
        );
    }

    /**
     * @group DDC-1339
     */
    public function testIdentityFunctionDoesNotAcceptStateField() : void
    {
        $this->assertInvalidSqlGeneration(
            'SELECT IDENTITY(u.name) as name FROM Doctrine\Tests\Models\CMS\CmsUser u',
            QueryException::class
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeJoinInRootClassWithDisabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT p FROM Doctrine\Tests\Models\Company\CompanyPerson p',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."title" AS c2, t2."salary" AS c3, t2."department" AS c4, t2."startDate" AS c5, t0."discr" AS c6, t0."spouse_id" AS c7, t1."car_id" AS c8 FROM "company_persons" t0 LEFT JOIN "company_managers" t1 ON t0."id" = t1."id" LEFT JOIN "company_employees" t2 ON t0."id" = t2."id"',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => false]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeJoinInRootClassWithEnabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT p FROM Doctrine\Tests\Models\Company\CompanyPerson p',
            'SELECT t0."id" AS c0, t0."name" AS c1, t0."discr" AS c2 FROM "company_persons" t0',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeJoinInChildClassWithDisabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT e FROM Doctrine\Tests\Models\Company\CompanyEmployee e',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t2."title" AS c5, t0."discr" AS c6, t0."spouse_id" AS c7, t2."car_id" AS c8 FROM "company_employees" t1 INNER JOIN "company_persons" t0 ON t1."id" = t0."id" LEFT JOIN "company_managers" t2 ON t1."id" = t2."id"',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => false]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeJoinInChildClassWithEnabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT e FROM Doctrine\Tests\Models\Company\CompanyEmployee e',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t0."discr" AS c5 FROM "company_employees" t1 INNER JOIN "company_persons" t0 ON t1."id" = t0."id"',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeJoinInLeafClassWithDisabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT m FROM Doctrine\Tests\Models\Company\CompanyManager m',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t2."title" AS c5, t0."discr" AS c6, t0."spouse_id" AS c7, t2."car_id" AS c8 FROM "company_managers" t2 INNER JOIN "company_employees" t1 ON t2."id" = t1."id" INNER JOIN "company_persons" t0 ON t2."id" = t0."id"',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => false]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeJoinInLeafClassWithEnabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT m FROM Doctrine\Tests\Models\Company\CompanyManager m',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, t2."title" AS c5, t0."discr" AS c6 FROM "company_managers" t2 INNER JOIN "company_employees" t1 ON t2."id" = t1."id" INNER JOIN "company_persons" t0 ON t2."id" = t0."id"',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeSingleTableInRootClassWithDisabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT c FROM Doctrine\Tests\Models\Company\CompanyContract c',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."fixPrice" AS c2, t0."hoursWorked" AS c3, t0."pricePerHour" AS c4, t0."maxPrice" AS c5, t0."discr" AS c6, t0."salesPerson_id" AS c7 FROM "company_contracts" t0 WHERE t0."discr" IN (\'fix\', \'flexible\', \'flexultra\')',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => false]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeSingleTableInRootClassWithEnabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT c FROM Doctrine\Tests\Models\Company\CompanyContract c',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."fixPrice" AS c2, t0."hoursWorked" AS c3, t0."pricePerHour" AS c4, t0."maxPrice" AS c5, t0."discr" AS c6 FROM "company_contracts" t0 WHERE t0."discr" IN (\'fix\', \'flexible\', \'flexultra\')',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeSingleTableInChildClassWithDisabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT fc FROM Doctrine\Tests\Models\Company\CompanyFlexContract fc',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."hoursWorked" AS c2, t0."pricePerHour" AS c3, t0."maxPrice" AS c4, t0."discr" AS c5, t0."salesPerson_id" AS c6 FROM "company_contracts" t0 WHERE t0."discr" IN (\'flexible\', \'flexultra\')',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => false]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeSingleTableInChildClassWithEnabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT fc FROM Doctrine\Tests\Models\Company\CompanyFlexContract fc',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."hoursWorked" AS c2, t0."pricePerHour" AS c3, t0."maxPrice" AS c4, t0."discr" AS c5 FROM "company_contracts" t0 WHERE t0."discr" IN (\'flexible\', \'flexultra\')',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeSingleTableInLeafClassWithDisabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT fuc FROM Doctrine\Tests\Models\Company\CompanyFlexUltraContract fuc',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."hoursWorked" AS c2, t0."pricePerHour" AS c3, t0."maxPrice" AS c4, t0."discr" AS c5, t0."salesPerson_id" AS c6 FROM "company_contracts" t0 WHERE t0."discr" IN (\'flexultra\')',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => false]
        );
    }

    /**
     * @group DDC-1389
     */
    public function testInheritanceTypeSingleTableInLeafClassWithEnabledForcePartialLoad() : void
    {
        $this->assertSqlGeneration(
            'SELECT fuc FROM Doctrine\Tests\Models\Company\CompanyFlexUltraContract fuc',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."hoursWorked" AS c2, t0."pricePerHour" AS c3, t0."maxPrice" AS c4, t0."discr" AS c5 FROM "company_contracts" t0 WHERE t0."discr" IN (\'flexultra\')',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => true]
        );
    }

    /**
     * @group DDC-1161
     */
    public function testSelfReferenceWithOneToOneDoesNotDuplicateAlias() : void
    {
        $this->assertSqlGeneration(
            'SELECT p, pp FROM Doctrine\Tests\Models\Company\CompanyPerson p JOIN p.spouse pp',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."title" AS c2, t2."salary" AS c3, t2."department" AS c4, t2."startDate" AS c5, t3."id" AS c6, t3."name" AS c7, t4."title" AS c8, t5."salary" AS c9, t5."department" AS c10, t5."startDate" AS c11, t0."discr" AS c12, t0."spouse_id" AS c13, t1."car_id" AS c14, t3."discr" AS c15, t3."spouse_id" AS c16, t4."car_id" AS c17 FROM "company_persons" t0 LEFT JOIN "company_managers" t1 ON t0."id" = t1."id" LEFT JOIN "company_employees" t2 ON t0."id" = t2."id" INNER JOIN "company_persons" t3 ON t0."spouse_id" = t3."id" LEFT JOIN "company_managers" t4 ON t3."id" = t4."id" LEFT JOIN "company_employees" t5 ON t3."id" = t5."id"',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => false]
        );
    }

    /**
     * @group DDC-1384
     */
    public function testAliasDoesNotExceedPlatformDefinedLength() : void
    {
        $this->assertSqlGeneration(
            'SELECT m FROM ' . __NAMESPACE__ . '\\DDC1384Model m',
            'SELECT t0."aVeryLongIdentifierThatShouldBeShortenedByTheSQLWalker_fooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooo" AS c0 FROM "DDC1384Model" t0'
        );
    }

    /**
     * @group DDC-331
     * @group DDC-1384
     */
    public function testIssue331() : void
    {
        $this->assertSqlGeneration(
            'SELECT e.name FROM Doctrine\Tests\Models\Company\CompanyEmployee e',
            'SELECT t0."name" AS c0 FROM "company_employees" t1 INNER JOIN "company_persons" t0 ON t1."id" = t0."id"'
        );
    }

    /**
     * @group DDC-1435
     */
    public function testForeignKeyAsPrimaryKeySubselect() : void
    {
        $this->assertSqlGeneration(
            'SELECT s FROM Doctrine\Tests\Models\DDC117\DDC117Article s WHERE EXISTS (SELECT r FROM Doctrine\Tests\Models\DDC117\DDC117Reference r WHERE r.source = s)',
            'SELECT t0."article_id" AS c0, t0."title" AS c1 FROM "DDC117Article" t0 WHERE EXISTS (SELECT t1."source_id", t1."target_id" FROM "DDC117Reference" t1 WHERE t1."source_id" = t0."article_id")'
        );
    }

    /**
     * @group DDC-1474
     */
    public function testSelectWithArithmeticExpressionBeforeField() : void
    {
        $this->assertSqlGeneration(
            'SELECT - e.value AS value, e.id FROM ' . __NAMESPACE__ . '\DDC1474Entity e',
            'SELECT -t0."value" AS c0, t0."id" AS c1 FROM "DDC1474Entity" t0'
        );

        $this->assertSqlGeneration(
            'SELECT e.id, + e.value AS value FROM ' . __NAMESPACE__ . '\DDC1474Entity e',
            'SELECT t0."id" AS c0, +t0."value" AS c1 FROM "DDC1474Entity" t0'
        );
    }

    /**
     * @group DDC-1430
     */
    public function testGroupByAllFieldsWhenObjectHasForeignKeys() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 GROUP BY t0."id", t0."status", t0."username", t0."name", t0."email_id"'
        );

        $this->assertSqlGeneration(
            'SELECT e FROM Doctrine\Tests\Models\CMS\CmsEmployee e GROUP BY e',
            'SELECT t0."id" AS c0, t0."name" AS c1 FROM "cms_employees" t0 GROUP BY t0."id", t0."name", t0."spouse_id"'
        );
    }

    /**
     * @group DDC-1236
     */
    public function testGroupBySupportsResultVariable() : void
    {
        $this->assertSqlGeneration(
            'SELECT u, u.status AS st FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY st',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, t0."status" AS c4 FROM "cms_users" t0 GROUP BY t0."status"'
        );
    }

    /**
     * @group DDC-1236
     */
    public function testGroupBySupportsIdentificationVariable() : void
    {
        $this->assertSqlGeneration(
            'SELECT u AS user FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY user',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 GROUP BY c0, c1, c2, c3'
        );
    }

    /**
     * @group DDC-1213
     */
    public function testSupportsBitComparison() : void
    {
        $this->assertSqlGeneration(
            'SELECT BIT_OR(4,2), BIT_AND(4,2), u FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT (4 | 2) AS c0, (4 & 2) AS c1, t0."id" AS c2, t0."status" AS c3, t0."username" AS c4, t0."name" AS c5 FROM "cms_users" t0'
        );
        $this->assertSqlGeneration(
            'SELECT BIT_OR(u.id,2), BIT_AND(u.id,2) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE BIT_OR(u.id,2) > 0',
            'SELECT (t0."id" | 2) AS c0, (t0."id" & 2) AS c1 FROM "cms_users" t0 WHERE (t0."id" | 2) > 0'
        );
        $this->assertSqlGeneration(
            'SELECT BIT_OR(u.id,2), BIT_AND(u.id,2) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE BIT_AND(u.id , 4) > 0',
            'SELECT (t0."id" | 2) AS c0, (t0."id" & 2) AS c1 FROM "cms_users" t0 WHERE (t0."id" & 4) > 0'
        );
        $this->assertSqlGeneration(
            'SELECT BIT_OR(u.id,2), BIT_AND(u.id,2) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE BIT_OR(u.id , 2) > 0 OR BIT_AND(u.id , 4) > 0',
            'SELECT (t0."id" | 2) AS c0, (t0."id" & 2) AS c1 FROM "cms_users" t0 WHERE (t0."id" | 2) > 0 OR (t0."id" & 4) > 0'
        );
    }

    /**
     * @group DDC-1539
     */
    public function testParenthesesOnTheLeftHandOfComparison() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u where ( (u.id + u.id) * u.id ) > 100',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE ((t0."id" + t0."id") * t0."id") > 100'
        );
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u where (u.id + u.id) * u.id > 100',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE (t0."id" + t0."id") * t0."id" > 100'
        );
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u where 100 < (u.id + u.id) * u.id ',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE 100 < (t0."id" + t0."id") * t0."id"'
        );
    }

    public function testSupportsParenthesisExpressionInSubSelect() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.id, (SELECT (1000*SUM(subU.id)/SUM(subU.id)) FROM Doctrine\Tests\Models\CMS\CmsUser subU where subU.id = u.id) AS subSelect FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT t0."id" AS c0, (SELECT (1000 * SUM(t1."id") / SUM(t1."id")) FROM "cms_users" t1 WHERE t1."id" = t0."id") AS c1 FROM "cms_users" t0'
        );
    }

    /**
     * @group DDC-1557
     */
    public function testSupportsSubSqlFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT u1 FROM Doctrine\Tests\Models\CMS\CmsUser u1 WHERE u1.name IN ( SELECT TRIM(u2.name) FROM Doctrine\Tests\Models\CMS\CmsUser u2 )',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."name" IN (SELECT TRIM(t1."name") AS c4 FROM "cms_users" t1)'
        );
        $this->assertSqlGeneration(
            'SELECT u1 FROM Doctrine\Tests\Models\CMS\CmsUser u1 WHERE u1.name IN ( SELECT TRIM(u2.name) FROM Doctrine\Tests\Models\CMS\CmsUser u2  WHERE LOWER(u2.name) LIKE \'%fabio%\')',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."name" IN (SELECT TRIM(t1."name") AS c4 FROM "cms_users" t1 WHERE LOWER(t1."name") LIKE \'%fabio%\')'
        );
        $this->assertSqlGeneration(
            'SELECT u1 FROM Doctrine\Tests\Models\CMS\CmsUser u1 WHERE u1.email IN ( SELECT TRIM(IDENTITY(u2.email)) FROM Doctrine\Tests\Models\CMS\CmsUser u2 )',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."email_id" IN (SELECT TRIM(t1."email_id") AS c4 FROM "cms_users" t1)'
        );
        $this->assertSqlGeneration(
            'SELECT u1 FROM Doctrine\Tests\Models\CMS\CmsUser u1 WHERE u1.email IN ( SELECT IDENTITY(u2.email) FROM Doctrine\Tests\Models\CMS\CmsUser u2 )',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."email_id" IN (SELECT t1."email_id" AS c4 FROM "cms_users" t1)'
        );
        $this->assertSqlGeneration(
            'SELECT u1 FROM Doctrine\Tests\Models\CMS\CmsUser u1 WHERE COUNT(u1.id) = ( SELECT SUM(u2.id) FROM Doctrine\Tests\Models\CMS\CmsUser u2 )',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE COUNT(t0."id") = (SELECT SUM(t1."id") AS c4 FROM "cms_users" t1)'
        );
        $this->assertSqlGeneration(
            'SELECT u1 FROM Doctrine\Tests\Models\CMS\CmsUser u1 WHERE COUNT(u1.id) <= ( SELECT SUM(u2.id) + COUNT(u2.email) FROM Doctrine\Tests\Models\CMS\CmsUser u2 )',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE COUNT(t0."id") <= (SELECT SUM(t1."id") + COUNT(t1."email_id") AS c4 FROM "cms_users" t1)'
        );
    }

    /**
     * @group DDC-1574
     */
    public function testSupportsNewOperator() : void
    {
        $this->assertSqlGeneration(
            'SELECT new Doctrine\Tests\Models\CMS\CmsUserDTO(u.name, e.email, a.city) FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.email e JOIN u.address a',
            'SELECT t0."name" AS c0, t1."email" AS c1, t2."city" AS c2 FROM "cms_users" t0 INNER JOIN "cms_emails" t1 ON t0."email_id" = t1."id" INNER JOIN "cms_addresses" t2 ON t0."id" = t2."user_id"'
        );

        $this->assertSqlGeneration(
            'SELECT new Doctrine\Tests\Models\CMS\CmsUserDTO(u.name, e.email, a.id + u.id) FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.email e JOIN u.address a',
            'SELECT t0."name" AS c0, t1."email" AS c1, t2."id" + t0."id" AS c2 FROM "cms_users" t0 INNER JOIN "cms_emails" t1 ON t0."email_id" = t1."id" INNER JOIN "cms_addresses" t2 ON t0."id" = t2."user_id"'
        );

        $this->assertSqlGeneration(
            'SELECT new Doctrine\Tests\Models\CMS\CmsUserDTO(u.name, e.email, a.city, COUNT(p)) FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.email e JOIN u.address a JOIN u.phonenumbers p',
            'SELECT t0."name" AS c0, t1."email" AS c1, t2."city" AS c2, COUNT(t3."phonenumber") AS c3 FROM "cms_users" t0 INNER JOIN "cms_emails" t1 ON t0."email_id" = t1."id" INNER JOIN "cms_addresses" t2 ON t0."id" = t2."user_id" INNER JOIN "cms_phonenumbers" t3 ON t0."id" = t3."user_id"'
        );

        $this->assertSqlGeneration(
            'SELECT new Doctrine\Tests\Models\CMS\CmsUserDTO(u.name, e.email, a.city, COUNT(p) + u.id) FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.email e JOIN u.address a JOIN u.phonenumbers p',
            'SELECT t0."name" AS c0, t1."email" AS c1, t2."city" AS c2, COUNT(t3."phonenumber") + t0."id" AS c3 FROM "cms_users" t0 INNER JOIN "cms_emails" t1 ON t0."email_id" = t1."id" INNER JOIN "cms_addresses" t2 ON t0."id" = t2."user_id" INNER JOIN "cms_phonenumbers" t3 ON t0."id" = t3."user_id"'
        );

        $this->assertSqlGeneration(
            'SELECT new Doctrine\Tests\Models\CMS\CmsUserDTO(a.id, a.country, a.city), new Doctrine\Tests\Models\CMS\CmsAddressDTO(u.name, e.email) FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.email e JOIN u.address a ORDER BY u.name',
            'SELECT t0."id" AS c0, t0."country" AS c1, t0."city" AS c2, t1."name" AS c3, t2."email" AS c4 FROM "cms_users" t1 INNER JOIN "cms_emails" t2 ON t1."email_id" = t2."id" INNER JOIN "cms_addresses" t0 ON t1."id" = t0."user_id" ORDER BY t1."name" ASC'
        );

        $this->assertSqlGeneration(
            'SELECT new Doctrine\Tests\Models\CMS\CmsUserDTO(a.id, (SELECT 1 FROM Doctrine\Tests\Models\CMS\CmsUser su), a.country, a.city), new Doctrine\Tests\Models\CMS\CmsAddressDTO(u.name, e.email) FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.email e JOIN u.address a ORDER BY u.name',
            'SELECT t0."id" AS c0, (SELECT 1 AS c2 FROM "cms_users" t1) AS c1, t0."country" AS c3, t0."city" AS c4, t2."name" AS c5, t3."email" AS c6 FROM "cms_users" t2 INNER JOIN "cms_emails" t3 ON t2."email_id" = t3."id" INNER JOIN "cms_addresses" t0 ON t2."id" = t0."user_id" ORDER BY t2."name" ASC'
        );
    }

    /**
     * @group DDC-2234
     */
    public function testWhereFunctionIsNullComparisonExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE IDENTITY(u.email) IS NULL',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."email_id" IS NULL'
        );

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE NULLIF(u.name, \'FabioBatSilva\') IS NULL AND IDENTITY(u.email) IS NOT NULL',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE NULLIF(t0."name", \'FabioBatSilva\') IS NULL AND t0."email_id" IS NOT NULL'
        );

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE IDENTITY(u.email) IS NOT NULL',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE t0."email_id" IS NOT NULL'
        );

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE NULLIF(u.name, \'FabioBatSilva\') IS NOT NULL',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE NULLIF(t0."name", \'FabioBatSilva\') IS NOT NULL'
        );

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE COALESCE(u.name, u.id) IS NOT NULL',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE COALESCE(t0."name", t0."id") IS NOT NULL'
        );

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE COALESCE(u.id, IDENTITY(u.email)) IS NOT NULL',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE COALESCE(t0."id", t0."email_id") IS NOT NULL'
        );

        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE COALESCE(IDENTITY(u.email), NULLIF(u.name, \'FabioBatSilva\')) IS NOT NULL',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 WHERE COALESCE(t0."email_id", NULLIF(t0."name", \'FabioBatSilva\')) IS NOT NULL'
        );
    }

    public function testCustomTypeValueSql() : void
    {
        if (DBALType::hasType('negative_to_positive')) {
            DBALType::overrideType('negative_to_positive', NegativeToPositiveType::class);
        } else {
            DBALType::addType('negative_to_positive', NegativeToPositiveType::class);
        }

        $this->assertSqlGeneration(
            'SELECT p.customInteger FROM Doctrine\Tests\Models\CustomType\CustomTypeParent p WHERE p.id = 1',
            'SELECT -(t0."customInteger") AS c0 FROM "customtype_parents" t0 WHERE t0."id" = 1'
        );
    }

    public function testCustomTypeValueSqlIgnoresIdentifierColumn() : void
    {
        if (DBALType::hasType('negative_to_positive')) {
            DBALType::overrideType('negative_to_positive', NegativeToPositiveType::class);
        } else {
            DBALType::addType('negative_to_positive', NegativeToPositiveType::class);
        }

        $this->assertSqlGeneration(
            'SELECT p.id FROM Doctrine\Tests\Models\CustomType\CustomTypeParent p WHERE p.id = 1',
            'SELECT t0."id" AS c0 FROM "customtype_parents" t0 WHERE t0."id" = 1'
        );
    }

    public function testCustomTypeValueSqlForAllFields() : void
    {
        if (DBALType::hasType('negative_to_positive')) {
            DBALType::overrideType('negative_to_positive', NegativeToPositiveType::class);
        } else {
            DBALType::addType('negative_to_positive', NegativeToPositiveType::class);
        }

        $this->assertSqlGeneration(
            'SELECT p FROM Doctrine\Tests\Models\CustomType\CustomTypeParent p',
            'SELECT t0."id" AS c0, -(t0."customInteger") AS c1 FROM "customtype_parents" t0'
        );
    }

    public function testCustomTypeValueSqlForPartialObject() : void
    {
        if (DBALType::hasType('negative_to_positive')) {
            DBALType::overrideType('negative_to_positive', NegativeToPositiveType::class);
        } else {
            DBALType::addType('negative_to_positive', NegativeToPositiveType::class);
        }

        $this->assertSqlGeneration(
            'SELECT partial p.{id, customInteger} FROM Doctrine\Tests\Models\CustomType\CustomTypeParent p',
            'SELECT t0."id" AS c0, -(t0."customInteger") AS c1 FROM "customtype_parents" t0'
        );
    }

    /**
     * @group DDC-1529
     */
    public function testMultipleFromAndInheritanceCondition() : void
    {
        $this->assertSqlGeneration(
            'SELECT fix, flex FROM Doctrine\Tests\Models\Company\CompanyFixContract fix, Doctrine\Tests\Models\Company\CompanyFlexContract flex',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."fixPrice" AS c2, t1."id" AS c3, t1."completed" AS c4, t1."hoursWorked" AS c5, t1."pricePerHour" AS c6, t1."maxPrice" AS c7, t0."discr" AS c8, t1."discr" AS c9 FROM "company_contracts" t0, "company_contracts" t1 WHERE (t0."discr" IN (\'fix\') AND t1."discr" IN (\'flexible\', \'flexultra\'))'
        );
    }

    /**
     * @group DDC-775
     */
    public function testOrderByClauseSupportsSimpleArithmeticExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.id + 1 ',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 ORDER BY t0."id" + 1 ASC'
        );
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY ( ( (u.id + 1) * (u.id - 1) ) / 2)',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 ORDER BY (((t0."id" + 1) * (t0."id" - 1)) / 2) ASC'
        );
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY ((u.id + 5000) * u.id + 3) ',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 ORDER BY ((t0."id" + 5000) * t0."id" + 3) ASC'
        );
    }

    public function testOrderByClauseSupportsFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY CONCAT(u.username, u.name) ',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3 FROM "cms_users" t0 ORDER BY t0."username" || t0."name" ASC'
        );
    }

    /**
     * @group DDC-1719
     */
    public function testStripNonAlphanumericCharactersFromAlias() : void
    {
        $this->assertSqlGeneration(
            'SELECT e FROM Doctrine\Tests\Models\Generic\NonAlphaColumnsEntity e',
            'SELECT t0."simple-entity-id" AS c0, t0."simple-entity-value" AS c1 FROM "not-a-simple-entity" t0'
        );

        $this->assertSqlGeneration(
            'SELECT e.value FROM Doctrine\Tests\Models\Generic\NonAlphaColumnsEntity e ORDER BY e.value',
            'SELECT t0."simple-entity-value" AS c0 FROM "not-a-simple-entity" t0 ORDER BY t0."simple-entity-value" ASC'
        );

        $this->assertSqlGeneration(
            'SELECT TRIM(e.value) FROM Doctrine\Tests\Models\Generic\NonAlphaColumnsEntity e ORDER BY e.value',
            'SELECT TRIM(t0."simple-entity-value") AS c0 FROM "not-a-simple-entity" t0 ORDER BY t0."simple-entity-value" ASC'
        );
    }

    /**
     * @group DDC-2435
     */
    public function testColumnNameWithNumbersAndNonAlphanumericCharacters() : void
    {
        $this->assertSqlGeneration(
            'SELECT e FROM Doctrine\Tests\Models\Quote\NumericEntity e',
            'SELECT t0."1:1" AS c0, t0."2:2" AS c1 FROM "table" t0'
        );

        $this->assertSqlGeneration(
            'SELECT e.value FROM Doctrine\Tests\Models\Quote\NumericEntity e',
            'SELECT t0."2:2" AS c0 FROM "table" t0'
        );

        $this->assertSqlGeneration(
            'SELECT TRIM(e.value) FROM Doctrine\Tests\Models\Quote\NumericEntity e',
            'SELECT TRIM(t0."2:2") AS c0 FROM "table" t0'
        );
    }

    /**
     * @group DDC-1845
     */
    public function testQuotedTableDeclaration() : void
    {
        $this->assertSqlGeneration(
            'SELECT u FROM Doctrine\Tests\Models\Quote\User u',
            'SELECT t0."user-id" AS c0, t0."user-name" AS c1 FROM "quote-user" t0'
        );
    }

    /**
     * @group DDC-1845
     */
    public function testQuotedWalkJoinVariableDeclaration() : void
    {
        $this->assertSqlGeneration(
            'SELECT u, a FROM Doctrine\Tests\Models\Quote\User u JOIN u.address a',
            'SELECT t0."user-id" AS c0, t0."user-name" AS c1, t1."address-id" AS c2, t1."address-zip" AS c3, t1."type" AS c4 FROM "quote-user" t0 INNER JOIN "quote-address" t1 ON t0."user-id" = t1."user-id" AND t1."type" IN (\'simple\', \'full\')'
        );

        $this->assertSqlGeneration(
            'SELECT u, p FROM Doctrine\Tests\Models\Quote\User u JOIN u.phones p',
            'SELECT t0."user-id" AS c0, t0."user-name" AS c1, t1."phone-number" AS c2 FROM "quote-user" t0 INNER JOIN "quote-phone" t1 ON t0."user-id" = t1."user-id"'
        );

        $this->assertSqlGeneration(
            'SELECT u, g FROM Doctrine\Tests\Models\Quote\User u JOIN u.groups g',
            'SELECT t0."user-id" AS c0, t0."user-name" AS c1, t1."group-id" AS c2, t1."group-name" AS c3 FROM "quote-user" t0 INNER JOIN "quote-users-groups" t2 ON t0."user-id" = t2."user-id" INNER JOIN "quote-group" t1 ON t1."group-id" = t2."group-id"'
        );

        $this->assertSqlGeneration(
            'SELECT a, u FROM Doctrine\Tests\Models\Quote\Address a JOIN a.user u',
            'SELECT t0."address-id" AS c0, t0."address-zip" AS c1, t1."user-id" AS c2, t1."user-name" AS c3, t0."type" AS c4 FROM "quote-address" t0 INNER JOIN "quote-user" t1 ON t0."user-id" = t1."user-id" WHERE t0."type" IN (\'simple\', \'full\')'
        );

        $this->assertSqlGeneration(
            'SELECT g, u FROM Doctrine\Tests\Models\Quote\Group g JOIN g.users u',
            'SELECT t0."group-id" AS c0, t0."group-name" AS c1, t1."user-id" AS c2, t1."user-name" AS c3 FROM "quote-group" t0 INNER JOIN "quote-users-groups" t2 ON t0."group-id" = t2."group-id" INNER JOIN "quote-user" t1 ON t1."user-id" = t2."user-id"'
        );

        $this->assertSqlGeneration(
            'SELECT g, p FROM Doctrine\Tests\Models\Quote\Group g JOIN g.parent p',
            'SELECT t0."group-id" AS c0, t0."group-name" AS c1, t1."group-id" AS c2, t1."group-name" AS c3 FROM "quote-group" t0 INNER JOIN "quote-group" t1 ON t0."parent-id" = t1."group-id"'
        );
    }

    /**
     * @group DDC-2208
     */
    public function testCaseThenParameterArithmeticExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT SUM(CASE WHEN e.salary <= :value THEN e.salary - :value WHEN e.salary >= :value THEN :value - e.salary ELSE 0 END) FROM Doctrine\Tests\Models\Company\CompanyEmployee e',
            'SELECT SUM(CASE WHEN t0."salary" <= ? THEN t0."salary" - ? WHEN t0."salary" >= ? THEN ? - t0."salary" ELSE 0 END) AS c0 FROM "company_employees" t0 INNER JOIN "company_persons" t1 ON t0."id" = t1."id"'
        );

        $this->assertSqlGeneration(
            'SELECT SUM(CASE WHEN e.salary <= :value THEN e.salary - :value WHEN e.salary >= :value THEN :value - e.salary ELSE e.salary + 0 END) FROM Doctrine\Tests\Models\Company\CompanyEmployee e',
            'SELECT SUM(CASE WHEN t0."salary" <= ? THEN t0."salary" - ? WHEN t0."salary" >= ? THEN ? - t0."salary" ELSE t0."salary" + 0 END) AS c0 FROM "company_employees" t0 INNER JOIN "company_persons" t1 ON t0."id" = t1."id"'
        );

        $this->assertSqlGeneration(
            'SELECT SUM(CASE WHEN e.salary <= :value THEN (e.salary - :value) WHEN e.salary >= :value THEN (:value - e.salary) ELSE (e.salary + :value) END) FROM Doctrine\Tests\Models\Company\CompanyEmployee e',
            'SELECT SUM(CASE WHEN t0."salary" <= ? THEN (t0."salary" - ?) WHEN t0."salary" >= ? THEN (? - t0."salary") ELSE (t0."salary" + ?) END) AS c0 FROM "company_employees" t0 INNER JOIN "company_persons" t1 ON t0."id" = t1."id"'
        );
    }

    /**
     * @group DDC-2268
     */
    public function testCaseThenFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT CASE WHEN LENGTH(u.name) <> 0 THEN CONCAT(u.id, u.name) ELSE u.id END AS name  FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT CASE WHEN LENGTH(t0."name") <> 0 THEN t0."id" || t0."name" ELSE t0."id" END AS c0 FROM "cms_users" t0'
        );

        $this->assertSqlGeneration(
            'SELECT CASE WHEN LENGTH(u.name) <> LENGTH(TRIM(u.name)) THEN TRIM(u.name) ELSE u.name END AS name  FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT CASE WHEN LENGTH(t0."name") <> LENGTH(TRIM(t0."name")) THEN TRIM(t0."name") ELSE t0."name" END AS c0 FROM "cms_users" t0'
        );

        $this->assertSqlGeneration(
            'SELECT CASE WHEN LENGTH(u.name) > :value THEN SUBSTRING(u.name, 0, :value) ELSE TRIM(u.name) END AS name  FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT CASE WHEN LENGTH(t0."name") > ? THEN SUBSTRING(t0."name" FROM 0 FOR ?) ELSE TRIM(t0."name") END AS c0 FROM "cms_users" t0'
        );
    }

    /**
     * @group DDC-2268
     */
    public function testSupportsMoreThanTwoParametersInConcatFunctionMySql() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new MySqlPlatform());

        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE CONCAT(u.name, u.status, \'s\') = ?1',
            'SELECT t0.`id` AS c0 FROM `cms_users` t0 WHERE CONCAT(t0.`name`, t0.`status`, \'s\') = ?'
        );

        $this->assertSqlGeneration(
            'SELECT CONCAT(u.id, u.name, u.status) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1',
            'SELECT CONCAT(t0.`id`, t0.`name`, t0.`status`) AS c0 FROM `cms_users` t0 WHERE t0.`id` = ?'
        );
    }

    /**
     * @group DDC-2268
     */
    public function testSupportsMoreThanTwoParametersInConcatFunctionPgSql94() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new PostgreSQL94Platform());

        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE CONCAT(u.name, u.status, \'s\') = ?1',
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE t0."name" || t0."status" || \'s\' = ?'
        );

        $this->assertSqlGeneration(
            'SELECT CONCAT(u.id, u.name, u.status) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1',
            'SELECT t0."id" || t0."name" || t0."status" AS c0 FROM "cms_users" t0 WHERE t0."id" = ?'
        );
    }

    /**
     * @group DDC-2268
     */
    public function testSupportsMoreThanTwoParametersInConcatFunctionPgSql100() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new PostgreSQL100Platform());

        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE CONCAT(u.name, u.status, \'s\') = ?1',
            'SELECT t0."id" AS c0 FROM "cms_users" t0 WHERE t0."name" || t0."status" || \'s\' = ?'
        );

        $this->assertSqlGeneration(
            'SELECT CONCAT(u.id, u.name, u.status) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1',
            'SELECT t0."id" || t0."name" || t0."status" AS c0 FROM "cms_users" t0 WHERE t0."id" = ?'
        );
    }

    /**
     * @group DDC-2268
     */
    public function testSupportsMoreThanTwoParametersInConcatFunctionSqlServer2012() : void
    {
        $this->em->getConnection()->setDatabasePlatform(new SQLServer2012Platform());

        $this->assertSqlGeneration(
            'SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE CONCAT(u.name, u.status, \'s\') = ?1',
            'SELECT t0.[id] AS c0 FROM [cms_users] t0 WHERE (t0.[name] + t0.[status] + \'s\') = ?'
        );

        $this->assertSqlGeneration(
            'SELECT CONCAT(u.id, u.name, u.status) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1',
            'SELECT (t0.[id] + t0.[name] + t0.[status]) AS c0 FROM [cms_users] t0 WHERE t0.[id] = ?'
        );
    }

    /**
     * @group DDC-2188
     */
    public function testArithmeticPriority() : void
    {
        $this->assertSqlGeneration(
            'SELECT 100/(2*2) FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT 100 / (2 * 2) AS c0 FROM "cms_users" t0'
        );

        $this->assertSqlGeneration(
            'SELECT (u.id / (u.id * 2)) FROM Doctrine\Tests\Models\CMS\CmsUser u',
            'SELECT (t0."id" / (t0."id" * 2)) AS c0 FROM "cms_users" t0'
        );

        $this->assertSqlGeneration(
            'SELECT 100/(2*2) + (u.id / (u.id * 2)) FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE (u.id / (u.id * 2)) > 0',
            'SELECT 100 / (2 * 2) + (t0."id" / (t0."id" * 2)) AS c0 FROM "cms_users" t0 WHERE (t0."id" / (t0."id" * 2)) > 0'
        );
    }

    /**
     * @group DDC-2475
     */
    public function testOrderByClauseShouldReplaceOrderByRelationMapping() : void
    {
        $this->assertSqlGeneration(
            'SELECT r, b FROM Doctrine\Tests\Models\Routing\RoutingRoute r JOIN r.bookings b',
            'SELECT t0."id" AS c0, t1."id" AS c1, t1."passengerName" AS c2 FROM "RoutingRoute" t0 INNER JOIN "RoutingRouteBooking" t1 ON t0."id" = t1."route_id" ORDER BY t1."passengerName" ASC'
        );

        $this->assertSqlGeneration(
            'SELECT r, b FROM Doctrine\Tests\Models\Routing\RoutingRoute r JOIN r.bookings b ORDER BY b.passengerName DESC',
            'SELECT t0."id" AS c0, t1."id" AS c1, t1."passengerName" AS c2 FROM "RoutingRoute" t0 INNER JOIN "RoutingRouteBooking" t1 ON t0."id" = t1."route_id" ORDER BY t1."passengerName" DESC'
        );
    }

    /**
     * @group DDC-1858
     */
    public function testHavingSupportIsNullExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u HAVING u.username IS NULL',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 HAVING t0."username" IS NULL'
        );

        $this->assertSqlGeneration(
            'SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u HAVING MAX(u.name) IS NULL',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 HAVING MAX(t0."name") IS NULL'
        );
    }

    /**
     * @group DDC-2506
     */
    public function testClassTableInheritanceJoinWithConditionAppliesToBaseTable() : void
    {
        $this->assertSqlGeneration(
            'SELECT e.id FROM Doctrine\Tests\Models\Company\CompanyOrganization o JOIN o.events e WITH e.id = ?1',
            'SELECT t0."id" AS c0 FROM "company_organizations" t1 INNER JOIN ("company_events" t0 LEFT JOIN "company_auctions" t2 ON t0."id" = t2."id" LEFT JOIN "company_raffles" t3 ON t0."id" = t3."id") ON t1."id" = t0."org_id" AND (t0."id" = ?)',
            [ORMQuery::HINT_FORCE_PARTIAL_LOAD => false]
        );
    }

    /**
     * @group DDC-2235
     */
    public function testSingleTableInheritanceLeftJoinWithCondition() : void
    {
        // Regression test for the bug
        $this->assertSqlGeneration(
            'SELECT c FROM Doctrine\Tests\Models\Company\CompanyEmployee e LEFT JOIN Doctrine\Tests\Models\Company\CompanyContract c WITH c.salesPerson = e.id',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."fixPrice" AS c2, t0."hoursWorked" AS c3, t0."pricePerHour" AS c4, t0."maxPrice" AS c5, t0."discr" AS c6 FROM "company_employees" t1 INNER JOIN "company_persons" t2 ON t1."id" = t2."id" LEFT JOIN "company_contracts" t0 ON (t0."salesPerson_id" = t2."id") AND t0."discr" IN (\'fix\', \'flexible\', \'flexultra\')'
        );
    }

    /**
     * @group DDC-2235
     */
    public function testSingleTableInheritanceLeftJoinWithConditionAndWhere() : void
    {
        // Ensure other WHERE predicates are passed through to the main WHERE clause
        $this->assertSqlGeneration(
            'SELECT c FROM Doctrine\Tests\Models\Company\CompanyEmployee e LEFT JOIN Doctrine\Tests\Models\Company\CompanyContract c WITH c.salesPerson = e.id WHERE e.salary > 1000',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."fixPrice" AS c2, t0."hoursWorked" AS c3, t0."pricePerHour" AS c4, t0."maxPrice" AS c5, t0."discr" AS c6 FROM "company_employees" t1 INNER JOIN "company_persons" t2 ON t1."id" = t2."id" LEFT JOIN "company_contracts" t0 ON (t0."salesPerson_id" = t2."id") AND t0."discr" IN (\'fix\', \'flexible\', \'flexultra\') WHERE t1."salary" > 1000'
        );
    }

    /**
     * @group DDC-2235
     */
    public function testSingleTableInheritanceInnerJoinWithCondition() : void
    {
        // Test inner joins too
        $this->assertSqlGeneration(
            'SELECT c FROM Doctrine\Tests\Models\Company\CompanyEmployee e INNER JOIN Doctrine\Tests\Models\Company\CompanyContract c WITH c.salesPerson = e.id',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."fixPrice" AS c2, t0."hoursWorked" AS c3, t0."pricePerHour" AS c4, t0."maxPrice" AS c5, t0."discr" AS c6 FROM "company_employees" t1 INNER JOIN "company_persons" t2 ON t1."id" = t2."id" INNER JOIN "company_contracts" t0 ON (t0."salesPerson_id" = t2."id") AND t0."discr" IN (\'fix\', \'flexible\', \'flexultra\')'
        );
    }

    /**
     * @group DDC-2235
     */
    public function testSingleTableInheritanceLeftJoinNonAssociationWithConditionAndWhere() : void
    {
        // Test that the discriminator IN() predicate is still added into
        // the where clause when not joining onto that table
        $this->assertSqlGeneration(
            'SELECT c FROM Doctrine\Tests\Models\Company\CompanyContract c LEFT JOIN Doctrine\Tests\Models\Company\CompanyEmployee e WITH e.id = c.salesPerson WHERE c.completed = true',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."fixPrice" AS c2, t0."hoursWorked" AS c3, t0."pricePerHour" AS c4, t0."maxPrice" AS c5, t0."discr" AS c6 FROM "company_contracts" t0 LEFT JOIN ("company_employees" t1 INNER JOIN "company_persons" t2 ON t1."id" = t2."id") ON (t2."id" = t0."salesPerson_id") WHERE (t0."completed" = 1) AND t0."discr" IN (\'fix\', \'flexible\', \'flexultra\')'
        );
    }

    /**
     * @group DDC-2235
     */
    public function testSingleTableInheritanceJoinCreatesOnCondition() : void
    {
        // Test that the discriminator IN() predicate is still added
        // into the where clause when not joining onto a single table inheritance entity
        // via a join association
        $this->assertSqlGeneration(
            'SELECT c FROM Doctrine\Tests\Models\Company\CompanyContract c JOIN c.salesPerson s WHERE c.completed = true',
            'SELECT t0."id" AS c0, t0."completed" AS c1, t0."fixPrice" AS c2, t0."hoursWorked" AS c3, t0."pricePerHour" AS c4, t0."maxPrice" AS c5, t0."discr" AS c6 FROM "company_contracts" t0 INNER JOIN "company_employees" t1 ON t0."salesPerson_id" = t1."id" LEFT JOIN "company_persons" t2 ON t1."id" = t2."id" WHERE (t0."completed" = 1) AND t0."discr" IN (\'fix\', \'flexible\', \'flexultra\')'
        );
    }

    /**
     * @group DDC-2235
     */
    public function testSingleTableInheritanceCreatesOnConditionAndWhere() : void
    {
        // Test that when joining onto an entity using single table inheritance via
        // a join association that the discriminator IN() predicate is placed
        // into the ON clause of the join
        $this->assertSqlGeneration(
            'SELECT e, COUNT(c) FROM Doctrine\Tests\Models\Company\CompanyEmployee e JOIN e.contracts c WHERE e.department = :department',
            'SELECT t0."id" AS c0, t0."name" AS c1, t1."salary" AS c2, t1."department" AS c3, t1."startDate" AS c4, COUNT(t2."id") AS c5, t0."discr" AS c6 FROM "company_employees" t1 INNER JOIN "company_persons" t0 ON t1."id" = t0."id" INNER JOIN "company_contract_employees" t3 ON t1."id" = t3."employee_id" INNER JOIN "company_contracts" t2 ON t2."id" = t3."contract_id" AND t2."discr" IN (\'fix\', \'flexible\', \'flexultra\') WHERE t1."department" = ?',
            [],
            ['department' => 'foobar']
        );
    }

    /**
     * @group DDC-1858
     */
    public function testHavingSupportResultVariableInExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name AS foo FROM Doctrine\Tests\Models\CMS\CmsUser u HAVING foo IN (?1)',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 HAVING c0 IN (?)'
        );
    }

    /**
     * @group DDC-1858
     */
    public function testHavingSupportResultVariableLikeExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u.name AS foo FROM Doctrine\Tests\Models\CMS\CmsUser u HAVING foo LIKE \'3\'',
            'SELECT t0."name" AS c0 FROM "cms_users" t0 HAVING c0 LIKE \'3\''
        );
    }

    /**
     * @group DDC-3085
     */
    public function testHavingSupportResultVariableNullComparisonExpression() : void
    {
        $this->assertSqlGeneration(
            'SELECT u AS user, SUM(a.id) AS score FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN Doctrine\Tests\Models\CMS\CmsAddress a WITH a.user = u GROUP BY u HAVING score IS NOT NULL AND score >= 5',
            'SELECT t0."id" AS c0, t0."status" AS c1, t0."username" AS c2, t0."name" AS c3, SUM(t1."id") AS c4 FROM "cms_users" t0 LEFT JOIN "cms_addresses" t1 ON (t1."user_id" = t0."id") GROUP BY t0."id", t0."status", t0."username", t0."name", t0."email_id" HAVING c4 IS NOT NULL AND c4 >= 5'
        );
    }

    /**
     * @group DDC-1858
     */
    public function testHavingSupportResultVariableInAggregateFunction() : void
    {
        $this->assertSqlGeneration(
            'SELECT COUNT(u.name) AS countName FROM Doctrine\Tests\Models\CMS\CmsUser u HAVING countName IS NULL',
            'SELECT COUNT(t0."name") AS c0 FROM "cms_users" t0 HAVING c0 IS NULL'
        );
    }

    /**
     * GitHub issue #4764: https://github.com/doctrine/orm/issues/4764
     *
     * @group DDC-3907
     * @dataProvider mathematicOperatorsProvider
     */
    public function testHavingRegressionUsingVariableWithMathOperatorsExpression($operator) : void
    {
        $this->assertSqlGeneration(
            'SELECT COUNT(u.name) AS countName FROM Doctrine\Tests\Models\CMS\CmsUser u HAVING 1 ' . $operator . ' countName > 0',
            'SELECT COUNT(t0."name") AS c0 FROM "cms_users" t0 HAVING 1 ' . $operator . ' c0 > 0'
        );
    }

    /**
     * @return array
     */
    public function mathematicOperatorsProvider()
    {
        return [['+'], ['-'], ['*'], ['/']];
    }
}

class MyAbsFunction extends FunctionNode
{
    public $simpleArithmeticExpression;

    public function getSql(SqlWalker $sqlWalker)
    {
        return 'ABS(' . $sqlWalker->walkSimpleArithmeticExpression($this->simpleArithmeticExpression) . ')';
    }

    public function parse(Parser $parser)
    {
        $lexer = $parser->getLexer();

        $parser->match(Lexer::T_IDENTIFIER);
        $parser->match(Lexer::T_OPEN_PARENTHESIS);

        $this->simpleArithmeticExpression = $parser->SimpleArithmeticExpression();

        $parser->match(Lexer::T_CLOSE_PARENTHESIS);
    }
}
/**
 * @ORM\Entity
 */
class DDC1384Model
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $aVeryLongIdentifierThatShouldBeShortenedByTheSQLWalker_fooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooo;
}


/**
 * @ORM\Entity
 */
class DDC1474Entity
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue()
     */
    protected $id;

    /** @ORM\Column(type="float") */
    private $value;

    /**
     * @param string $float
     */
    public function __construct($float)
    {
        $this->value = $float;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return float
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param float $value
     */
    public function setValue($value)
    {
        $this->value = $value;
    }
}
