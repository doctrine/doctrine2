<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Cursor;
use Doctrine\ORM\Tools\Pagination\CursorItem;
use Doctrine\ORM\Tools\Pagination\CursorPaginator;
use Doctrine\ORM\Tools\Pagination\Exception\InvalidCursor;
use Doctrine\Tests\OrmTestCase;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use SortDirection;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function enum_exists;
use function method_exists;

require_once __DIR__ . '/PaginationTestCase.php';

class CursorPaginatorTest extends OrmTestCase
{
    private Connection&MockObject $connection;
    private EntityManagerInterface&MockObject $em;
    private AbstractHydrator&MockObject $hydrator;

    protected function setUp(): void
    {
        $platform = $this->getMockBuilder(AbstractPlatform::class)
            ->setConstructorArgs(enum_exists(UnquotedIdentifierFolding::class) ? [UnquotedIdentifierFolding::UPPER] : [])
            ->getMock();
        if (method_exists($platform, 'getUnquotedIdentifierFolding')) {
            $platform->method('getUnquotedIdentifierFolding')->willReturn(UnquotedIdentifierFolding::NONE);
        }

        $platform->method('supportsIdentityColumns')
            ->willReturn(true);

        $driver = $this->createMock(Driver::class);
        $driver->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->connection = $this->getMockBuilder(Connection::class)
            ->onlyMethods(['executeQuery'])
            ->setConstructorArgs([[], $driver])
            ->getMock();

        $this->em = $this->getMockBuilder(EntityManagerDecorator::class)
            ->onlyMethods(['newHydrator', 'createQuery'])
            ->setConstructorArgs([$this->createTestEntityManagerWithConnection($this->connection)])
            ->getMock();

        $this->hydrator = $this->createMock(AbstractHydrator::class);
        $this->em->method('newHydrator')->willReturn($this->hydrator);

        // keep queries built through this entity manager (e.g. by a QueryBuilder) bound to
        // it, so that they go through the mocked hydrator
        $this->em->method('createQuery')->willReturnCallback(function (string $dql = ''): Query {
            $query = new Query($this->em);
            $query->setDQL($dql);

            return $query;
        });
    }

    public function testPaginatorAcceptsQueryBuilder(): void
    {
        $items = [(object) ['id' => 1]];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        // not $this->em->createQueryBuilder(): the decorator would bind the builder to the
        // wrapped entity manager, bypassing the mocked hydrator.
        $qb = (new QueryBuilder($this->em))
            ->select('p')
            ->from(BlogPost::class, 'p')
            ->orderBy('p.id', SortDirection::Ascending);

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($qb, null);

        self::assertSame($items, $page->getItems());
    }

    public function testPaginatorIsReusableAcrossQueriesAndPages(): void
    {
        $this->hydrator->method('hydrateAll')->willReturn([(object) ['id' => 1]]);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $otherQuery = new Query($this->em);
        $otherQuery->setDQL('SELECT b FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost b ORDER BY b.id ASC');

        $paginator = new CursorPaginator(10, queryProducesDuplicates: false);

        self::assertCount(1, $paginator->paginate($query, null));
        self::assertCount(1, $paginator->paginate($otherQuery, null));
    }

    public function testHasNextPageWhenMoreResultsExist(): void
    {
        $items = [
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
            (object) ['id' => 4],
        ];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(3, queryProducesDuplicates: false))->paginate($query, null);

