<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function array_column;
use function array_map;
use function iterator_to_array;

#[Group('GH8390')]
final class GH8390Test extends OrmFunctionalTestCase
{
    private const ROOTS             = 2;
    private const CHILDREN_PER_ROOT = 3;
    private const TAGS              = 2;

    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(GH8390CountingType::NAME, GH8390CountingType::class);

        $this->createSchemaForModels(GH8390Root::class, GH8390Child::class, GH8390Tag::class);

        $tags = [];
        for ($tagId = 1; $tagId <= self::TAGS; $tagId++) {
            $tags[$tagId] = new GH8390Tag($tagId);
            $this->_em->persist($tags[$tagId]);
        }

        $childId = 1;
        for ($rootId = 1; $rootId <= self::ROOTS; $rootId++) {
            $root = new GH8390Root($rootId);
            $this->_em->persist($root);

            foreach ($tags as $tag) {
                $root->tags->add($tag);
            }

            for ($i = 0; $i < self::CHILDREN_PER_ROOT; $i++) {
                $this->_em->persist(new GH8390Child($childId, $root, $tags[$childId % self::TAGS + 1]));
                $childId++;
            }
        }

        $this->_em->persist(new GH8390Root(self::ROOTS + 1));

        $this->_em->flush();
        $this->_em->clear();

        GH8390CountingType::$conversions = 0;
    }

    protected function tearDown(): void
    {
        $connection = $this->_em->getConnection();

        foreach (['gh8390_root_tag', 'gh8390_child', 'gh8390_root', 'gh8390_tag'] as $table) {
            $connection->executeStatement('DELETE FROM ' . $table);
        }

        parent::tearDown();
    }

    public function testObjectHydrationConvertsRepeatedRootOnce(): void
    {
        $roots = $this->_em
            ->createQuery('SELECT r, c FROM ' . GH8390Root::class . ' r JOIN r.children c')
            ->getResult();

        self::assertCount(self::ROOTS, $roots);
        self::assertSame(self::ROOTS + self::ROOTS * self::CHILDREN_PER_ROOT, GH8390CountingType::$conversions);
    }

    public function testArrayHydrationConvertsRepeatedRootOnce(): void
    {
        $roots = $this->_em
            ->createQuery('SELECT r, c FROM ' . GH8390Root::class . ' r JOIN r.children c ORDER BY r.id, c.id')
            ->getArrayResult();

        self::assertSame(self::ROOTS + self::ROOTS * self::CHILDREN_PER_ROOT, GH8390CountingType::$conversions);
        self::assertSame(['doc-1', 'doc-2'], array_column($roots, 'doc'));
        self::assertSame([1, 2, 3], array_column($roots[0]['children'], 'id'));
        self::assertSame([4, 5, 6], array_column($roots[1]['children'], 'id'));
    }

    public function testArrayHydrationConvertsRepeatedToOneOncePerParent(): void
    {
        $roots = $this->_em
            ->createQuery('SELECT c, r FROM ' . GH8390Child::class . ' c JOIN c.root r ORDER BY c.id')
            ->getArrayResult();

        self::assertCount(self::ROOTS * self::CHILDREN_PER_ROOT, $roots);
        self::assertSame(2 * self::ROOTS * self::CHILDREN_PER_ROOT, GH8390CountingType::$conversions);
        self::assertSame('doc-1', $roots[0]['root']['doc']);
        self::assertSame('doc-2', $roots[5]['root']['doc']);
    }

    public function testArrayHydrationDoesNotConvertEmptyLeftJoins(): void
    {
        $roots = $this->_em
            ->createQuery('SELECT r, c FROM ' . GH8390Root::class . ' r LEFT JOIN r.children c WHERE r.id = :id')
            ->setParameter('id', self::ROOTS + 1)
            ->getArrayResult();

        self::assertSame(1, GH8390CountingType::$conversions);
        self::assertSame([], $roots[0]['children']);
    }

    public function testArrayHydrationIteratesJoinedToOneEntities(): void
    {
        $children = iterator_to_array($this->_em
            ->createQuery('SELECT c, t FROM ' . GH8390Child::class . ' c JOIN c.tag t ORDER BY c.id')
            ->toIterable([], Query::HYDRATE_ARRAY), false);

        self::assertCount(self::ROOTS * self::CHILDREN_PER_ROOT, $children);
        self::assertSame('doc-2', $children[0]['tag']['doc']);
        self::assertSame('doc-1', $children[1]['tag']['doc']);
    }

    public function testObjectHydrationConvertsToOneSharedByParentsOnce(): void
    {
        $roots = $this->_em
            ->createQuery('SELECT r, c, t FROM ' . GH8390Root::class . ' r JOIN r.children c JOIN c.tag t ORDER BY c.id')
            ->getResult();

        self::assertSame(
            self::ROOTS + self::ROOTS * self::CHILDREN_PER_ROOT + self::TAGS,
            GH8390CountingType::$conversions,
        );

        $children = $roots[0]->children->toArray();

        self::assertSame($children[0]->tag, $children[2]->tag);
        self::assertNotSame($children[0]->tag, $children[1]->tag);
        self::assertSame($children[1]->tag, $roots[1]->children->first()->tag);
        self::assertSame([2, 1, 2], array_map(static fn (GH8390Child $child): int => $child->tag->id, $children));
    }

    public function testObjectHydrationConvertsManyToManyElementSharedByParentsOnce(): void
    {
        $roots = $this->_em
            ->createQuery('SELECT r, t FROM ' . GH8390Root::class . ' r JOIN r.tags t')
            ->getResult();

        self::assertSame(self::ROOTS + self::TAGS, GH8390CountingType::$conversions);
        self::assertCount(self::TAGS, $roots[0]->tags);
        self::assertCount(self::TAGS, $roots[1]->tags);
        self::assertSame($roots[0]->tags->first(), $roots[1]->tags->first());
    }

    public function testObjectHydrationInitializesProxyOfSharedJoinedEntity(): void
    {
        $proxy = $this->_em->getReference(GH8390Tag::class, 1);

        $this->_em
            ->createQuery('SELECT c, t FROM ' . GH8390Child::class . ' c JOIN c.tag t')
            ->getResult();

        self::assertFalse($this->_em->getUnitOfWork()->isUninitializedObject($proxy));
        self::assertSame('doc-1', $proxy->doc);
    }

    public function testIterationDoesNotReuseEntitiesAfterClear(): void
    {
        $query = $this->_em->createQuery('SELECT c, t FROM ' . GH8390Child::class . ' c JOIN c.tag t ORDER BY c.id');

        $seen = 0;
        foreach ($query->toIterable() as $child) {
            self::assertTrue($this->_em->contains($child->tag));
            self::assertSame($child->id % self::TAGS + 1, $child->tag->id);

            $seen++;
            $this->_em->clear();
        }

        self::assertSame(self::ROOTS * self::CHILDREN_PER_ROOT, $seen);
    }

    public function testRefreshHintRefreshesSharedJoinedEntity(): void
    {
        $tag = $this->_em->find(GH8390Tag::class, 1);

        $this->_em->getConnection()->executeStatement('UPDATE gh8390_tag SET doc = ? WHERE id = ?', ['changed', 1]);

        $this->_em
            ->createQuery('SELECT c, t FROM ' . GH8390Child::class . ' c JOIN c.tag t')
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        self::assertSame('changed', $tag->doc);
    }
}

