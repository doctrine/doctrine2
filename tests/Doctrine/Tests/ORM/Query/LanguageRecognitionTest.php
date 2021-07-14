<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ParserResult;
use Doctrine\ORM\Query\QueryException;
use Doctrine\Tests\Mocks\MockTreeWalker;
use Doctrine\Tests\OrmTestCase;

use const PHP_EOL;

class LanguageRecognitionTest extends OrmTestCase
{
    /** @var EntityManagerInterface */
    private $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->getTestEntityManager();
    }

    public function assertValidDQL($dql, $debug = false): void
    {
        try {
            $parserResult = $this->parseDql($dql);
            $this->addToAssertionCount(1);
        } catch (QueryException $e) {
            if ($debug) {
                echo $e->getTraceAsString() . PHP_EOL;
            }

            $this->fail($e->getMessage());
        }
    }

    public function assertInvalidDQL($dql, $debug = false): void
    {
        try {
            $parserResult = $this->parseDql($dql);

            $this->fail('No syntax errors were detected, when syntax errors were expected');
        } catch (QueryException $e) {
            if ($debug) {
                echo $e->getMessage() . PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL;
            }

            $this->addToAssertionCount(1);
        }
    }

    /**
     * @psalm-param array<string, mixed> $hints
     */
    public function parseDql(string $dql, array $hints = []): ParserResult
    {
        $query = $this->entityManager->createQuery($dql);
        $query->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true);
        $query->setDQL($dql);

        foreach ($hints as $key => $value) {
            $query->setHint($key, $value);
        }

        $parser = new Query\Parser($query);

        // We do NOT test SQL output here. That only unnecessarily slows down the tests!
        $parser->setCustomOutputTreeWalker(MockTreeWalker::class);

        return $parser->parse();
    }

    public function testEmptyQueryString(): void
    {
        $this->assertInvalidDQL('');
    }

    public function testPlainFromClauseWithAlias(): void
    {
        $this->assertValidDQL('SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testSelectSingleComponentWithAsterisk(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    /**
     * @dataProvider invalidDQL
     */
    public function testRejectsInvalidDQL(string $dql): void
    {
        $this->expectException(QueryException::class);

        $this->entityManager->getConfiguration()->setEntityNamespaces(
            [
                'Unknown' => 'Unknown',
                'CMS' => 'Doctrine\Tests\Models\CMS',
            ]
        );

        $this->parseDql($dql);
    }

    /**
     * @psalm-return list<array{string}>
     */
    public function invalidDQL(): array
    {
        return [

            ['SELECT \'foo\' AS foo\bar FROM Doctrine\Tests\Models\CMS\CmsUser u'],
            /* Checks for invalid IdentificationVariables and AliasIdentificationVariables */
            ['SELECT \foo FROM Doctrine\Tests\Models\CMS\CmsUser \foo'],
            ['SELECT foo\ FROM Doctrine\Tests\Models\CMS\CmsUser foo\\'],
            ['SELECT foo\bar FROM Doctrine\Tests\Models\CMS\CmsUser foo\bar'],
            ['SELECT foo:bar FROM Doctrine\Tests\Models\CMS\CmsUser foo:bar'],
            ['SELECT foo: FROM Doctrine\Tests\Models\CMS\CmsUser foo:'],

            /* Checks for invalid AbstractSchemaName */
            ['SELECT u FROM UnknownClass u'],  // unknown
            ['SELECT u FROM Unknown\Class u'], // unknown with namespace
            ['SELECT u FROM \Unknown\Class u'], // unknown, leading backslash
            ['SELECT u FROM Unknown\\\\Class u'], // unknown, syntactically bogus (duplicate \\)
            ['SELECT u FROM Unknown\Class\ u'], // unknown, syntactically bogus (trailing \)
            ['SELECT u FROM Unknown:Class u'], // unknown, with namespace alias
            ['SELECT u FROM Unknown::Class u'], // unknown, with PAAMAYIM_NEKUDOTAYIM
            ['SELECT u FROM Unknown:Class:Name u'], // unknown, with invalid namespace alias
            ['SELECT u FROM UnknownClass: u'], // unknown, with invalid namespace alias
            ['SELECT u FROM Unknown:Class: u'], // unknown, with invalid namespace alias
            ['SELECT u FROM Doctrine\Tests\Models\CMS\\\\CmsUser u'], // syntactically bogus (duplicate \\)array('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser\ u'), // syntactically bogus (trailing \)
            ['SELECT u FROM CMS::User u'],
            ['SELECT u FROM CMS:User: u'],
            ['SELECT u FROM CMS:User:Foo u'],

            /* Checks for invalid AliasResultVariable */
            ['SELECT \'foo\' AS \foo FROM Doctrine\Tests\Models\CMS\CmsUser u'],
            ['SELECT \'foo\' AS \foo\bar FROM Doctrine\Tests\Models\CMS\CmsUser u'],
            ['SELECT \'foo\' AS foo\ FROM Doctrine\Tests\Models\CMS\CmsUser u'],
            ['SELECT \'foo\' AS foo\\\\bar FROM Doctrine\Tests\Models\CMS\CmsUser u'],
            ['SELECT \'foo\' AS foo: FROM Doctrine\Tests\Models\CMS\CmsUser u'],
            ['SELECT \'foo\' AS foo:bar FROM Doctrine\Tests\Models\CMS\CmsUser u'],

            ['0'],
        ];
    }

    public function testSelectSingleComponentWithMultipleColumns(): void
    {
        $this->assertValidDQL('SELECT u.name, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testSelectMultipleComponentsUsingMultipleFrom(): void
    {
        $this->assertValidDQL('SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE u = p.user');
    }

    public function testSelectMultipleComponentsWithAsterisk(): void
    {
        $this->assertValidDQL('SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.phonenumbers p');
    }

    public function testSelectDistinctIsSupported(): void
    {
        $this->assertValidDQL('SELECT DISTINCT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testAggregateFunctionInSelect(): void
    {
        $this->assertValidDQL('SELECT COUNT(u.id) FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testMultipleParenthesisInSelect(): void
    {
        $this->assertValidDQL('SELECT (((u.id))) as v FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testDuplicatedAliasInAggregateFunction(): void
    {
        $this->assertInvalidDQL('SELECT COUNT(u.id) AS num, SUM(u.id) AS num FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testAggregateFunctionWithDistinctInSelect(): void
    {
        $this->assertValidDQL('SELECT COUNT(DISTINCT u.name) FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testFunctionalExpressionsSupportedInWherePart(): void
    {
        $this->assertValidDQL("SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE TRIM(u.name) = 'someone'");
    }

    public function testArithmeticExpressionsSupportedInWherePart(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE ((u.id + 5000) * u.id + 3) < 10000000');
    }

    public function testInExpressionSupportedInWherePart(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id IN (1, 2)');
    }

    public function testInExpressionWithoutSpacesSupportedInWherePart(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id IN (1,2,3)');
    }

    public function testNotInExpressionSupportedInWherePart(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id NOT IN (1)');
    }

    public function testInExpressionWithSingleValuedAssociationPathExpression(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\Forum\ForumUser u WHERE u.avatar IN (?1, ?2)');
    }

    public function testInvalidInExpressionWithCollectionValuedAssociationPathExpression(): void
    {
        $this->assertInvalidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.phonenumbers IN (?1, ?2)');
    }

    public function testInstanceOfExpressionSupportedInWherePart(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\Company\CompanyPerson u WHERE u INSTANCE OF Doctrine\Tests\Models\Company\CompanyEmployee');
    }

    public function testInstanceOfExpressionWithInputParamSupportedInWherePart(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\Company\CompanyPerson u WHERE u INSTANCE OF ?1');
    }

    public function testNotInstanceOfExpressionSupportedInWherePart(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\Company\CompanyPerson u WHERE u NOT INSTANCE OF ?1');
    }

    public function testExistsExpressionSupportedInWherePart(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE EXISTS (SELECT p.phonenumber FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE p.phonenumber = 1234)');
    }

    public function testNotExistsExpressionSupportedInWherePart(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE NOT EXISTS (SELECT p.phonenumber FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE p.phonenumber = 1234)');
    }

    public function testAggregateFunctionInHavingClause(): void
    {
        $this->assertValidDQL('SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.phonenumbers p HAVING COUNT(p.phonenumber) > 2');
        $this->assertValidDQL("SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.phonenumbers p HAVING MAX(u.name) = 'romanb'");
    }

    public function testLeftJoin(): void
    {
        $this->assertValidDQL('SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.phonenumbers p');
    }

    public function testJoin(): void
    {
        $this->assertValidDQL('SELECT u,p FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.phonenumbers p');
    }

    public function testInnerJoin(): void
    {
        $this->assertValidDQL('SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.phonenumbers p');
    }

    public function testMultipleLeftJoin(): void
    {
        $this->assertValidDQL('SELECT u, a, p FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a LEFT JOIN u.phonenumbers p');
    }

    public function testMultipleInnerJoin(): void
    {
        $this->assertValidDQL('SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a INNER JOIN u.phonenumbers p');
    }

    public function testMixingOfJoins(): void
    {
        $this->assertValidDQL('SELECT u.name, a.topic, p.phonenumber FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a LEFT JOIN u.phonenumbers p');
    }

    public function testJoinClassPathUsingWITH(): void
    {
        $this->assertValidDQL('SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN Doctrine\Tests\Models\CMS\CmsArticle a WITH a.user = u.id');
    }

    /**
     * @group DDC-3701
     */
    public function testJoinClassPathUsingWHERE(): void
    {
        $this->assertValidDQL('SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN Doctrine\Tests\Models\CMS\CmsArticle a WHERE a.user = u.id');
    }

    /**
     * @group DDC-3701
     */
    public function testDDC3701WHEREIsNotWITH(): void
    {
        $this->assertInvalidDQL('SELECT c FROM Doctrine\Tests\Models\Company\CompanyContract c JOIN Doctrine\Tests\Models\Company\CompanyEmployee e WHERE e.id = c.salesPerson WHERE c.completed = true');
    }

    public function testOrderBySingleColumn(): void
    {
        $this->assertValidDQL('SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.name');
    }

    public function testOrderBySingleColumnAscending(): void
    {
        $this->assertValidDQL('SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.name ASC');
    }

    public function testOrderBySingleColumnDescending(): void
    {
        $this->assertValidDQL('SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.name DESC');
    }

    public function testOrderByMultipleColumns(): void
    {
        $this->assertValidDQL('SELECT u.name, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.username DESC, u.name DESC');
    }

    public function testSubselectInInExpression(): void
    {
        $this->assertValidDQL("SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id NOT IN (SELECT u2.id FROM Doctrine\Tests\Models\CMS\CmsUser u2 WHERE u2.name = 'zYne')");
    }

    public function testSubselectInSelectPart(): void
    {
        $this->assertValidDQL("SELECT u.name, (SELECT COUNT(p.phonenumber) FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE p.phonenumber = 1234) pcount FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = 'jon'");
    }

    public function testArithmeticExpressionInSelectPart(): void
    {
        $this->assertValidDQL('SELECT SUM(u.id) / COUNT(u.id) FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testArithmeticExpressionInSubselectPart(): void
    {
        $this->assertValidDQL("SELECT (SELECT SUM(u.id) / COUNT(u.id) FROM Doctrine\Tests\Models\CMS\CmsUser u2) value FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = 'jon'");
    }

    public function testArithmeticExpressionWithParenthesisInSubselectPart(): void
    {
        $this->assertValidDQL("SELECT (SELECT (SUM(u.id) / COUNT(u.id)) FROM Doctrine\Tests\Models\CMS\CmsUser u2) value FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = 'jon'");
    }

    /**
     * @group DDC-1079
     */
    public function testSelectLiteralInSubselect(): void
    {
        $this->assertValidDQL('SELECT (SELECT 1 FROM Doctrine\Tests\Models\CMS\CmsUser u2) value FROM Doctrine\Tests\Models\CMS\CmsUser u');
        $this->assertValidDQL('SELECT (SELECT 0 FROM Doctrine\Tests\Models\CMS\CmsUser u2) value FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    /**
     * @group DDC-1077
     */
    public function testConstantValueInSelect(): void
    {
        $this->assertValidDQL("SELECT u.name, 'foo' AS bar FROM Doctrine\Tests\Models\CMS\CmsUser u", true);
    }

    public function testDuplicateAliasInSubselectPart(): void
    {
        $this->assertInvalidDQL("SELECT (SELECT SUM(u.id) / COUNT(u.id) AS foo FROM Doctrine\Tests\Models\CMS\CmsUser u2) foo FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = 'jon'");
    }

    public function testPositionalInputParameter(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1');
    }

    public function testNamedInputParameter(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :id');
    }

    public function testJoinConditionOverrideNotSupported(): void
    {
        $this->assertInvalidDQL("SELECT u.name, p FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.phonenumbers p ON p.phonenumber = '123 123'");
    }

    public function testIndexByClauseWithOneComponent(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u INDEX BY u.id');
    }

    public function testIndexBySupportsJoins(): void
    {
        $this->assertValidDQL('SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a INDEX BY a.id'); // INDEX BY is now referring to articles
    }

    public function testIndexBySupportsJoins2(): void
    {
        $this->assertValidDQL('SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u INDEX BY u.id LEFT JOIN u.phonenumbers p INDEX BY p.phonenumber');
    }

    public function testBetweenExpressionSupported(): void
    {
        $this->assertValidDQL("SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name BETWEEN 'jepso' AND 'zYne'");
    }

    public function testNotBetweenExpressionSupported(): void
    {
        $this->assertValidDQL("SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name NOT BETWEEN 'jepso' AND 'zYne'");
    }

    public function testLikeExpression(): void
    {
        $this->assertValidDQL("SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name LIKE 'z%'");
    }

    public function testNotLikeExpression(): void
    {
        $this->assertValidDQL("SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name NOT LIKE 'z%'");
    }

    public function testLikeExpressionWithCustomEscapeCharacter(): void
    {
        $this->assertValidDQL("SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name LIKE 'z|%' ESCAPE '|'");
    }

    public function testFieldComparisonWithoutAlias(): void
    {
        $this->assertInvalidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE id = 1');
    }

    public function testDuplicatedAliasDeclaration(): void
    {
        $this->assertInvalidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles u WHERE u.id = 1');
    }

    public function testImplicitJoinInWhereOnSingleValuedAssociationPathExpression(): void
    {
        // This should be allowed because avatar is a single-value association.
        // SQL: SELECT ... FROM forum_user fu INNER JOIN forum_avatar fa ON fu.avatar_id = fa.id WHERE fa.id = ?
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\Forum\ForumUser u JOIN u.avatar a WHERE a.id = ?1');
    }

    public function testImplicitJoinInWhereOnCollectionValuedPathExpression(): void
    {
        // This should be forbidden, because articles is a collection
        $this->assertInvalidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.articles a WHERE a.title = ?');
    }

    public function testInvalidSyntaxIsRejected(): void
    {
        $this->assertInvalidDQL('FOOBAR CmsUser');
        $this->assertInvalidDQL('DELETE FROM Doctrine\Tests\Models\CMS\CmsUser.articles');
        $this->assertInvalidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.articles.comments');

        // Currently UNDEFINED OFFSET error
        $this->assertInvalidDQL('SELECT c FROM CmsUser.articles.comments c');
    }

    public function testUpdateWorksWithOneField(): void
    {
        $this->assertValidDQL("UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = 'someone'");
    }

    public function testUpdateWorksWithMultipleFields(): void
    {
        $this->assertValidDQL("UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = 'someone', u.username = 'some'");
    }

    public function testUpdateSupportsConditions(): void
    {
        $this->assertValidDQL("UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = 'someone' WHERE u.id = 5");
    }

    public function testDeleteAll(): void
    {
        $this->assertValidDQL('DELETE FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testDeleteWithCondition(): void
    {
        $this->assertValidDQL('DELETE FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = 3');
    }

    /**
     * The main use case for this generalized style of join is when a join condition
     * does not involve a foreign key relationship that is mapped to an entity relationship.
     */
    public function testImplicitJoinWithCartesianProductAndConditionInWhere(): void
    {
        $this->assertValidDQL('SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a WHERE u.name = a.topic');
    }

    public function testAllExpressionWithCorrelatedSubquery(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id > ALL (SELECT u2.id FROM Doctrine\Tests\Models\CMS\CmsUser u2 WHERE u2.name = u.name)');
    }

    public function testCustomJoinsAndWithKeywordSupported(): void
    {
        $this->assertValidDQL('SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.phonenumbers p WITH p.phonenumber = 123 WHERE u.id = 1');
    }

    public function testAnyExpressionWithCorrelatedSubquery(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id > ANY (SELECT u2.id FROM Doctrine\Tests\Models\CMS\CmsUser u2 WHERE u2.name = u.name)');
    }

    public function testSomeExpressionWithCorrelatedSubquery(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id > SOME (SELECT u2.id FROM Doctrine\Tests\Models\CMS\CmsUser u2 WHERE u2.name = u.name)');
    }

    public function testArithmeticExpressionWithoutParenthesisInWhereClause(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE SIZE(u.phonenumbers) + 1 > 10');
    }

    public function testMemberOfExpression(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE :param MEMBER OF u.phonenumbers');
        //$this->assertValidDQL("SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE 'Joe' MEMBER OF u.nicknames");
    }

    public function testSizeFunction(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE SIZE(u.phonenumbers) > 1');
    }

    public function testEmptyCollectionComparisonExpression(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.phonenumbers IS EMPTY');
    }

    public function testSingleValuedAssociationFieldInWhere(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.address = ?1');
        $this->assertValidDQL('SELECT p FROM Doctrine\Tests\Models\CMS\CmsPhonenumber p WHERE p.user = ?1');
    }

    public function testBooleanLiteralInWhere(): void
    {
        $this->assertValidDQL('SELECT b FROM Doctrine\Tests\Models\Generic\BooleanModel b WHERE b.booleanField = true');
    }

    public function testSubqueryInSelectExpression(): void
    {
        $this->assertValidDQL('select u, (select max(p.phonenumber) from Doctrine\Tests\Models\CMS\CmsPhonenumber p) maxId from Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testUsageOfQComponentOutsideSubquery(): void
    {
        $this->assertInvalidDQL('select u, (select max(p.phonenumber) from Doctrine\Tests\Models\CMS\CmsPhonenumber p) maxId from Doctrine\Tests\Models\CMS\CmsUser u WHERE p.user = ?1');
    }

    public function testUnknownAbstractSchemaName(): void
    {
        $this->assertInvalidDQL('SELECT u FROM UnknownClassName u');
    }

    public function testCorrectPartialObjectLoad(): void
    {
        $this->assertValidDQL('SELECT PARTIAL u.{id,name} FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testIncorrectPartialObjectLoadBecauseOfMissingIdentifier(): void
    {
        $this->assertInvalidDQL('SELECT PARTIAL u.{name} FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testScalarExpressionInSelect(): void
    {
        $this->assertValidDQL('SELECT u, 42 + u.id AS someNumber FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testInputParameterInSelect(): void
    {
        $this->assertValidDQL('SELECT u, u.id + ?1 AS someNumber FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    /**
     * @group DDC-1091
     */
    public function testCustomFunctionsReturningStringInStringPrimary(): void
    {
        $this->entityManager->getConfiguration()->addCustomStringFunction('CC', Query\AST\Functions\ConcatFunction::class);

        $this->assertValidDQL("SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE CC('%', u.name) LIKE '%foo%'", true);
    }

    /**
     * @group DDC-505
     */
    public function testDQLKeywordInJoinIsAllowed(): void
    {
        $this->assertValidDQL('SELECT u FROM ' . __NAMESPACE__ . '\DQLKeywordsModelUser u JOIN u.group g');
    }

    /**
     * @group DDC-505
     */
    public function testDQLKeywordInConditionIsAllowed(): void
    {
        $this->assertValidDQL('SELECT g FROM ' . __NAMESPACE__ . '\DQLKeywordsModelGroup g WHERE g.from=0');
    }

    /* The exception is currently thrown in the SQLWalker, not earlier.
    public function testInverseSideSingleValuedAssociationPathNotAllowed()
    {
        $this->assertInvalidDQL('SELECT u.id FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.address = ?1');
    }
    */

    /**
     * @group DDC-617
     */
    public function testSelectOnlyNonRootEntityAlias(): void
    {
        $this->assertInvalidDQL('SELECT g FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.groups g');
    }

    /**
     * @group DDC-1108
     */
    public function testInputParameterSingleChar(): void
    {
        $this->assertValidDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = :q');
    }

    /**
     * @group DDC-1053
     */
    public function testGroupBy(): void
    {
        $this->assertValidDQL('SELECT g.id, count(u.id) FROM Doctrine\Tests\Models\CMS\CmsGroup g JOIN g.users u GROUP BY g.id');
    }

    /**
     * @group DDC-1053
     */
    public function testGroupByIdentificationVariable(): void
    {
        $this->assertValidDQL('SELECT g, count(u.id) FROM Doctrine\Tests\Models\CMS\CmsGroup g JOIN g.users u GROUP BY g');
    }

    /**
     * @group DDC-1053
     */
    public function testGroupByUnknownIdentificationVariable(): void
    {
        $this->assertInvalidDQL('SELECT g, count(u.id) FROM Doctrine\Tests\Models\CMS\CmsGroup g JOIN g.users u GROUP BY m');
    }

    /**
     * @group DDC-117
     */
    public function testSizeOfForeignKeyOneToManyPrimaryKeyEntity(): void
    {
        $this->assertValidDQL('SELECT a, t FROM Doctrine\Tests\Models\DDC117\DDC117Article a JOIN a.translations t WHERE SIZE(a.translations) > 0');
    }

    /**
     * @group DDC-117
     */
    public function testSizeOfForeignKeyManyToManyPrimaryKeyEntity(): void
    {
        $this->assertValidDQL('SELECT e, t FROM Doctrine\Tests\Models\DDC117\DDC117Editor e JOIN e.reviewingTranslations t WHERE SIZE(e.reviewingTranslations) > 0');
    }

    public function testCaseSupportContainingNullIfExpression(): void
    {
        $this->assertValidDQL('SELECT u.id, NULLIF(u.name, u.name) AS shouldBeNull FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testCaseSupportContainingCoalesceExpression(): void
    {
        $this->assertValidDQL("select COALESCE(NULLIF(u.name, ''), u.username) as Display FROM Doctrine\Tests\Models\CMS\CmsUser u");
    }

    /**
     * @group DDC-1858
     */
    public function testHavingSupportIsNullExpression(): void
    {
        $this->assertValidDQL('SELECT u.name FROM Doctrine\Tests\Models\CMS\CmsUser u HAVING u.username IS NULL');
    }

    /**
     * @group DDC-3085
     */
    public function testHavingSupportResultVariableInNullComparisonExpression(): void
    {
        $this->assertValidDQL('SELECT u AS user, SUM(a.id) AS score FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN Doctrine\Tests\Models\CMS\CmsAddress a WITH a.user = u GROUP BY u HAVING score IS NOT NULL AND score >= 5');
    }

    /**
     * @group DDC-1858
     */
    public function testHavingSupportLikeExpression(): void
    {
        $this->assertValidDQL("SELECT _u.id, count(_articles) as uuuu FROM Doctrine\Tests\Models\CMS\CmsUser _u LEFT JOIN _u.articles _articles GROUP BY _u HAVING uuuu LIKE '3'");
    }

    /**
     * @group DDC-3018
     */
    public function testNewLiteralExpression(): void
    {
        $this->assertValidDQL('SELECT new ' . __NAMESPACE__ . "\\DummyStruct(u.id, 'foo', 1, true) FROM Doctrine\Tests\Models\CMS\CmsUser u");
    }

    /**
     * @group DDC-3075
     */
    public function testNewLiteralWithSubselectExpression(): void
    {
        $this->assertValidDQL('SELECT new ' . __NAMESPACE__ . "\\DummyStruct(u.id, 'foo', (SELECT 1 FROM Doctrine\Tests\Models\CMS\CmsUser su), true) FROM Doctrine\Tests\Models\CMS\CmsUser u");
    }

    public function testStringPrimaryAcceptsAggregateExpression(): void
    {
        $this->assertValidDQL(
            'SELECT CONCAT(a.topic, MAX(a.version)) last FROM Doctrine\Tests\Models\CMS\CmsArticle a GROUP BY a'
        );
    }
}

/** @Entity */
class DQLKeywordsModelUser
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var DQLKeywordsModelGroup
     * @OneToOne(targetEntity="DQLKeywordsModelGroup")
     */
    private $group;
}

/** @Entity */
class DQLKeywordsModelGroup
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var string
     * @Column
     */
    private $from;
}

class DummyStruct
{
    public function __construct($id, $arg1, $arg2, $arg3)
    {
    }
}
