<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Collection;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Collection\AbstractDelegateCollection;
use Doctrine\ORM\Mapping\PropertyAccessors\DelegateCollectionPropertyAccessor;
use PHPUnit\Framework\TestCase;

use function strtoupper;

class AbstractDelegateCollectionTest extends TestCase
{
    public function testDefaultsToAnEmptyArrayCollection(): void
    {
        $collection = new ConcreteDelegateCollection();

        self::assertInstanceOf(ArrayCollection::class, $collection->getDoctrineCollection());
        self::assertTrue($collection->isEmpty());
    }

    public function testUsesTheGivenCollection(): void
    {
        $inner      = new ArrayCollection(['a', 'b']);
        $collection = new ConcreteDelegateCollection($inner);

        self::assertSame($inner, $collection->getDoctrineCollection());
        self::assertSame(['a', 'b'], $collection->toArray());
    }

    public function testForwardsTheCollectionApiToTheUnderlyingCollection(): void
    {
        $inner      = new ArrayCollection();
        $collection = new ConcreteDelegateCollection($inner);

        $collection->add('a');
        $collection->set('key', 'b');
        $collection[] = 'c';

        self::assertSame(['a', 'key' => 'b', 'c'], $inner->toArray());
        self::assertTrue($collection->contains('a'));
        self::assertCount(3, $collection);

        $collection->removeElement('a');
        $collection->remove('key');
        unset($collection[1]);

        self::assertTrue($inner->isEmpty());
    }

    public function testInnerCollectionCanBeInjectedThroughReflection(): void
    {
        $collection  = new ConcreteDelegateCollection(new ArrayCollection(['a']));
        $replacement = new ArrayCollection(['b']);

        DelegateCollectionPropertyAccessor::resolveInnerProperty(ConcreteDelegateCollection::class)->setValue($collection, $replacement);

        self::assertSame($replacement, $collection->getDoctrineCollection());
        self::assertSame(['b'], $collection->toArray());
    }

    public function testCustomMethodsOperateOnTheUnderlyingCollection(): void
    {
        $collection = new ConcreteDelegateCollection(new ArrayCollection(['a', 'b']));

        self::assertSame(['A', 'B'], $collection->uppercased());
    }

    public function testMatchingIsForwarded(): void
    {
        $collection = new ConcreteDelegateCollection(new ArrayCollection([['v' => 1], ['v' => 2]]));

        $matched = $collection->matching(Criteria::create()->where(Criteria::expr()->eq('v', 2)));

        self::assertCount(1, $matched);
    }

    public function testCloningClonesTheUnderlyingCollection(): void
    {
        $inner      = new ArrayCollection(['a']);
        $collection = new ConcreteDelegateCollection($inner);

        $copy = clone $collection;

        self::assertNotSame($inner, $copy->getDoctrineCollection());
        self::assertSame(['a'], $copy->toArray());

        $copy->add('b');

        self::assertSame(['a'], $inner->toArray());
    }
}

/** @template-extends AbstractDelegateCollection<array-key, mixed> */
final class ConcreteDelegateCollection extends AbstractDelegateCollection
{
    /** @return list<string> */
    public function uppercased(): array
    {
        return $this->map(static fn (string $value): string => strtoupper($value))->getValues();
    }
}
