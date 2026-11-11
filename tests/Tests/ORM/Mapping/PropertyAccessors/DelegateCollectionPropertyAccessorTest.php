<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping\PropertyAccessors;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Collection\AbstractDelegateCollection;
use Doctrine\ORM\Collection\DefaultDelegateCollectionFactory;
use Doctrine\ORM\Collection\DelegateCollection;
use Doctrine\ORM\Collection\DelegateCollectionFactory;
use Doctrine\ORM\Mapping\PropertyAccessors\DelegateCollectionPropertyAccessor;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Tests\Models\CustomCollection\Shelf;
use Doctrine\Tests\Models\CustomCollection\Tag;
use Doctrine\Tests\Models\CustomCollection\TagCollection;
use Doctrine\Tests\OrmTestCase;

use function sprintf;

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
        self::assertSame($collection, $holder->tags->getDelegatee());
    }

    public function testSetValueReusesTheCurrentInstance(): void
    {
        $holder       = new TaggedHolder();
        $current      = new NamedTagCollection('main');
        $holder->tags = $current;
        $collection   = new ArrayCollection();

        $this->createAccessor()->setValue($holder, $collection);

        self::assertSame($current, $holder->tags);
        self::assertSame($collection, $current->getDelegatee());
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
        self::assertSame($owned, $shared->getDelegatee(), 'The other entity keeps its collection.');
        self::assertSame($collection, $holder->tags->getDelegatee());
    }

    public function testSetValueAssignsACustomCollection(): void
    {
        $holder       = new TaggedHolder();
        $holder->tags = new NamedTagCollection('old');
        $new          = new NamedTagCollection('new');

        $this->createAccessor()->setValue($holder, $new);

        self::assertSame($new, $holder->tags);
    }

    public function testSetValueRejectsAnotherCollectionClass(): void
    {
        $holder       = new CollectionTypedHolder();
        $holder->tags = new TagCollection();
        $accessor     = new DelegateCollectionPropertyAccessor(
            PropertyAccessorFactory::createPropertyAccessor(CollectionTypedHolder::class, 'tags'),
            NamedTagCollection::class,
        );

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Expected value of type "%s" for association field "%s#$tags", got "%s" instead.',
            NamedTagCollection::class,
            CollectionTypedHolder::class,
            TagCollection::class,
        ));

        $accessor->setValue($holder, new ArrayCollection());
    }

    public function testSetValueCreatesTheInstanceThroughTheFactory(): void
    {
        $holder   = new TaggedHolder();
        $created  = new NamedTagCollection('from factory');
        $accessor = $this->createAccessor();
        $accessor->setCollectionFactory(new class ($created) implements DelegateCollectionFactory {
            public function __construct(private readonly DelegateCollection $created)
            {
            }

            public function create(string $collectionClass): DelegateCollection
            {
                return $this->created;
            }
        });

        $accessor->setValue($holder, new ArrayCollection());

        self::assertSame($created, $holder->tags);
    }

    public function testSetValueRejectsAFactoryCreatingAnotherCollectionClass(): void
    {
        $accessor = $this->createAccessor();
        $accessor->setCollectionFactory(new class implements DelegateCollectionFactory {
            public function create(string $collectionClass): DelegateCollection
            {
                return new TagCollection();
            }
        });

        $this->expectException(ORMInvalidArgumentException::class);

        $accessor->setValue(new TaggedHolder(), new ArrayCollection());
    }

    public function testDefaultFactoryDoesNotCallTheConstructor(): void
    {
        self::assertInstanceOf(
            NamedTagCollection::class,
            (new DefaultDelegateCollectionFactory())->create(NamedTagCollection::class),
        );
    }
}

class TaggedHolder
{
    public NamedTagCollection $tags;
}

class CollectionTypedHolder
{
    /** @var Collection<array-key, Tag> */
    public Collection $tags;
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
