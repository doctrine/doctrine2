<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Tools\Pagination\Cursor;
use Doctrine\ORM\Tools\Pagination\CursorPaginator;
use Doctrine\Tests\DbalTypes\BinaryCode;
use Doctrine\Tests\DbalTypes\BinaryCodeType;
use Doctrine\Tests\Models\Pagination\CodedItem;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function sprintf;

class CursorPaginatorBinaryValueTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Type::hasType(BinaryCodeType::NAME)) {
            Type::overrideType(BinaryCodeType::NAME, BinaryCodeType::class);
        } else {
            Type::addType(BinaryCodeType::NAME, BinaryCodeType::class);
        }

        $this->createSchemaForModels(CodedItem::class);

        // 0xff never appears in UTF-8, so none of the database values can be put in JSON as such
        for ($i = 5; $i >= 1; --$i) {
            $this->_em->persist(new CodedItem(new BinaryCode(sprintf('ff%06x', $i))));
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    protected function tearDown(): void
    {
        $this->_em->getConnection()->executeStatement('DELETE FROM pagination_coded_item');

        parent::tearDown();
    }

    #[DataProvider('queryProducesDuplicates')]
    public function testEncodedCursorsNavigateOverValuesStoredAsBinary(bool $queryProducesDuplicates): void
    {
        $query     = $this->_em->createQuery('SELECT i FROM Doctrine\Tests\Models\Pagination\CodedItem i ORDER BY i.code ASC');
        $paginator = new CursorPaginator(2, $queryProducesDuplicates);

        $firstPage = $paginator->paginate($query);
        self::assertSame(['ff000001', 'ff000002'], self::codes($firstPage->getItems()));

        $nextCursor = $firstPage->getNextCursorAsString();
        self::assertSame(['i.code' => "\xff\x00\x00\x02"], Cursor::fromEncodedString($nextCursor)->getParameters());

        $secondPage = $paginator->paginate($query, $nextCursor);
        self::assertSame(['ff000003', 'ff000004'], self::codes($secondPage->getItems()));

        $thirdPage = $paginator->paginate($query, $secondPage->getNextCursorAsString());
        self::assertSame(['ff000005'], self::codes($thirdPage->getItems()));
        self::assertFalse($thirdPage->hasNextPage());

        $previousPage = $paginator->paginate($query, $thirdPage->getPreviousCursorAsString());
        self::assertSame(['ff000003', 'ff000004'], self::codes($previousPage->getItems()));
    }

    /** @return iterable<string, array{bool}> */
    public static function queryProducesDuplicates(): iterable
    {
        yield 'plain query' => [false];
        yield 'query producing duplicates' => [true];
    }

    /**
     * @param list<CodedItem> $items
     *
     * @return list<string>
     */
    private static function codes(array $items): array
    {
        return array_map(static fn (CodedItem $item): string => (string) $item->code, $items);
    }
}
