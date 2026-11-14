<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Tests\DbalTypes\NegativeToPositiveType;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test case for testing the saving and referencing of query identifiers.
 *
 * @link        http://www.phpdoctrine.org
 *
 * @todo        1) [romanb] We  might want to split the SQL generation tests into multiple
 *              testcases later since we'll have a lot of them and we might want to have special SQL
 *              generation tests for some dbms specific SQL syntaxes.
 */
class UpdateSqlGenerationTest extends OrmTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::registerDbalType(NegativeToPositiveType::NAME, NegativeToPositiveType::class);

        $this->entityManager = $this->getTestEntityManager();
    }

    public function assertSqlGeneration($dqlToBeTested, $sqlToBeConfirmed): void
    {
        $query = $this->entityManager->createQuery($dqlToBeTested);

        parent::assertEquals($sqlToBeConfirmed, $query->getSql());

        $query->free();
    }

    public function testSupportsQueriesWithoutWhere(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = ?1',
            'UPDATE cms_users SET name = ?',
        );
    }

    public function testSupportsMultipleFieldsWithoutWhere(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = ?1, u.username = ?2',
            'UPDATE cms_users SET name = ?, username = ?',
        );
    }

    public function testSupportsWhereClauses(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = ?1 WHERE u.id = ?2',
            'UPDATE cms_users SET name = ? WHERE id = ?',
        );
    }

    public function testSupportsWhereClausesOnTheUpdatedField(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = ?1 WHERE u.name = ?2',
            'UPDATE cms_users SET name = ? WHERE name = ?',
        );
    }

    public function testSupportsMultipleWhereClause(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = ?1 WHERE u.name = ?2 AND u.status = ?3',
            'UPDATE cms_users SET name = ? WHERE name = ? AND status = ?',
        );
    }

    public function testSupportsInClause(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = ?1 WHERE u.id IN (1, 3, 4)',
            'UPDATE cms_users SET name = ? WHERE id IN (1, 3, 4)',
        );
    }

    public function testSupportsParametrizedInClause(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = ?1 WHERE u.id IN (?2, ?3, ?4)',
            'UPDATE cms_users SET name = ? WHERE id IN (?, ?, ?)',
        );
    }

    public function testSupportsNotInClause(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.name = ?1 WHERE u.id NOT IN (1, 3, 4)',
            'UPDATE cms_users SET name = ? WHERE id NOT IN (1, 3, 4)',
        );
    }

    public function testSupportsGreaterThanClause(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.status = ?1 WHERE u.id > ?2',
            'UPDATE cms_users SET status = ? WHERE id > ?',
        );
    }

    public function testSupportsGreaterThanOrEqualToClause(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.status = ?1 WHERE u.id >= ?2',
            'UPDATE cms_users SET status = ? WHERE id >= ?',
        );
    }

    public function testSupportsLessThanClause(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.status = ?1 WHERE u.id < ?2',
            'UPDATE cms_users SET status = ? WHERE id < ?',
        );
    }

    public function testSupportsLessThanOrEqualToClause(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.status = ?1 WHERE u.id <= ?2',
            'UPDATE cms_users SET status = ? WHERE id <= ?',
        );
    }

    public function testSupportsBetweenClause(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.status = ?1 WHERE u.id BETWEEN :from AND :to',
            'UPDATE cms_users SET status = ? WHERE id BETWEEN ? AND ?',
        );
    }

    public function testSingleValuedAssociationFieldInWhere(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CMS\CmsPhonenumber p SET p.phonenumber = 1234 WHERE p.user = ?1',
            'UPDATE cms_phonenumbers SET phonenumber = 1234 WHERE user_id = ?',
        );
    }

    public function testSingleValuedAssociationFieldInSetClause(): void
    {
        $this->assertSqlGeneration(
            'update Doctrine\Tests\Models\CMS\CmsComment c set c.article = null where c.article=?1',
            'UPDATE cms_comments SET article_id = NULL WHERE article_id = ?',
        );
    }

    #[Group('DDC-980')]
    public function testSubselectTableAliasReferencing(): void
    {
        $this->assertSqlGeneration(
            "UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.status = 'inactive' WHERE SIZE(u.groups) = 10",
            "UPDATE cms_users SET status = 'inactive' WHERE (SELECT COUNT(*) FROM cms_users_groups c0_ WHERE c0_.user_id = cms_users.id) = 10",
        );
    }

    public function testCustomTypeValueSqlCompletelyIgnoredInUpdateStatements(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\CustomType\CustomTypeParent p SET p.customInteger = 1 WHERE p.id = 1',
            'UPDATE customtype_parents SET customInteger = 1 WHERE id = 1',
        );
    }

    public function testUpdateWithSubselectAsNewValue(): void
    {
        $this->assertSqlGeneration(
            'UPDATE Doctrine\Tests\Models\Company\CompanyFixContract fc SET fc.fixPrice = (SELECT ce2.salary FROM Doctrine\Tests\Models\Company\CompanyEmployee ce2 WHERE ce2.id = 2) WHERE fc.id = 1',
            "UPDATE company_contracts SET fixPrice = (SELECT c0_.salary FROM company_employees c0_ INNER JOIN company_persons c1_ ON c0_.id = c1_.id LEFT JOIN company_managers c2_ ON c0_.id = c2_.id WHERE c1_.id = 2) WHERE (id = 1) AND discr IN ('fix')",
        );
    }
}
