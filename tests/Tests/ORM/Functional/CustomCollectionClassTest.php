<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Collection\DefaultDelegateCollectionFactory;
use Doctrine\ORM\Collection\DelegateCollection;
use Doctrine\ORM\Collection\DelegateCollectionFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Tools\DebugUnitOfWorkListener;
use Doctrine\Tests\Models\CustomCollection\Article;
use Doctrine\Tests\Models\CustomCollection\Book;
use Doctrine\Tests\Models\CustomCollection\BookList;
use Doctrine\Tests\Models\CustomCollection\Library;
use Doctrine\Tests\Models\CustomCollection\LibraryList;
use Doctrine\Tests\Models\CustomCollection\Shelf;
use Doctrine\Tests\Models\CustomCollection\Tag;
use Doctrine\Tests\Models\CustomCollection\TagCollection;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;
use function current;
use function file_get_contents;
use function serialize;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function unserialize;

class CustomCollectionClassTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(Library::class, Book::class, Tag::class, Shelf::class, Article::class);
    }

    private function createLibrary(): Library
    {
        $library = new Library('Central');
        $library->addBook(new Book('Zoology'));
        $library->addBook(new Book('Astronomy'));
        $library->addTag(new Tag('php'));
        $library->addTag(new Tag('js'));

        $this->_em->persist($library);
        $this->_em->flush();

        return $library;
    }

    public function testPersistNewEntityKeepsUserCollectionInstances(): void
    {
        $library = new Library('Central');
        $books   = $library->getBooks();
        $tags    = $library->getTags();

        $library->addBook(new Book('Zoology'));
        $library->addTag(new Tag('php'));

        $this->_em->persist($library);
        $this->_em->flush();

        self::assertSame($books, $library->getBooks(), 'The user-constructed BookList instance must survive the flush.');
        self::assertSame($tags, $library->getTags(), 'The user-constructed TagCollection instance must survive the flush.');
        self::assertInstanceOf(PersistentCollection::class, $books->getDelegatee());
        self::assertInstanceOf(PersistentCollection::class, $tags->getDelegatee());

        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        self::assertSame(['Zoology'], $loaded->getBooks()->titles());
        self::assertSame(['php'], $loaded->getTags()->names());
    }

    public function testLoadedEntityExposesCustomCollectionsLazily(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $books = $loaded->getBooks();
        $inner = $books->getDelegatee();

        self::assertInstanceOf(BookList::class, $books);
        self::assertInstanceOf(PersistentCollection::class, $inner);
        assert($inner instanceof PersistentCollection);
        self::assertFalse($inner->isInitialized(), 'Accessing the custom collection must not initialize it.');

        self::assertSame(['Astronomy', 'Zoology'], $books->titles());
        self::assertTrue($inner->isInitialized());
    }

    public function testReferenceExposesCustomCollectionsOnceInitialized(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $reference = $this->_em->getReference(Library::class, $library->id);
        assert($reference instanceof Library);

        $books = $reference->getBooks();

        self::assertInstanceOf(BookList::class, $books);
        self::assertInstanceOf(PersistentCollection::class, $books->getDelegatee());
        self::assertSame(['Astronomy', 'Zoology'], $books->titles());
        self::assertSame($books, $reference->getBooks(), 'The custom collection instance must stay the same after initialization.');
    }

    public function testIsInitializedReflectsTheUnderlyingCollection(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $tags = $loaded->getTags();

        self::assertFalse($tags->isInitialized(), 'isInitialized() must report the lazy state of the underlying collection.');

        $tags->names();

        self::assertTrue($tags->isInitialized());
        self::assertTrue((new TagCollection())->isInitialized(), 'A collection backed by an ArrayCollection is always initialized.');
    }

    public function testExtraLazyCountDoesNotInitialize(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $books = $loaded->getBooks();
        $inner = $books->getDelegatee();
        assert($inner instanceof PersistentCollection);

        self::assertCount(2, $books);
        self::assertFalse($inner->isInitialized(), 'count() on an EXTRA_LAZY association must not initialize the collection.');
    }

    public function testManyToManyMutationThroughCustomMethodIsFlushed(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $loaded->getTags()->removeByName('php');
        $this->_em->flush();
        $this->_em->clear();

        $reloaded = $this->_em->find(Library::class, $library->id);
        assert($reloaded instanceof Library);

        self::assertSame(['js'], $reloaded->getTags()->names(), 'Join rows removed through a custom method must be deleted on flush.');
    }

    public function testManyToManyAdditionThroughInheritedApiIsFlushed(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $loaded->addTag(new Tag('sql'));
        $this->_em->flush();
        $this->_em->clear();

        $reloaded = $this->_em->find(Library::class, $library->id);
        assert($reloaded instanceof Library);

        self::assertEqualsCanonicalizing(['js', 'php', 'sql'], $reloaded->getTags()->names());
        self::assertSame('sql', $reloaded->getTags()->get('sql')->name, 'indexBy must be honored.');
    }

    public function testCustomCollectionWrappingAnotherEntityCollectionDoesNotShareIt(): void
    {
        $source = $this->createLibrary();

        $copy = new Library('Copy');
        $copy->replaceTags(new TagCollection($source->getTags()));
        $this->_em->persist($copy);
        $this->_em->flush();

        $copy->addTag(new Tag('rust'));
        $this->_em->flush();
        $this->_em->clear();

        $loadedSource = $this->_em->find(Library::class, $source->id);
        assert($loadedSource instanceof Library);
        $loadedCopy = $this->_em->find(Library::class, $copy->id);
        assert($loadedCopy instanceof Library);

        self::assertEqualsCanonicalizing(['php', 'js'], $loadedSource->getTags()->names());
        self::assertEqualsCanonicalizing(['php', 'js', 'rust'], $loadedCopy->getTags()->names());
    }

    public function testDelegateCollectionFactoryCreatesTheCollectionsOfLoadedEntities(): void
    {
        $library = $this->createLibrary();

        $factory = new class implements DelegateCollectionFactory {
            /** @var list<class-string> */
            public array $created = [];

            public function create(string $collectionClass): DelegateCollection
            {
                $this->created[] = $collectionClass;

                return (new DefaultDelegateCollectionFactory())->create($collectionClass);
            }
        };
        $config  = $this->_em->getConfiguration();
        $config->setDelegateCollectionFactory($factory);
        $em = new EntityManager($this->_em->getConnection(), $config, $this->_em->getEventManager());

        $loaded = $em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        self::assertEqualsCanonicalizing([BookList::class, TagCollection::class], $factory->created);
        self::assertSame(['Astronomy', 'Zoology'], $loaded->getBooks()->titles());
    }

    public function testCustomCollectionDeclaredInAMappedSuperclass(): void
    {
        $article = new Article();
        $article->getTags()->add(new Tag('php'));
        $this->_em->persist($article);
        $this->_em->flush();
        $this->_em->clear();

        $loaded = $this->_em->find(Article::class, $article->id);
        assert($loaded instanceof Article);

        self::assertSame(['php'], $loaded->getTags()->names());

        $loaded->getTags()->add(new Tag('js'));
        $this->_em->flush();
        $this->_em->clear();

        $reloaded = $this->_em->find(Article::class, $article->id);
        assert($reloaded instanceof Article);

        self::assertEqualsCanonicalizing(['php', 'js'], $reloaded->getTags()->names());
    }

    public function testInverseSideManyToManyUsesCustomCollection(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $tag = $this->_em->createQuery('SELECT t FROM ' . Tag::class . ' t JOIN t.libraries l WHERE l.id = :id AND t.name = :name')
            ->setParameter('id', $library->id)
            ->setParameter('name', 'php')
            ->getSingleResult();
        assert($tag instanceof Tag);

        self::assertInstanceOf(LibraryList::class, $tag->libraries);
        $inner = $tag->libraries->getDelegatee();
        assert($inner instanceof PersistentCollection);
        self::assertFalse($inner->isInitialized(), 'The inverse side must be loaded lazily.');

        self::assertSame(['Central'], $tag->libraries->names());
        self::assertTrue($inner->isInitialized());

        $this->_em->clear();

        $joined = $this->_em->createQuery('SELECT t, l FROM ' . Tag::class . ' t JOIN t.libraries l WHERE l.id = :id AND t.name = :name')
            ->setParameter('id', $library->id)
            ->setParameter('name', 'php')
            ->getSingleResult();
        assert($joined instanceof Tag);

        self::assertInstanceOf(LibraryList::class, $joined->libraries);
        $joinedInner = $joined->libraries->getDelegatee();
        assert($joinedInner instanceof PersistentCollection);
        self::assertTrue($joinedInner->isInitialized(), 'A fetch-joined inverse side must be hydrated as initialized.');
        self::assertSame(['Central'], $joined->libraries->names());
    }

    public function testOrphanRemovalThroughCustomMethod(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $removedId = null;

        foreach ($loaded->getBooks() as $book) {
            if ($book->title === 'Zoology') {
                $removedId = $book->id;
                $loaded->removeBook($book);
            }
        }

        self::assertNotNull($removedId);

        $this->_em->flush();
        $this->_em->clear();

        self::assertSame(['Astronomy'], $this->_em->find(Library::class, $library->id)->getBooks()->titles());
        self::assertNull($this->_em->find(Book::class, $removedId), 'orphanRemoval must delete the removed book.');
    }

    public function testReplacingCustomCollectionRemovesOrphans(): void
    {
        $library = $this->createLibrary();
        $bookIds = [];

        foreach ($library->getBooks() as $book) {
            $bookIds[] = $book->id;
        }

        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $book          = new Book('Physics');
        $book->library = $loaded;
        $loaded->replaceBooks(new BookList());
        $loaded->getBooks()->addBook($book);
        $this->_em->flush();
        $this->_em->clear();

        // A DQL query, as orphans are deleted in bulk without evicting their second-level cache entries
        $remaining = $this->_em->createQuery('SELECT COUNT(b) FROM ' . Book::class . ' b WHERE b.id IN (:ids)')
            ->setParameter('ids', $bookIds)
            ->getSingleScalarResult();

        self::assertSame(0, (int) $remaining, 'Replacing the custom collection must delete the orphaned books.');
        self::assertSame(['Physics'], $this->_em->find(Library::class, $library->id)->getBooks()->titles());
    }

    public function testRemoveCascadesThroughCustomCollection(): void
    {
        $library = $this->createLibrary();
        $bookIds = [];

        foreach ($library->getBooks() as $book) {
            $bookIds[] = $book->id;
        }

        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $this->_em->remove($loaded);
        $this->_em->flush();
        $this->_em->clear();

        foreach ($bookIds as $bookId) {
            self::assertNull($this->_em->find(Book::class, $bookId), 'Removing the library must cascade to its books.');
        }
    }

    public function testRefreshCascadesThroughCustomCollection(): void
    {
        $shelf = new Shelf();
        $shelf->addBook(new Book('Zoology'));
        $shelf->addBook(new Book('Astronomy'));
        $this->_em->persist($shelf);
        $this->_em->flush();

        foreach ($shelf->books as $book) {
            $book->title = 'Changed';
        }

        $this->_em->refresh($shelf);

        self::assertSame(['Astronomy', 'Zoology'], $shelf->books->titles(), 'Refreshing the shelf must cascade to its books.');
    }

    public function testDetachCascadesThroughCustomCollection(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $books = [...$loaded->getBooks()];
        $this->_em->detach($loaded);

        foreach ($books as $book) {
            self::assertFalse($this->_em->contains($book), 'Detaching the library must cascade to its books.');
        }
    }

    public function testRefreshDiscardsPendingCollectionChanges(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $loaded->addBook(new Book('Draft'));
        $this->_em->refresh($loaded);

        self::assertInstanceOf(BookList::class, $loaded->getBooks());
        self::assertSame(['Astronomy', 'Zoology'], $loaded->getBooks()->titles(), 'refresh() must discard the pending addition.');
    }

    public function testRefreshKeepsCustomCollectionInstance(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $books = $loaded->getBooks();
        $this->_em->refresh($loaded);

        self::assertSame($books, $loaded->getBooks(), 'refresh() must keep the custom collection instance.');

        $book          = new Book('Physics');
        $book->library = $loaded;
        $books->addBook($book);
        $this->_em->flush();
        $this->_em->clear();

        $reloaded = $this->_em->find(Library::class, $library->id);
        assert($reloaded instanceof Library);

        self::assertSame(['Astronomy', 'Physics', 'Zoology'], $reloaded->getBooks()->titles(), 'A book added through a reference taken before refresh() must be persisted.');
    }

    public function testCloningEntityYieldsIndependentCollections(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $copy = clone $loaded;

        self::assertNotSame($loaded->getBooks(), $copy->getBooks());
        self::assertNotSame($loaded->getTags(), $copy->getTags());

        $this->_em->persist($copy);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNotEquals($library->id, $copy->id);

        $reloadedCopy = $this->_em->find(Library::class, $copy->id);
        assert($reloadedCopy instanceof Library);

        self::assertSame(['Astronomy', 'Zoology'], $reloadedCopy->getBooks()->titles());
        self::assertEqualsCanonicalizing(['js', 'php'], $reloadedCopy->getTags()->names(), 'The copy must reference the shared tags through its own join rows.');

        $reloadedOriginal = $this->_em->find(Library::class, $library->id);
        assert($reloadedOriginal instanceof Library);

        self::assertSame(['Astronomy', 'Zoology'], $reloadedOriginal->getBooks()->titles());
        self::assertEqualsCanonicalizing(['js', 'php'], $reloadedOriginal->getTags()->names());
    }

    public function testEntitySerializationRoundTrip(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);
        $loaded->getBooks()->titles(); // initialize

        $unserialized = unserialize(serialize($loaded));
        assert($unserialized instanceof Library);

        self::assertInstanceOf(BookList::class, $unserialized->getBooks());
        self::assertSame(['Astronomy', 'Zoology'], $unserialized->getBooks()->titles());
    }

    public function testFetchJoinHydratesCustomCollections(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->createQuery(
            'SELECT l, b FROM ' . Library::class . ' l JOIN l.books b WHERE l.id = :id',
        )->setParameter('id', $library->id)->getSingleResult();
        assert($loaded instanceof Library);

        $books = $loaded->getBooks();
        $inner = $books->getDelegatee();
        assert($inner instanceof PersistentCollection);

        self::assertInstanceOf(BookList::class, $books);
        self::assertTrue($inner->isInitialized(), 'Fetch-joined collections must be hydrated as initialized.');

        $this->getQueryLog()->reset()->enable();
        self::assertSame(['Astronomy', 'Zoology'], $books->titles());
        $this->assertQueryCount(0, 'Reading a fetch-joined custom collection must not trigger queries.');
    }

    public function testEagerFetchBatchLoadsCustomCollections(): void
    {
        $first = new Shelf();
        $first->addBook(new Book('Zoology'));
        $first->addBook(new Book('Astronomy'));
        $second = new Shelf();
        $second->addBook(new Book('Physics'));
        $this->_em->persist($first);
        $this->_em->persist($second);
        $this->_em->flush();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $shelves = $this->_em->createQuery('SELECT s FROM ' . Shelf::class . ' s WHERE s.id IN (:ids) ORDER BY s.id')
            ->setParameter('ids', [$first->id, $second->id])
            ->getResult();
        $this->assertQueryCount(2, 'The books of all shelves must be loaded by a single batch query.');

        self::assertCount(2, $shelves);
        [$loadedFirst, $loadedSecond] = $shelves;
        assert($loadedFirst instanceof Shelf && $loadedSecond instanceof Shelf);

        self::assertInstanceOf(BookList::class, $loadedFirst->books);
        $inner = $loadedFirst->books->getDelegatee();
        assert($inner instanceof PersistentCollection);
        self::assertTrue($inner->isInitialized(), 'Eager collections must be initialized by the batch query.');

        $this->getQueryLog()->reset()->enable();
        self::assertSame(['Astronomy', 'Zoology'], $loadedFirst->books->titles());
        self::assertSame(['Physics'], $loadedSecond->books->titles());
        $this->assertQueryCount(0, 'Reading eagerly loaded custom collections must not trigger queries.');
    }

    public function testMatchingOnInheritedApiReturnsPlainCollection(): void
    {
        $library = $this->createLibrary();
        $this->_em->clear();

        $loaded = $this->_em->find(Library::class, $library->id);
        assert($loaded instanceof Library);

        $criteria = Criteria::create()->where(Criteria::expr()->eq('name', 'php'));
        $matched  = $loaded->getTags()->matching($criteria);

        self::assertNotInstanceOf(TagCollection::class, $matched, 'matching() intentionally returns a plain collection.');
        self::assertCount(1, $matched);
        self::assertSame('php', $matched->first()->name);
    }

    public function testDebugDumpDoesNotInitializeCustomCollections(): void
    {
        $shelf = new Shelf();
        $shelf->tags->add(new Tag('php'));
        $this->_em->persist($shelf);
        $this->_em->flush();
        $this->_em->clear();

        $loaded = $this->_em->find(Shelf::class, $shelf->id);
        assert($loaded instanceof Shelf);

        $file = sys_get_temp_dir() . '/' . uniqid('doctrine_debug_', true);
        (new DebugUnitOfWorkListener($file))->dumpIdentityMap($this->_em);
        $dump = file_get_contents($file);
        unlink($file);

        self::assertStringContainsString('tags [PROXY] PersistentCollection unknown element size', (string) $dump);
        $inner = $loaded->tags->getDelegatee();
        assert($inner instanceof PersistentCollection);
        self::assertFalse($inner->isInitialized(), 'Dumping the identity map must not initialize the collection.');
    }

    public function testListenersSeeTheManagedCollection(): void
    {
        $library = $this->createLibrary();

        $listener = new class {
            /** @var array<int, PersistentCollection<array-key, mixed>> */
            public array $scheduledUpdates = [];

            /** @var list<object> */
            public array $preUpdateEntities = [];

            public function onFlush(OnFlushEventArgs $args): void
            {
                $this->scheduledUpdates = $args->getObjectManager()->getUnitOfWork()->getScheduledCollectionUpdates();
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $this->preUpdateEntities[] = $args->getObject();
            }
        };
        $this->_em->getEventManager()->addEventListener([Events::onFlush, Events::preUpdate], $listener);

        $library->addTag(new Tag('python'));
        $this->_em->flush();

        self::assertCount(1, $listener->scheduledUpdates);
        $collection = current($listener->scheduledUpdates);
        self::assertSame($library->getTags()->getDelegatee(), $collection);
        self::assertSame($library, $collection->getOwner());
        self::assertSame('tags', $collection->getMapping()->fieldName);

        self::assertSame([$library], $listener->preUpdateEntities);
    }
}
