<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\ValueConversionType;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\Tests\Models\ValueConversionType\InversedManyToManyEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToManyEntity;
use Doctrine\Tests\OrmFunctionalTestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;

use function defined;

class ManyToManyCriteriaMatchingTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('vct_manytomany');

        parent::setUp();
    }

    #[DataProvider('provideMatchingExpressions')]
    public function testCriteriaMatchingOnFieldInManyToManyTarget(Comparison $comparison): void
    {
        $associated      = new InversedManyToManyEntity();
        $associated->id1 = 'associated';

        $matching        = new OwningManyToManyEntity();
        $matching->id2   = 'first';
        $matching->field = 'match this'; // stored as 'zngpu guvf'
        $matching->associatedEntities->add($associated);

        $nonMatching        = new OwningManyToManyEntity();
        $nonMatching->id2   = 'second';
        $nonMatching->field = 'this is no match';
        $nonMatching->associatedEntities->add($associated);

        $this->_em->persist($associated);
        $this->_em->persist($matching);
        $this->_em->persist($nonMatching);
        $this->_em->flush();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();

        $associated = $this->_em->find(InversedManyToManyEntity::class, 'associated');
        self::assertFalse($associated->associatedEntities->isInitialized(), 'Pre-condition: lazy collection');

        $result = $associated->associatedEntities->matching((defined(Criteria::class . '::ASC') ? Criteria::create(true) : Criteria::create())->where($comparison));

        $l = $this->getQueryLog();

        self::assertCount(1, $result);
        self::assertSame('first', $result[0]->id2);
    }

    public static function provideMatchingExpressions(): Generator
    {
        yield [Criteria::expr()->eq('field', 'match this')]; // should convert to 'zngpu guvf'
        yield [Criteria::expr()->in('field', ['match this'])]; // should convert to ['zngpu guvf']
        yield [Criteria::expr()->in('field', ['first' => 'match this', 9 => 'missing', 'duplicate' => 'match this'])];
        yield [Criteria::expr()->notIn('field', ['this is no match', 'missing'])];
    }
}