        self::assertTrue($page->hasNextPage());
        self::assertFalse($page->hasPreviousPage());
        self::assertSame(3, $page->count());
    }

    public function testHasNoPagesOnFirstPageWithoutMoreResults(): void
    {
        $items = [(object) ['id' => 1]];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        self::assertFalse($page->hasPreviousPage());
        self::assertFalse($page->hasNextPage());
        self::assertFalse($page->hasToPaginate());
    }

    public function testGetNextCursorAsStringThrowsWhenNoNextPage(): void
    {
        $items = [(object) ['id' => 1]];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        $this->expectException(LogicException::class);
        $page->getNextCursorAsString();
    }

    public function testGetPreviousCursorAsStringThrowsWhenNoPreviousPage(): void
    {
        $items = [(object) ['id' => 1]];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        $this->expectException(LogicException::class);
        $page->getPreviousCursorAsString();
    }

    public function testGetNextCursorThrowsWhenNoNextPage(): void
    {
        $items = [(object) ['id' => 1]];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        $this->expectException(LogicException::class);
        $page->getNextCursor();
    }

    public function testGetPreviousCursorThrowsWhenNoPreviousPage(): void
    {
        $items = [(object) ['id' => 1]];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        $this->expectException(LogicException::class);
        $page->getPreviousCursor();
    }

    public function testGetNextCursorWhenMoreResultsExist(): void
    {
        $items = [
            (object) ['id' => 1],
            (object) ['id' => 2],
        ];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(1, queryProducesDuplicates: false))->paginate($query, null);

        $nextCursor = $page->getNextCursor();

        self::assertTrue($nextCursor->isNext());
    }

    public function testGetNextCursorAsStringReturnsStringWhenNextPageExists(): void
    {
        $items = [
            (object) ['id' => 1],
            (object) ['id' => 2],
        ];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(1, queryProducesDuplicates: false))->paginate($query, null);

        self::assertNotEmpty($page->getNextCursorAsString());
    }

    public function testGetPreviousCursorAsStringReturnsStringWhenPreviousPageExists(): void
    {
        $items = [
            (object) ['id' => 1],
            (object) ['id' => 2],
        ];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $cursor = (new Cursor(['p__id' => 1], true))->encodeToString();
        $page   = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, $cursor);

        self::assertNotEmpty($page->getPreviousCursorAsString());
    }

    public function testEmptyResultSet(): void
    {
        $this->hydrator->method('hydrateAll')->willReturn([]);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        self::assertSame(0, $page->count());
        self::assertFalse($page->hasNextPage());
        self::assertFalse($page->hasPreviousPage());
    }

    public function testIteratorReturnsItems(): void
    {
        $items = [
            (object) ['id' => 1],
            (object) ['id' => 2],
        ];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        $iteratedItems = [];
        foreach ($page as $item) {
            $iteratedItems[] = $item;
        }

        self::assertCount(2, $iteratedItems);
    }

    public function testGetItemsReturnsRawEntities(): void
    {
        $items = [
            (object) ['id' => 1],
            (object) ['id' => 2],
        ];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        $values = $page->getItems();

        self::assertCount(2, $values);
        self::assertSame($items[0], $values[0]);
        self::assertSame($items[1], $values[1]);
    }

    public function testGetItemsWithCursorsReturnsCursorItems(): void
    {
        $items = [
            (object) ['id' => 1],
            (object) ['id' => 2],
        ];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        $cursorItems = $page->getItemsWithCursors();

        self::assertCount(2, $cursorItems);
        self::assertInstanceOf(CursorItem::class, $cursorItems[0]);
        self::assertSame($items[0], $cursorItems[0]->getValue());
        self::assertInstanceOf(Cursor::class, $cursorItems[0]->getCursor());
        self::assertSame($items[1], $cursorItems[1]->getValue());
    }

    public function testGetItemsReturnsEmptyArrayWhenNoResults(): void
    {
        $this->hydrator->method('hydrateAll')->willReturn([]);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        self::assertSame([], $page->getItemsWithCursors());
        self::assertSame([], $page->getItems());
    }

    public function testPaginateWithCursorInstance(): void
    {
        $items = [
            (object) ['id' => 1],
            (object) ['id' => 2],
        ];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $cursor = new Cursor(['p.id' => 1], true);
        $page   = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, $cursor);

        self::assertNotEmpty($page->getPreviousCursorAsString());
    }

    public function testPaginateWithEmptyCursorTreatedAsFirstPage(): void
    {
        $items = [(object) ['id' => 1]];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, '');

        self::assertFalse($page->hasPreviousPage());
        self::assertFalse($page->hasNextPage());
    }

    public function testPaginateWithPreviousCursorReversesItems(): void
    {
        $items = [
            (object) ['id' => 3],
            (object) ['id' => 2],
            (object) ['id' => 1],
        ];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $previousCursor = (new Cursor(['p__id' => 3], false))->encodeToString();

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, $previousCursor);

        $values = $page->getItems();
        self::assertCount(3, $values);
        self::assertSame(1, $values[0]->id);
        self::assertSame(2, $values[1]->id);
        self::assertSame(3, $values[2]->id);
    }

    public function testPaginateThrowsInvalidCursorForNonStringNonCursorNonNull(): void
    {
        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $this->expectException(InvalidCursor::class);
        (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, ['injected' => 'array']);
    }

    public function testDefaultQueryProducesDuplicatesIsTrue(): void
    {
        $paginator = new CursorPaginator(10);

        self::assertSame(10, $paginator->getLimit());
        self::assertTrue($paginator->getQueryProducesDuplicates());
    }

    public function testGetTotalCountReturnsCountQueryResult(): void
    {
        $this->hydrator->method('hydrateAll')->willReturn([[1 => 42]]);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false, useOutputWalkers: false))
            ->paginate($query, null);

        self::assertSame(42, $page->getTotalCount());
    }

    public function testGetTotalCountIsCached(): void
    {
        $this->hydrator->method('hydrateAll')->willReturn([[1 => 5]]);
        $result = $this->createMock(Result::class);
        // one executeQuery for the page query, one for the (cached) count query
        $this->connection->expects(self::exactly(2))->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.id ASC');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false, useOutputWalkers: false))
            ->paginate($query, null);

        self::assertSame(5, $page->getTotalCount());
        self::assertSame(5, $page->getTotalCount());
    }

    public function testCloneQueryCopiesExistingHints(): void
    {
        $items = [(object) ['id' => 1]];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');
        $query->setHint('custom.hint', 'custom_value');

        $page = (new CursorPaginator(10, queryProducesDuplicates: false))->paginate($query, null);

        self::assertSame(1, $page->count());
    }

    public function testGetNextCursorUnwrapsManyToOneAssociationToIdentifier(): void
    {
        $author            = new Author();
        $author->id        = 42;
        $blogPost1         = new BlogPost();
        $blogPost1->author = $author;

        $author2           = new Author();
        $author2->id       = 43;
        $blogPost2         = new BlogPost();
        $blogPost2->author = $author2;

        $this->hydrator->method('hydrateAll')->willReturn([$blogPost1, $blogPost2]);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $query = new Query($this->em);
        $query->setDQL('SELECT b FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost b ORDER BY b.author ASC');

        $page = (new CursorPaginator(1, queryProducesDuplicates: false))->paginate($query, null);

        self::assertTrue($page->hasNextPage());

        $cursor = $page->getNextCursor();
        self::assertSame(42, $cursor->getParameters()['b.author']);
    }

    public function testPaginateDoesNotWriteQueryCacheEntryPerCursor(): void
    {
        $items = [(object) ['id' => 1]];
        $this->hydrator->method('hydrateAll')->willReturn($items);
        $result = $this->createMock(Result::class);
        $this->connection->method('executeQuery')->willReturn($result);

        $queryCache = new ArrayAdapter();
        $this->em->getConfiguration()->setQueryCache($queryCache);

        $query = new Query($this->em);
        $query->setDQL('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC');

        $paginator = new CursorPaginator(10, queryProducesDuplicates: false);

        $page = $paginator->paginate($query, null);
        $paginator->paginate($query, new Cursor(['p.id' => 10]));
        $paginator->paginate($query, new Cursor(['p.id' => 50]));

        self::assertSame($items, $page->getItems());
        self::assertCount(0, $queryCache->getValues());

        $paginator->paginate($query, new Cursor(['p.id' => 50], false));

        self::assertCount(0, $queryCache->getValues());

        $query->getSQL();
        self::assertCount(1, $queryCache->getValues());
    }
}
