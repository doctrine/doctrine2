<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function array_map;
use function count;
use function implode;
use function sort;

/**
 * Regression tests for paginator issues reported against the legacy Paginator.
 *
 * The behavior is verified against OffsetPaginator, which uses the same
 * identifier walkers.
 *
 * @see https://github.com/doctrine/orm/issues/3717
 * @see https://github.com/doctrine/orm/issues/3887
 * @see https://github.com/doctrine/orm/issues/4520
 * @see https://github.com/doctrine/orm/issues/6553
 */
final class PaginatorRegressionTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            PaginatorRegressionRoot::class,
            PaginatorRegressionLeft::class,
            PaginatorRegressionRight::class,
            PaginatorRegressionCategory::class,
            PaginatorRegressionUser::class,
            PaginatorRegressionOrganization::class,
        );

        $this->populate();
    }

    protected function tearDown(): void
    {
        if (isset($this->_em)) {
            $connection = $this->_em->getConnection();

            foreach (['PaginatorRegressionLeft', 'PaginatorRegressionRight', 'PaginatorRegressionRoot', 'PaginatorRegressionCategory', 'PaginatorRegressionUser', 'PaginatorRegressionOrganization'] as $table) {
                $connection->executeStatement('DELETE FROM ' . $table);
            }
        }

        parent::tearDown();
    }

    #[DataProvider('useOutputWalkers')]
    public function testOrderIsKeptWithFetchJoinCollection(bool $useOutputWalkers): void
    {
        $query = $this->_em->createQuery(
            'SELECT r, l FROM ' . PaginatorRegressionRoot::class . ' r JOIN r.lefts l ORDER BY r.position ASC',
        );

        $paginator = new OffsetPaginator(true, $useOutputWalkers);
        $ids       = [];

        foreach ([2, 2, 1] as $index => $expectedCount) {
            $page = $paginator->paginate($query, new Window($index * 2, 2));

            self::assertCount($expectedCount, $page, 'Page ' . $index . ' must hold ' . $expectedCount . ' roots');

            foreach ($page->getItems() as $root) {
                $ids[] = $root->position;

                self::assertCount(2, $root->lefts);
            }
        }

        self::assertSame([0, 1, 2, 3, 4], $ids);
    }

    #[DataProvider('useOutputWalkers')]
    public function testOrderByJoinedColumnIsKept(bool $useOutputWalkers): void
    {
        $query = $this->_em->createQuery(
            'SELECT r FROM ' . PaginatorRegressionRoot::class . ' r JOIN r.category c ORDER BY c.position ASC',
        );

        $paginator = new OffsetPaginator(true, $useOutputWalkers);

        $firstPage = $paginator->paginate($query, new Window(0, 2));
        self::assertSame(
            [4, 3],
            array_map(static fn (PaginatorRegressionRoot $root): int => $root->position, $firstPage->getItems()),
        );

        $secondPage = $paginator->paginate($query, new Window(2, 2));
        self::assertSame(
            [2, 1],
            array_map(static fn (PaginatorRegressionRoot $root): int => $root->position, $secondPage->getItems()),
        );
    }

    #[DataProvider('useOutputWalkers')]
    public function testOrderByCollectionFieldDoesNotLoseItems(bool $useOutputWalkers): void
    {
        $query = $this->_em->createQuery(
            'SELECT r, l, rt FROM ' . PaginatorRegressionRoot::class . ' r JOIN r.lefts l JOIN r.rights rt ORDER BY l.label ASC',
        );

        if (! $useOutputWalkers) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot select distinct identifiers from query with LIMIT and ORDER BY on a column from a fetch joined to-many association. Use output walkers.');

            (new OffsetPaginator(true, false))->paginate($query, new Window(0, 3));

            return;
        }

        $paginator  = new OffsetPaginator(true, $useOutputWalkers);
        $positions  = [];
        $pageNumber = 0;
        $sizes      = [];

        do {
            $page  = $paginator->paginate($query, new Window($pageNumber * 3, 3));
            $items = $page->getItems();

            $sizes[] = count($items);

            foreach ($items as $root) {
                $positions[] = $root->position;
            }

            $pageNumber++;
        } while ($page->hasNextPage() && $pageNumber < 10);

        self::assertSame([3, 2], $sizes, 'Every page must hold the expected number of distinct roots, got ' . implode(',', $sizes));

        sort($positions);
        self::assertSame([0, 1, 2, 3, 4], $positions, 'Every root must appear exactly once across the pages');
    }

    #[DataProvider('useOutputWalkers')]
    public function testOrderByFieldWithEagerAssociationAndCustomColumnNames(bool $useOutputWalkers): void
    {
        $query = $this->_em->createQuery(
            'SELECT u FROM ' . PaginatorRegressionUser::class . ' u ORDER BY u.username DESC, u.usernameCanonical ASC',
        );

        $page = (new OffsetPaginator(true, $useOutputWalkers))->paginate($query, new Window(0, 2));

        self::assertCount(2, $page);
        self::assertSame(['user3', 'user2'], array_map(
            static fn (PaginatorRegressionUser $user): string => $user->username,
            $page->getItems(),
        ));
    }

    /** @return list<array{bool}> */
    public static function useOutputWalkers(): array
    {
        return [[true], [false]];
    }

    private function populate(): void
    {
        // Insert the roots out of position order, so a lost ORDER BY cannot
        // produce the expected pages from the primary key order by accident.
        foreach ([2, 0, 4, 1, 3] as $i) {
            $category           = new PaginatorRegressionCategory();
            $category->position = 4 - $i;
            $this->_em->persist($category);

            $root           = new PaginatorRegressionRoot();
            $root->position = $i;
            $root->name     = 'root' . $i;
            $root->category = $category;
            $this->_em->persist($root);

            for ($j = 0; $j < 2; $j++) {
                $left        = new PaginatorRegressionLeft();
                $left->root  = $root;
                $left->label = 'L' . $i . '-' . $j;
                $this->_em->persist($left);

                $right        = new PaginatorRegressionRight();
                $right->root  = $root;
                $right->label = 'R' . $i . '-' . $j;
                $this->_em->persist($right);
            }
        }

        $organization       = new PaginatorRegressionOrganization();
        $organization->name = 'org';
        $this->_em->persist($organization);

        for ($i = 0; $i < 4; $i++) {
            $user                    = new PaginatorRegressionUser();
            $user->username          = 'user' . $i;
            $user->usernameCanonical = 'user' . $i;
            $user->organization      = $organization;
            $this->_em->persist($user);
        }

        $this->_em->flush();
        $this->_em->clear();
    }
}

