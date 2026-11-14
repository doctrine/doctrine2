<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\LazyCriteriaCollection;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(LazyCriteriaCollection::class)]
class LazyCriteriaCollectionTest extends TestCase
{
    private EntityPersister&MockObject $persister;
    private Criteria $criteria;
    private LazyCriteriaCollection $lazyCriteriaCollection;

    protected function setUp(): void
    {
        $this->persister              = $this->createMock(EntityPersister::class);
        $this->criteria               = Criteria::create();
        $this->lazyCriteriaCollection = new LazyCriteriaCollection($this->persister, $this->criteria);
    }

    public function testCountIsCached(): void
    {
        $this->persister->expects(self::once())->method('count')->with($this->criteria)->willReturn(10);

        self::assertSame(10, $this->lazyCriteriaCollection->count());
        self::assertSame(10, $this->lazyCriteriaCollection->count());
        self::assertSame(10, $this->lazyCriteriaCollection->count());
    }

    public function testCountIsCachedEvenWithZeroResult(): void
    {
        $this->persister->expects(self::once())->method('count')->with($this->criteria)->willReturn(0);

        self::assertSame(0, $this->lazyCriteriaCollection->count());
        self::assertSame(0, $this->lazyCriteriaCollection->count());
        self::assertSame(0, $this->lazyCriteriaCollection->count());
    }

    public function testCountUsesWrappedCollectionWhenInitialized(): void
    {
        $this
            ->persister
            ->expects(self::once())
            ->method('loadCriteria')
            ->with($this->criteria)
            ->willReturn(['foo', 'bar', 'baz']);

        // should never call the persister's count
        $this->persister->expects(self::never())->method('count');

        self::assertSame(['foo', 'bar', 'baz'], $this->lazyCriteriaCollection->toArray());

        self::assertSame(3, $this->lazyCriteriaCollection->count());
    }

    public function testMatchingUsesThePersisterOnlyOnce(): void
    {
        $foo = new LazyCriteriaCollectionTestObject();
        $bar = new LazyCriteriaCollectionTestObject();
        $baz = new LazyCriteriaCollectionTestObject();

        $foo->val = 'foo';
        $bar->val = 'bar';
        $baz->val = 'baz';

        $this
            ->persister
            ->expects(self::once())
            ->method('loadCriteria')
            ->with($this->criteria)
            ->willReturn([$foo, $bar, $baz]);

        $criteria = Criteria::create();

        $criteria->andWhere($criteria->expr()->eq('val', 'foo'));

        $filtered = $this->lazyCriteriaCollection->matching($criteria);

        self::assertInstanceOf(Collection::class, $filtered);
        self::assertEquals([$foo], $filtered->toArray());

        self::assertEquals([$foo], $this->lazyCriteriaCollection->matching($criteria)->toArray());
    }

    public function testIsEmptyUsesCountWhenNotInitialized(): void
    {
        $this->persister->expects(self::once())->method('count')->with($this->criteria)->willReturn(0);

        self::assertTrue($this->lazyCriteriaCollection->isEmpty());
    }

    public function testIsEmptyIsFalseIfCountIsNotZero(): void
    {
        $this->persister->expects(self::once())->method('count')->with($this->criteria)->willReturn(1);

        self::assertFalse($this->lazyCriteriaCollection->isEmpty());
    }

    public function testIsEmptyUsesWrappedCollectionWhenInitialized(): void
    {
        $this
            ->persister
            ->expects(self::once())
            ->method('loadCriteria')
            ->with($this->criteria)
            ->willReturn(['foo', 'bar', 'baz']);

        // should never call the persister's count
        $this->persister->expects(self::never())->method('count');

        self::assertSame(['foo', 'bar', 'baz'], $this->lazyCriteriaCollection->toArray());

        self::assertFalse($this->lazyCriteriaCollection->isEmpty());
    }
}

class LazyCriteriaCollectionTestObject
{
    /** @var mixed */
    public $val;
}
