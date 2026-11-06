<?php

declare(strict_types=1);

namespace Doctrine\Performance\Hydration;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Internal\Hydration\ArrayHydrator;
use Doctrine\ORM\Internal\Hydration\ObjectHydrator;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Performance\EntityManagerFactory;
use Doctrine\Tests\DbalTypes\UuidObjectType;
use Doctrine\Tests\Mocks\ArrayResultFactory;
use Doctrine\Tests\Models\ShoppingCart\Cart;
use Doctrine\Tests\Models\ShoppingCart\CartItem;
use Doctrine\Tests\Models\ShoppingCart\Category;
use Generator;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;

use function sprintf;

/**
 * Hydrates carts with many columns and costly types, fetch joined with their items in different shapes.
 *
 * This shows how often the columns of a parent that repeats on many rows are converted.
 */
#[BeforeMethods('init')]
#[ParamProviders('provideShapes')]
#[Revs(1)]
#[Iterations(10)]
final class ShoppingCartFetchJoinHydrationBench
{
    private const CART_FIELDS = ['id', 'uuid', 'name', 'status', 'createdAt', 'updatedAt', 'options', 'metadata', 'active', 'total', 'version'];

    private const ITEM_FIELDS = ['id', 'uuid', 'sku', 'quantity', 'addedAt', 'options'];

    private EntityManagerInterface $entityManager;

    private ResultSetMapping $rsm;

    /** @var list<array<string, mixed>> */
    private array $resultSet;

    /** @return Generator<string, array{join: string, carts: int, items: int, hydrator: string, itemsPresent: bool}> */
    public function provideShapes(): Generator
    {
        yield 'root only' => ['join' => 'none', 'carts' => 10000, 'items' => 0, 'hydrator' => 'object', 'itemsPresent' => true];
        yield '10000 carts x 1 item' => ['join' => 'items', 'carts' => 10000, 'items' => 1, 'hydrator' => 'object', 'itemsPresent' => true];
        yield '1000 carts x 10 items' => ['join' => 'items', 'carts' => 1000, 'items' => 10, 'hydrator' => 'object', 'itemsPresent' => true];
        yield '100 carts x 100 items' => ['join' => 'items', 'carts' => 100, 'items' => 100, 'hydrator' => 'object', 'itemsPresent' => true];
        yield '10 carts x 1000 items' => ['join' => 'items', 'carts' => 10, 'items' => 1000, 'hydrator' => 'object', 'itemsPresent' => true];
        yield '100 carts x 100 items + category' => ['join' => 'items+category', 'carts' => 100, 'items' => 100, 'hydrator' => 'object', 'itemsPresent' => true];
        yield 'left join without items' => ['join' => 'items', 'carts' => 10000, 'items' => 1, 'hydrator' => 'object', 'itemsPresent' => false];
        yield 'array 100 carts x 100 items' => ['join' => 'items', 'carts' => 100, 'items' => 100, 'hydrator' => 'array', 'itemsPresent' => true];
    }

    /** @param array{join: string, carts: int, items: int, hydrator: string, itemsPresent: bool} $params */
    public function init(array $params): void
    {
        if (! Type::hasType(UuidObjectType::NAME)) {
            Type::addType(UuidObjectType::NAME, UuidObjectType::class);
        }

        $this->entityManager = EntityManagerFactory::getEntityManager([]);
        $this->rsm           = $this->createResultSetMapping($params['join']);
        $this->resultSet     = $this->createResultSet($params);
    }

    /** @param array{join: string, carts: int, items: int, hydrator: string, itemsPresent: bool} $params */
    public function benchHydrateAll(array $params): void
    {
        $this->createHydrator($params['hydrator'])->hydrateAll(
            ArrayResultFactory::createWrapperResultFromArray($this->resultSet),
            $this->rsm,
        );

        $this->entityManager->clear();
    }

    private function createHydrator(string $hydrator): AbstractHydrator
    {
        return $hydrator === 'array'
            ? new ArrayHydrator($this->entityManager)
            : new ObjectHydrator($this->entityManager);
    }

    private function createResultSetMapping(string $join): ResultSetMapping
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(Cart::class, 'c');

        foreach (self::CART_FIELDS as $field) {
            $rsm->addFieldResult('c', 'c__' . $field, $field);
        }

        if ($join === 'none') {
            return $rsm;
        }

        $rsm->addJoinedEntityResult(CartItem::class, 'i', 'c', 'items');

        foreach (self::ITEM_FIELDS as $field) {
            $rsm->addFieldResult('i', 'i__' . $field, $field);
        }

        $rsm->addMetaResult('i', 'i__cart_id', 'cart_id', false, 'integer');
        $rsm->addMetaResult('i', 'i__category_id', 'category_id', false, 'integer');

        if ($join === 'items+category') {
            $rsm->addJoinedEntityResult(Category::class, 'g', 'i', 'category');
            $rsm->addFieldResult('g', 'g__id', 'id');
            $rsm->addFieldResult('g', 'g__name', 'name');
        }

        return $rsm;
    }

    /**
     * @param array{join: string, carts: int, items: int, hydrator: string, itemsPresent: bool} $params
     *
     * @return list<array<string, mixed>>
     */
    private function createResultSet(array $params): array
    {
        $resultSet = [];
        $itemId    = 0;

        for ($cartId = 1; $cartId <= $params['carts']; ++$cartId) {
            $cart = [
                'c__id' => (string) $cartId,
                'c__uuid' => sprintf('%08x-0000-4000-8000-%012x', $cartId, $cartId),
                'c__name' => 'Cart ' . $cartId,
                'c__status' => 'open',
                'c__createdAt' => '2026-01-01 10:00:00',
                'c__updatedAt' => '2026-01-02 11:00:00',
                'c__options' => '{"gift":true,"note":"please wrap","tags":["a","b","c"]}',
                'c__metadata' => '{"source":"web","campaign":{"id":12,"name":"autumn"}}',
                'c__active' => '1',
                'c__total' => '123.45',
                'c__version' => '3',
            ];

            if ($params['join'] === 'none') {
                $resultSet[] = $cart;

                continue;
            }

            for ($i = 1; $i <= $params['items']; ++$i) {
                ++$itemId;
                $categoryId = (string) ($itemId % 5 + 1);

                $row = $cart + [
                    'i__id' => $params['itemsPresent'] ? (string) $itemId : null,
                    'i__uuid' => $params['itemsPresent'] ? sprintf('%08x-0000-4000-8000-%012x', $itemId, $itemId) : null,
                    'i__sku' => $params['itemsPresent'] ? 'SKU-' . $itemId : null,
                    'i__quantity' => $params['itemsPresent'] ? '2' : null,
                    'i__addedAt' => $params['itemsPresent'] ? '2026-01-01 10:05:00' : null,
                    'i__options' => $params['itemsPresent'] ? '{"color":"red","size":"M"}' : null,
                    'i__cart_id' => $params['itemsPresent'] ? (string) $cartId : null,
                    'i__category_id' => $params['itemsPresent'] ? $categoryId : null,
                ];

                if ($params['join'] === 'items+category') {
                    $row['g__id']   = $categoryId;
                    $row['g__name'] = 'Category ' . $categoryId;
                }

                $resultSet[] = $row;
            }
        }

        return $resultSet;
    }
}
