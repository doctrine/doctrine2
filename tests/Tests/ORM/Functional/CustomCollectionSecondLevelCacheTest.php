<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\CustomCollection\Book;
use Doctrine\Tests\Models\CustomCollection\BookList;
use Doctrine\Tests\Models\CustomCollection\Library;
use Doctrine\Tests\Models\CustomCollection\Shelf;
use Doctrine\Tests\Models\CustomCollection\Tag;
use Doctrine\Tests\Models\CustomCollection\TagCollection;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

class CustomCollectionSecondLevelCacheTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->enableSecondLevelCache();

        parent::setUp();

        $this->createSchemaForModels(Library::class, Book::class, Tag::class, Shelf::class);
    }

    private function createLibrary(): Library
    {
        $library = new Library('Central');
        $library->addBook(new Book('Zoology'));
        $library->addBook(new Book('Astronomy'));
        $library->addTag(new Tag('php'));

        $this->_em->persist($library);
        $this->_em->flush();
        $this->_em->clear();

        return $library;
    }

    public function testCollectionsHydratedFromCacheUseCustomCollections(): void
    {
        $library = $this->createLibrary();

        // Warm the entity and collection regions.
        $warm = $this->_em->find(Library::class, $library->id);
        assert($warm instanceof Library);
        $warm->getBooks()->titles();
        $warm->getTags()->names();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();

        $cached = $this->_em->find(Library::class, $library->id);
        assert($cached instanceof Library);

        self::assertInstanceOf(BookList::class, $cached->getBooks());
        self::assertInstanceOf(TagCollection::class, $cached->getTags());
        self::assertSame(['Astronomy', 'Zoology'], $cached->getBooks()->titles());
        self::assertSame(['php'], $cached->getTags()->names());
        $this->assertQueryCount(0, 'Entity and collections must be served from the second level cache.');
    }

    public function testCacheableQueryHydratesCustomCollections(): void
    {
        $library = $this->createLibrary();

        $dql = 'SELECT l, t FROM ' . Library::class . ' l JOIN l.tags t WHERE l.id = :id';

        $loaded = $this->_em->createQuery($dql)
            ->setParameter('id', $library->id)
            ->setCacheable(true)
            ->getSingleResult();
        assert($loaded instanceof Library);

        self::assertInstanceOf(TagCollection::class, $loaded->getTags());
        $this->_em->clear();

        $cached = $this->_em->createQuery($dql)
            ->setParameter('id', $library->id)
            ->setCacheable(true)
            ->getSingleResult();
        assert($cached instanceof Library);

        self::assertInstanceOf(TagCollection::class, $cached->getTags());
        self::assertSame(['php'], $cached->getTags()->names());
    }

    public function testCacheableQueryStoresTheUnderlyingCollection(): void
    {
        $shelf = new Shelf();
        $shelf->tags->add(new Tag('php'));
        $shelf->tags->add(new Tag('_draft'));
        $this->_em->persist($shelf);
        $this->_em->flush();
        $this->_em->clear();

        $dql = 'SELECT s, t FROM ' . Shelf::class . ' s JOIN s.tags t WHERE s.id = :id';

        $this->_em->createQuery($dql)->setParameter('id', $shelf->id)->setCacheable(true)->getSingleResult();
        $this->_em->clear();

        $cached = $this->_em->createQuery($dql)->setParameter('id', $shelf->id)->setCacheable(true)->getSingleResult();
        assert($cached instanceof Shelf);

        self::assertCount(2, $cached->tags->getDoctrineCollection(), 'The query cache must store every element, not only the ones the custom iterator yields.');
    }
}
