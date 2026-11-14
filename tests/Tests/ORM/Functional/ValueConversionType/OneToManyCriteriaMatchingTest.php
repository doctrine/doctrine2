<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\ValueConversionType;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\Tests\Models\ValueConversionType\InversedOneToManyEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToOneEntity;
use Doctrine\Tests\OrmFunctionalTestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;

class OneToManyCriteriaMatchingTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('vct_onetomany');

        parent::setUp();
    }

    #[DataProvider('provideMatchingExpressions')]
    public function testCriteriaMatchingOnFieldInOneToManyTarget(Comparison $comparison): void
    {
        $entityWithCollection      = new InversedOneToManyEntity();
        $entityWithCollection->id1 = 'associated';

        $matching                   = new OwningManyToOneEntity();
        $matching->id2              = 'first';
        $matching->field            = 'match this'; // stored as 'zngpu guvf'
        $matching->associatedEntity = $entityWithCollection;

        $notMatching                   = new OwningManyToOneEntity();
        $notMatching->id2              = 'second';
        $notMatching->field            = 'this is no match';
        $notMatching->associatedEntity = $entityWithCollection;

        $this->_em->persist($entityWithCollection);
        $this->_em->persist($matching);
        $this->_em->persist($notMatching);
        $this->_em->flush();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();

        $entityWithCollection = $this->_em->find(InversedOneToManyEntity::class, 'associated');
        self::assertFalse($entityWithCollection->associatedEntities->isInitialized(), 'Pre-condition: lazy collection');

        $result = $entityWithCollection->associatedEntities->matching(Criteria::create()->where($comparison));

        $l = $this->getQueryLog();

        self::assertCount(1, $result);
        self::assertSame('first', $result[0]->id2);
    }

    public static function provideMatchingExpressions(): Generator
    {
        yield [Criteria::expr()->eq('field', 'match this')]; // should convert to 'zngpu guvf'
        yield [Criteria::expr()->in('field', ['match this'])]; // should convert to ['zngpu guvf']
    }
}
