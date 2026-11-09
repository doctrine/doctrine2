<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping\PropertyAccessors;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Collection\AbstractDelegateCollection;
use Doctrine\ORM\Mapping\PropertyAccessors\DelegateCollectionPropertyAccessor;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Tests\Models\CustomCollection\Shelf;
use Doctrine\Tests\Models\CustomCollection\Tag;
use Doctrine\Tests\OrmTestCase;

class DelegateCollectionPropertyAccessorTest extends OrmTestCase
{
    private function createAccessor(): DelegateCollectionPropertyAccessor
    {
        return new DelegateCollectionPropertyAccessor(
            PropertyAccessorFactory::createPropertyAccessor(TaggedHolder::class, 'tags'),
            NamedTagCollection::class,
        );
    }

    public function testGetValueReturnsTheUnderlyingCollection(): void
    {
        $holder       = new TaggedHolder();
        $inner        = new ArrayCollection([new Tag('php')]);
        $holder->tags = new NamedTagCollection('main', $inner);

        self::assertSame($inner, $this->createAccessor()->getValue($holder));
    }

    public function testSetValueCreatesAnInstanceWithoutCallingItsConstructor(): void
    {
        $holder     = new TaggedHolder();
        $collection = new ArrayCollection();

        $this->createAccessor()->setValue($holder, $collection);

        self::assertInstanceOf(NamedTagCollection::class, $holder->tags);
        self::assertSame($collection, $holder->tags->getDoctrineCollection());
    }

    public function testSetValueReusesTheCurrentInstance(): void
    {
        $holder       = new TaggedHolder();
        $current      = new NamedTagCollection('main');
        $holder->tags = $current;
        $collection   = new ArrayCollection();

        $this->createAccessor()->setValue($holder, $collection);

        self::assertSame($current, $holder->tags);
        self::assertSame($collection, $current->getDoctrineCollection());
    }

    public function testSetValueCreatesAnInstanceWhenTheCurrentOneBelongsToAnotherEntity(): void
    {
        $em    = $this->getTestEntityManager();
        $owned = new PersistentCollection($em, $em->getClassMetadata(Tag::class), new ArrayCollection());
        $owned->setOwner(new Shelf(), $em->getClassMetadata(Shelf::class)->associationMappings['tags']);

        $holder       = new TaggedHolder();
        $shared       = new NamedTagCollection('main', $owned);
        $holder->tags = $shared;
        $collection   = new ArrayCollection();

        $this->createAccessor()->setValue($holder, $collection);

        self::assertNotSame($shared, $holder->tags);
        self::assertSame($owned, $shared->getDoctrineCollection(), 'The other entity keeps its collection.');
        self::assertSame($collection, $holder->tags->getDoctrineCollection());
    }
}

class TaggedHolder
{
    public NamedTagCollection $tags;
}

/** @template-extends AbstractDelegateCollection<array-key, Tag> */
final class NamedTagCollection extends AbstractDelegateCollection
{
    /** @param Collection<array-key, Tag> $collection */
    public function __construct(public readonly string $name, Collection $collection = new ArrayCollection())
    {
        parent::__construct($collection);
    }
}
