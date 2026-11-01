<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Tools\Pagination\CursorPaginator;
use Doctrine\Tests\DbalTypes\HexBinaryType;
use Doctrine\Tests\Models\Pagination\BinaryCodeItem;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function sprintf;

class CursorPaginatorBinaryValueTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Type::hasType(HexBinaryType::NAME)) {
            Type::overrideType(HexBinaryType::NAME, HexBinaryType::class);
        } else {
            Type::addType(HexBinaryType::NAME, HexBinaryType::class);
        }

        $this->createSchemaForModels(BinaryCodeItem::class);

        // 0xff never appears in UTF-8, so no database value is valid UTF-8
        for ($i = 5; $i >= 1; --$i) {
            $this->_em->persist(new BinaryCodeItem(sprintf('ff%06x', $i)));
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    protected function tearDown(): void
    {
        $this->_em->getConnection()->executeStatement('DELETE FROM pagination_binary_code_item');

        parent::tearDown();
    }

    #[DataProvider('queryProducesDuplicates')]
    public function testEncodedCursorsNavigateOverBinaryDatabaseValues(bool $queryProducesDuplicates): void
    {
        $query = $this->_em->createQuery(
            'SELECT i FROM Doctrine\Tests\Models\Pagination\BinaryCodeItem i ORDER BY i.code ASC',
        );

        $paginator = new CursorPaginator(2, $queryProducesDuplicates);

        $firstPage = $paginator->paginate($query);
        self::assertSame(['ff000001', 'ff000002'], self::codes($firstPage->getItems()));

        $secondPage = $paginator->paginate($query, $firstPage->getNextCursorAsString());
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
     * @param list<BinaryCodeItem> $items
     *
     * @return list<string>
     */
    private static function codes(array $items): array
    {
        return array_map(static fn (BinaryCodeItem $item): string => $item->code, $items);
    }
}