class GH8390CountingType extends StringType
{
    public const NAME = 'gh8390_counting';

    public static int $conversions = 0;

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        self::$conversions++;

        return parent::convertToPHPValue($value, $platform);
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh8390_root')]
class GH8390Root
{
    #[ORM\Column(type: GH8390CountingType::NAME)]
    public string $doc;

    /** @var Collection<int, GH8390Child> */
    #[ORM\OneToMany(targetEntity: GH8390Child::class, mappedBy: 'root')]
    public Collection $children;

    /** @var Collection<int, GH8390Tag> */
    #[ORM\ManyToMany(targetEntity: GH8390Tag::class)]
    #[ORM\JoinTable(name: 'gh8390_root_tag')]
    public Collection $tags;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
    ) {
        $this->doc      = 'doc-' . $id;
        $this->children = new ArrayCollection();
        $this->tags     = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh8390_child')]
class GH8390Child
{
    #[ORM\Column(type: GH8390CountingType::NAME)]
    public string $doc;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\ManyToOne(targetEntity: GH8390Root::class, inversedBy: 'children')]
        public GH8390Root $root,
        #[ORM\ManyToOne(targetEntity: GH8390Tag::class)]
        public GH8390Tag $tag,
    ) {
        $this->doc = 'doc-' . $id;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh8390_tag')]
class GH8390Tag
{
    #[ORM\Column(type: GH8390CountingType::NAME)]
    public string $doc;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
    ) {
        $this->doc = 'doc-' . $id;
    }
}
