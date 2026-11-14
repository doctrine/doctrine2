<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\SwitchContextWithFilterAndIndexedRelation;

use Doctrine\Tests\OrmFunctionalTestCase;

final class ChangeFiltersTest extends OrmFunctionalTestCase
{
    private const string COMPANY_A = 'A';
    private const string CAT_BAR   = 'bar';
    private const string CAT_FOO   = 'foo';

    public function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            Company::class,
            Category::class,
        ]);
    }

    private function prepareData(): void
    {
        $cat1     = new Category('cat1', self::CAT_FOO);
        $cat2     = new Category('cat2', self::CAT_BAR);
        $companyA = new Company(self::COMPANY_A, [$cat1, $cat2]);

        $this->_em->persist($cat1);
        $this->_em->persist($cat2);
        $this->_em->persist($companyA);
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testIndexAliasUpdatedWithUpdatedFilter(): void
    {
        $this->prepareData();

        $company = $this->_em->getRepository(Company::class)->findOneBy([]);

        self::assertCount(2, $company->categories);
        self::assertEquals([self::CAT_FOO, self::CAT_BAR], $company->categories->map(static function (Category $c): string {
            return $c->type;
        })->getValues());

        $this->_em->clear();
        $this->_em->getConfiguration()->addFilter(CategoryTypeSQLFilter::class, CategoryTypeSQLFilter::class);
        $this->_em->getFilters()->enable(CategoryTypeSQLFilter::class)->setParameter('type', self::CAT_FOO);

        $company = $this->_em->getRepository(Company::class)->findOneBy([]);

        self::assertCount(1, $company->categories);
        self::assertEquals([self::CAT_FOO], $company->categories->map(static function (Category $c): string {
            return $c->type;
        })->getValues());
    }
}
