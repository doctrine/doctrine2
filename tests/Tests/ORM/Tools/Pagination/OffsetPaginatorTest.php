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
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\ORM\Tools\Pagination\WindowPage;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

use function enum_exists;
use function method_exists;

class OffsetPaginatorTest extends OrmTestCase
{
    private Connection&MockObject $connection;
    private EntityManagerInterface&Stub $em;
    private AbstractHydrator&Stub $hydrator;

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

        $driver = $this->createStub(Driver::class);
        $driver->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->connection = $this->getMockBuilder(Connection::class)
            ->onlyMethods(['executeQuery'])
            ->setConstructorArgs([[], $driver])
            ->getMock();

        $this->em = $this->getMockBuilder(EntityManagerDecorator::class)
            ->onlyMethods(['newHydrator'])
            ->setConstructorArgs([$this->createTestEntityManagerWithConnection($this->connection)])
            ->getMock();

        $this->hydrator = $this->createStub(AbstractHydrator::class);
        $this->em->method('newHydrator')->willReturn($this->hydrator);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExtraParametersAreStrippedWhenWalkerRemovingOriginalSelectElementsIsUsed(): void
    {
        $paramInWhere     = 1;
        $paramInSubSelect = 2;
        $returnedIds      = [10];

        $this->hydrator->method('hydrateAll')->willReturn([$returnedIds]);

        $query = new Query($this->em);
        $query->setDQL(
            'SELECT u,
                (
                    SELECT MAX(a.version)
                    FROM Doctrine\\Tests\\Models\\CMS\\CmsArticle a
                    WHERE a.user = u AND 1 = :paramInSubSelect
                ) AS HIDDEN max_version
            FROM Doctrine\\Tests\\Models\\CMS\\CmsUser u
            WHERE u.id = :paramInWhere',
        );
        $query->setParameters(['paramInWhere' => $paramInWhere, 'paramInSubSelect' => $paramInSubSelect]);

        $receivedParams = [];
        $resultStub     = $this->createStub(Result::class);
        $this->connection
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $params) use (&$receivedParams, $resultStub): Result {
                $receivedParams[] = $params;

                return $resultStub;
            });

        (new OffsetPaginator(true, false))->paginate($query, new Window(0, 1))->getTotalCount();

        self::assertSame([
            [$paramInWhere],
            [$paramInSubSelect, $paramInWhere, $returnedIds],
            [$paramInWhere],
        ], $receivedParams);
    }

    public function testPaginatingDoesNotCareAboutExtraParametersWithoutOutputWalkersWhenResultIsEmpty(): void
    {
        $result = $this->getMockBuilder(Result::class)->disableOriginalConstructor()->getMock();
        $this->connection->expects(self::exactly(2))->method('executeQuery')->willReturn($result);

        $page = $this->paginateWithExtraParametersWithoutOutputWalkers([]);

        self::assertCount(0, $page);
        self::assertSame(0, $page->getTotalCount());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCountQueryIsOnlyExecutedWhenTheTotalCountIsRequested(): void
    {
        $executedQueries = 0;
        $resultStub      = $this->createStub(Result::class);
        $this->connection
            ->method('executeQuery')
            ->willReturnCallback(static function () use (&$executedQueries, $resultStub): Result {
                ++$executedQueries;

                return $resultStub;
            });

        $this->hydrator->method('hydrateAll')->willReturn([]);

        $query = new Query($this->em);
        $query->setDQL('SELECT u FROM Doctrine\\Tests\\Models\\CMS\\CmsUser u');

        $page = (new OffsetPaginator(true, false))->paginate($query, new Window(0, 1));

        self::assertSame(1, $executedQueries, 'Only the identifier query has been executed.');

        self::assertSame(0, $page->getTotalCount());
        self::assertSame(2, $executedQueries, 'The COUNT query has been executed on demand.');

        self::assertSame(0, $page->getTotalCount());
        self::assertSame(2, $executedQueries, 'The COUNT query result is memoized.');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTotalCountOnlyExecutesTheCountQuery(): void
    {
        $executedSql = [];
        $resultStub  = $this->createStub(Result::class);
        $this->connection
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql) use (&$executedSql, $resultStub): Result {
                $executedSql[] = $sql;

                return $resultStub;
            });

        $this->hydrator->method('hydrateAll')->willReturn([[3]]);

        $query = new Query($this->em);
        $query->setDQL('SELECT u FROM Doctrine\\Tests\\Models\\CMS\\CmsUser u');

        self::assertSame(3, (new OffsetPaginator(true, false))->getTotalCount($query));
        self::assertCount(1, $executedSql, 'Only the COUNT query has been executed.');
        self::assertStringStartsWith('SELECT count(DISTINCT', $executedSql[0]);
    }

    public function testPaginatingDoesCareAboutExtraParametersWithoutOutputWalkersWhenResultIsNotEmpty(): void
    {
        $result = $this->getMockBuilder(Result::class)->disableOriginalConstructor()->getMock();
        $this->connection->expects(self::exactly(1))->method('executeQuery')->willReturn($result);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Too many parameters: the query defines 1 parameters and you bound 2');

        $this->paginateWithExtraParametersWithoutOutputWalkers([[10]]);
    }

    /**
     * @param int[][] $willReturnRows
     *
     * @return WindowPage<mixed>
     */
    private function paginateWithExtraParametersWithoutOutputWalkers(array $willReturnRows): WindowPage
    {
        $this->hydrator->method('hydrateAll')->willReturn($willReturnRows);
        $this->connection->method('executeQuery')->with(self::anything(), []);

        $query = new Query($this->em);
        $query->setDQL('SELECT u FROM Doctrine\\Tests\\Models\\CMS\\CmsUser u');
        $query->setParameters(['paramInWhere' => 1]);

        return (new OffsetPaginator(true, false))->paginate($query, new Window(0, 1));
    }
}