#[Entity]
class PaginatorRegressionRoot
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var int */
    #[Column(type: 'integer')]
    public $position;

    /** @var string */
    #[Column(type: 'string', length: 50)]
    public $name;

    /** @var PaginatorRegressionCategory */
    #[ManyToOne(targetEntity: PaginatorRegressionCategory::class)]
    #[JoinColumn(name: 'category_id', nullable: true)]
    public $category;

    /** @phpstan-var Collection<int, PaginatorRegressionLeft> */
    #[OneToMany(targetEntity: PaginatorRegressionLeft::class, mappedBy: 'root')]
    public $lefts;

    /** @phpstan-var Collection<int, PaginatorRegressionRight> */
    #[OneToMany(targetEntity: PaginatorRegressionRight::class, mappedBy: 'root')]
    public $rights;

    public function __construct()
    {
        $this->lefts  = new ArrayCollection();
        $this->rights = new ArrayCollection();
    }
}

#[Entity]
class PaginatorRegressionLeft
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var PaginatorRegressionRoot */
    #[ManyToOne(targetEntity: PaginatorRegressionRoot::class, inversedBy: 'lefts')]
    #[JoinColumn(name: 'root_id', nullable: false)]
    public $root;

    /** @var string */
    #[Column(type: 'string', length: 50)]
    public $label;
}

#[Entity]
class PaginatorRegressionRight
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var PaginatorRegressionRoot */
    #[ManyToOne(targetEntity: PaginatorRegressionRoot::class, inversedBy: 'rights')]
    #[JoinColumn(name: 'root_id', nullable: false)]
    public $root;

    /** @var string */
    #[Column(type: 'string', length: 50)]
    public $label;
}

#[Entity]
class PaginatorRegressionCategory
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var int */
    #[Column(type: 'integer')]
    public $position;
}

#[Entity]
class PaginatorRegressionOrganization
{
    /** @var int */
    #[Id]
    #[Column(name: 'organization_id', type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var string */
    #[Column(name: 'org_name', type: 'string', length: 50)]
    public $name;
}

#[Entity]
class PaginatorRegressionUser
{
    /** @var int */
    #[Id]
    #[Column(name: 'IDuser', type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var string */
    #[Column(name: 'username', type: 'string', length: 50)]
    public $username;

    /** @var string */
    #[Column(name: 'username_canonical', type: 'string', length: 50)]
    public $usernameCanonical;

    /** @var PaginatorRegressionOrganization|null */
    #[ManyToOne(targetEntity: PaginatorRegressionOrganization::class, fetch: 'EAGER')]
    #[JoinColumn(name: 'id', referencedColumnName: 'organization_id', nullable: true)]
    public $organization;
}
