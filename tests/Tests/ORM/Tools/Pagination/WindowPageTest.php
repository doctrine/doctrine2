<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\ORM\Tools\Pagination\WindowPage;
use LogicException;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class WindowPageTest extends TestCase
{
    public function testGetItemsAndCount(): void
    {
        $items = [(object) ['id' => 1], (object) ['id' => 2]];
        $page  = new WindowPage($items, 10, new Window(0, 2));

        self::assertSame($items, $page->getItems());
        self::assertCount(2, $page);
        self::assertSame($items, iterator_to_array($page));
    }

    public function testGetTotalCount(): void
    {
        $page = new WindowPage([], 42, new Window(0, 20));

        self::assertSame(42, $page->getTotalCount());
    }

    public function testLazyTotalCountIsOnlyComputedOnDemandAndMemoized(): void
    {
        $calls = 0;
        $page  = new WindowPage([], static function () use (&$calls): int {
            ++$calls;

            return 42;
        }, new Window(0, 20));

        self::assertSame(0, $calls);

        self::assertSame(42, $page->getTotalCount());
        self::assertSame(42, $page->getTotalCount());
        self::assertSame(3, $page->getPageCount());
        self::assertTrue($page->hasNextPage());
        self::assertTrue($page->hasToPaginate());

        self::assertSame(1, $calls);
    }

    public function testFirstPageHasNextButNoPreviousPage(): void
    {
        $page = new WindowPage([(object) ['id' => 1], (object) ['id' => 2]], 10, new Window(0, 2));

        self::assertFalse($page->hasPreviousPage());
        self::assertTrue($page->hasNextPage());
        self::assertTrue($page->hasToPaginate());
    }

    public function testMiddlePageHasBothPages(): void
    {
        $page = new WindowPage([(object) ['id' => 3], (object) ['id' => 4]], 10, new Window(2, 2));

        self::assertTrue($page->hasPreviousPage());
        self::assertTrue($page->hasNextPage());
    }

    public function testLastPageHasPreviousButNoNextPage(): void
    {
        $page = new WindowPage([(object) ['id' => 9], (object) ['id' => 10]], 10, new Window(8, 2));

        self::assertTrue($page->hasPreviousPage());
        self::assertFalse($page->hasNextPage());
    }

    public function testSinglePageHasNoNavigation(): void
    {
        $page = new WindowPage([(object) ['id' => 1]], 1, new Window(0, 20));

        self::assertFalse($page->hasPreviousPage());
        self::assertFalse($page->hasNextPage());
        self::assertFalse($page->hasToPaginate());
    }

    public function testGetNextWindow(): void
    {
        $page = new WindowPage([(object) ['id' => 1], (object) ['id' => 2]], 10, new Window(0, 2));

        self::assertSame(2, $page->getNextWindow()->getFirstResult());
    }

    public function testGetPreviousWindow(): void
    {
        $page = new WindowPage([(object) ['id' => 3], (object) ['id' => 4]], 10, new Window(2, 2));

        self::assertSame(0, $page->getPreviousWindow()->getFirstResult());
    }

    public function testGetNextWindowThrowsWhenNoNextPage(): void
    {
        $page = new WindowPage([(object) ['id' => 1]], 1, new Window(0, 20));

        $this->expectException(LogicException::class);
        $page->getNextWindow();
    }

    public function testGetPreviousWindowThrowsWhenNoPreviousPage(): void
    {
        $page = new WindowPage([(object) ['id' => 1]], 10, new Window(0, 2));

        $this->expectException(LogicException::class);
        $page->getPreviousWindow();
    }

    public function testGetWindow(): void
    {
        $window = new Window(4, 2);
        $page   = new WindowPage([], 10, $window);

        self::assertSame($window, $page->getWindow());
    }

    public function testGetPageNumber(): void
    {
        $page = new WindowPage([], 10, new Window(4, 2));

        self::assertSame(3, $page->getPageNumber());
    }

    public function testGetPageCount(): void
    {
        self::assertSame(5, (new WindowPage([], 10, new Window(0, 2)))->getPageCount());
        self::assertSame(4, (new WindowPage([], 10, new Window(0, 3)))->getPageCount());
        self::assertSame(1, (new WindowPage([], 10, new Window(0, 20)))->getPageCount());
    }

    public function testGetPageCountIsAtLeastOneOnEmptyResultSet(): void
    {
        self::assertSame(1, (new WindowPage([], 0, new Window(0, 20)))->getPageCount());
    }

    public function testGetLastWindow(): void
    {
        $lastWindow = (new WindowPage([], 10, new Window(0, 3)))->getLastWindow();

        self::assertSame(9, $lastWindow->getFirstResult());
        self::assertSame(3, $lastWindow->getMaxResults());
        self::assertSame(4, $lastWindow->getPageNumber());
    }

    public function testGetLastWindowOnEmptyResultSetPointsAtTheFirstPage(): void
    {
        $lastWindow = (new WindowPage([], 0, new Window(0, 20)))->getLastWindow();

        self::assertSame(0, $lastWindow->getFirstResult());
        self::assertSame(1, $lastWindow->getPageNumber());
    }
}
