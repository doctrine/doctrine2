<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use ArrayIterator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Collection\AbstractDelegateCollection;
use Doctrine\ORM\Collection\DelegateCollection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\Models\CustomCollection\Book;
use Doctrine\Tests\Models\CustomCollection\BookList;
use Doctrine\Tests\Models\CustomCollection\Library;
use Doctrine\Tests\Models\CustomCollection\LibraryList;
use Doctrine\Tests\Models\CustomCollection\Tag;
use Doctrine\Tests\Models\CustomCollection\TagCollection;
use LogicException;
use PHPUnit\Framework\TestCase;
use Traversable;

use function serialize;
use function unserialize;

final class CustomCollectionMappingTest extends TestCase
{
    private function loadLibraryMetadataFromXml(): ClassMetadata
    {
        $driver = new XmlDriver([__DIR__ . '/custom_collection_xml']);

        $class = new ClassMetadata(Library::class);
        $class->initializeReflection(new RuntimeReflectionService());
        $driver->loadMetadataForClass(Library::class, $class);

        return $class;
    }

    public function testXmlDriverLoadsCollectionClass(): void
    {
        $class = $this->loadLibraryMetadataFromXml();

        self::assertSame(BookList::class, $class->associationMappings['books']->collectionClass);
        self::assertSame(TagCollection::class, $class->associationMappings['tags']->collectionClass);
    }

    public function testCollectionClassIsInferredFromPropertyType(): void
    {
        $class = new ClassMetadata(Library::class);
        $class->initializeReflection(new RuntimeReflectionService());

        $class->mapManyToMany(['fieldName' => 'tags', 'targetEntity' => Tag::class]);

        self::assertSame(TagCollection::class, $class->associationMappings['tags']->collectionClass);
    }

    public function testCollectionClassSurvivesMetadataSerialization(): void
    {
        $class = $this->loadLibraryMetadataFromXml();

        $resurrected = unserialize(serialize($class));

        self::assertSame(BookList::class, $resurrected->associationMappings['books']->collectionClass);
        self::assertSame(TagCollection::class, $resurrected->associationMappings['tags']->collectionClass);
    }

    public function testCollectionClassMustExist(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the class does not exist');

        $this->mapBooksWithCollectionClass('Doctrine\Tests\ORM\Mapping\ThereIsNoSuchClass');
    }

    public function testCollectionClassMustImplementDelegateCollection(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the class does not implement the Doctrine\ORM\Collection\DelegateCollection interface');

        $this->mapBooksWithCollectionClass(ArrayCollection::class);
    }

    public function testCollectionClassMustBeInstantiable(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the class is not instantiable');

        $this->mapBooksWithCollectionClass(AbstractDelegateCollection::class);
    }

    public function testInferredInterfaceAsksForAConcreteCollectionClass(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the class is not instantiable');

        $class = new ClassMetadata(InterfaceTypedHolder::class);
        $class->initializeReflection(new RuntimeReflectionService());

        $class->mapManyToMany(['fieldName' => 'tags', 'targetEntity' => Tag::class]);
    }

    public function testCollectionClassConstructorMayRequireArguments(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mapBooksWithCollectionClass(RequiresConstructorArgumentsCollection::class);
    }

    public function testCollectionClassConstructorMayBePrivate(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mapBooksWithCollectionClass(PrivateConstructorCollection::class);
    }

    public function testCollectionPropertyMayBeDeclaredPrivateByAParentClass(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mapBooksWithCollectionClass(LibraryList::class);
    }

    public function testCollectionClassMustDeclareACollectionProperty(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the class must declare a $collection property typed as Doctrine\Common\Collections\Collection');

        $this->mapBooksWithCollectionClass(ArrayBackedCollection::class);
    }

    public function testCollectionClassInnerPropertyMustAcceptPersistentCollection(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the $collection property must be non-static and typed as Doctrine\Common\Collections\Collection');

        $this->mapBooksWithCollectionClass(ArrayCollectionPropertyCollection::class);
    }

    public function testCollectionClassMayDeclareOtherCollectionProperties(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mapBooksWithCollectionClass(TwoCollectionPropertiesCollection::class);
    }

    public function testCollectionClassInnerPropertyMustNotBeReadonly(): void
    {
        try {
            $this->mapBooksWithCollectionClass(ReadonlyPropertyCollection::class);
            self::fail('A MappingException was expected.');
        } catch (MappingException $e) {
            self::assertStringContainsString('must not be readonly', $e->getMessage());
            self::assertInstanceOf(LogicException::class, $e->getPrevious());
        }
    }

    public function testCollectionClassIsOnlyAllowedOnToManyAssociations(): void
    {
        $class = new ClassMetadata(Book::class);
        $class->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('only to-many associations support a custom collection class');

        $class->mapManyToOne(
            [
                'fieldName' => 'library',
                'targetEntity' => Library::class,
                'collectionClass' => TagCollection::class,
            ],
        );
    }

    private function mapBooksWithCollectionClass(string $collectionClass): void
    {
        $class = new ClassMetadata(Library::class);
        $class->initializeReflection(new RuntimeReflectionService());

        $class->mapOneToMany(
            [
                'fieldName' => 'books',
                'targetEntity' => Book::class,
                'mappedBy' => 'library',
                'collectionClass' => $collectionClass,
            ],
        );
    }
}

/** @template-extends AbstractDelegateCollection<array-key, Tag> */
final class RequiresConstructorArgumentsCollection extends AbstractDelegateCollection
{
    public function __construct(private readonly string $name)
    {
        parent::__construct();
    }
}

/** @template-extends AbstractDelegateCollection<array-key, Tag> */
final class PrivateConstructorCollection extends AbstractDelegateCollection
{
    private function __construct()
    {
        parent::__construct();
    }

    public static function empty(): self
    {
        return new self();
    }
}

/** @template-implements DelegateCollection<array-key, Tag> */
final class ArrayBackedCollection implements DelegateCollection
{
    /** @var list<Tag> */
    private array $items = [];

    public function getDelegatee(): Collection
    {
        return new ArrayCollection($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}

/** @template-extends AbstractDelegateCollection<array-key, Tag> */
final class TwoCollectionPropertiesCollection extends AbstractDelegateCollection
{
    /** @phpstan-var Collection<array-key, Tag> */
    private Collection $archived;

    public function __construct()
    {
        parent::__construct();

        $this->archived = new ArrayCollection();
    }
}

/** @template-implements DelegateCollection<array-key, Tag> */
final class ReadonlyPropertyCollection implements DelegateCollection
{
    /** @phpstan-param Collection<array-key, Tag> $collection */
    public function __construct(private readonly Collection $collection = new ArrayCollection())
    {
    }

    public function getDelegatee(): Collection
    {
        return $this->collection;
    }

    public function getIterator(): Traversable
    {
        return $this->collection->getIterator();
    }
}

/** @template-implements DelegateCollection<array-key, Tag> */
final class ArrayCollectionPropertyCollection implements DelegateCollection
{
    /** @phpstan-param ArrayCollection<array-key, Tag> $collection */
    public function __construct(private ArrayCollection $collection = new ArrayCollection())
    {
    }

    public function getDelegatee(): Collection
    {
        return $this->collection;
    }

    public function getIterator(): Traversable
    {
        return $this->collection->getIterator();
    }
}

/** @template-extends DelegateCollection<array-key, Tag> */
interface TagListContract extends DelegateCollection
{
}

class InterfaceTypedHolder
{
    public TagListContract $tags;
}
