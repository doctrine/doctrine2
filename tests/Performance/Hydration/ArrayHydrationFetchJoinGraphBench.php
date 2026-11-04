<?php

declare(strict_types=1);

namespace Doctrine\Performance\Hydration;

use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\ArrayHydrator;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Performance\EntityManagerFactory;
use Doctrine\Tests\Mocks\ArrayResultFactory;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsComment;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Generator;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;

use function gc_collect_cycles;
use function iterator_count;
use function memory_reset_peak_usage;

/**
 * Hydrates fetch-joined graphs of different shapes with the ArrayHydrator.
 *
 * Each iteration hydrates a new Result, because a Result is empty after
 * hydration. Revs must stay 1 and there must be no warmup for that reason.
 */
#[BeforeMethods('init')]
#[ParamProviders('provideShapes')]
#[Revs(1)]
#[Iterations(30)]
final class ArrayHydrationFetchJoinGraphBench
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $resultSets = [];

    private static EntityManagerInterface|null $entityManager = null;

    private ArrayHydrator $hydrator;

    private ResultSetMapping $rsm;

    private Result $result;

    /** @return Generator<string, array{shape: string, users: int, children: int, grandchildren: int}> */
    public function provideShapes(): Generator
    {
        yield 'root only' => ['shape' => 'root', 'users' => 20000, 'children' => 1, 'grandchildren' => 1];
        yield 'root + leaf collection' => ['shape' => 'leaf', 'users' => 2000, 'children' => 10, 'grandchildren' => 1];
        yield 'root + collection + nested collection' => ['shape' => 'deep', 'users' => 200, 'children' => 10, 'grandchildren' => 10];
        yield 'wide nested collection' => ['shape' => 'deep', 'users' => 20, 'children' => 10, 'grandchildren' => 100];
        yield 'mixed root + scalar + collection' => ['shape' => 'mixed', 'users' => 2000, 'children' => 10, 'grandchildren' => 1];
    }

    /** @param array{shape: string, users: int, children: int, grandchildren: int} $params */
    public function init(array $params): void
    {
        self::$entityManager ??= EntityManagerFactory::getEntityManager([]);

        $this->hydrator = new ArrayHydrator(self::$entityManager);
        $this->rsm      = $this->createResultSetMapping($params['shape']);
        $this->result   = ArrayResultFactory::createWrapperResultFromArray($this->getResultSet($params));

        gc_collect_cycles();
        memory_reset_peak_usage();
    }

    /** @param array{shape: string, users: int, children: int, grandchildren: int} $params */
    public function benchHydrateAll(array $params): void
    {
        $this->hydrator->hydrateAll($this->result, $this->rsm);
    }

    /** @param array{shape: string, users: int, children: int, grandchildren: int} $params */
    public function benchToIterable(array $params): void
    {
        iterator_count($this->hydrator->toIterable($this->result, $this->rsm));
    }

    private function createResultSetMapping(string $shape): ResultSetMapping
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addFieldResult('u', 'u__username', 'username');
        $rsm->addFieldResult('u', 'u__name', 'name');

        if ($shape === 'leaf' || $shape === 'mixed') {
            $rsm->addJoinedEntityResult(CmsPhonenumber::class, 'p', 'u', 'phonenumbers');
            $rsm->addFieldResult('p', 'p__phonenumber', 'phonenumber');
        }

        if ($shape === 'mixed') {
            $rsm->addScalarResult('sclr0', 'nameUpper');
        }

        if ($shape === 'deep') {
            $rsm->addJoinedEntityResult(CmsArticle::class, 'a', 'u', 'articles');
            $rsm->addFieldResult('a', 'a__id', 'id');
            $rsm->addFieldResult('a', 'a__topic', 'topic');
            $rsm->addFieldResult('a', 'a__text', 'text');
            $rsm->addJoinedEntityResult(CmsComment::class, 'c', 'a', 'comments');
            $rsm->addFieldResult('c', 'c__id', 'id');
            $rsm->addFieldResult('c', 'c__topic', 'topic');
            $rsm->addFieldResult('c', 'c__text', 'text');
        }

        return $rsm;
    }

    /**
     * @param array{shape: string, users: int, children: int, grandchildren: int} $params
     *
     * @return list<array<string, mixed>>
     */
    private function getResultSet(array $params): array
    {
        $key = $params['shape'] . '-' . $params['users'] . '-' . $params['children'] . '-' . $params['grandchildren'];

        if (isset(self::$resultSets[$key])) {
            return self::$resultSets[$key];
        }

        $rows      = [];
        $articleId = 0;
        $commentId = 0;

        for ($user = 1; $user <= $params['users']; ++$user) {
            $userColumns = [
                'u__id'       => (string) $user,
                'u__status'   => 'developer',
                'u__username' => 'user' . $user,
                'u__name'     => 'User ' . $user,
            ];

            if ($params['shape'] === 'root') {
                $rows[] = $userColumns;

                continue;
            }

            for ($child = 1; $child <= $params['children']; ++$child) {
                if ($params['shape'] === 'leaf') {
                    $rows[] = $userColumns + ['p__phonenumber' => (string) ($user * 100 + $child)];

                    continue;
                }

                if ($params['shape'] === 'mixed') {
                    $rows[] = $userColumns + [
                        'p__phonenumber' => (string) ($user * 100 + $child),
                        'sclr0'          => 'USER' . $user,
                    ];

                    continue;
                }

                ++$articleId;

                for ($grandchild = 1; $grandchild <= $params['grandchildren']; ++$grandchild) {
                    ++$commentId;

                    $rows[] = $userColumns + [
                        'a__id'    => (string) $articleId,
                        'a__topic' => 'Topic ' . $articleId,
                        'a__text'  => 'Text of article ' . $articleId,
                        'c__id'    => (string) $commentId,
                        'c__topic' => 'Comment ' . $commentId,
                        'c__text'  => 'Text of comment ' . $commentId,
                    ];
                }
            }
        }

        return self::$resultSets[$key] = $rows;
    }
}
