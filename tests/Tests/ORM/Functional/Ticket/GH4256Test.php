<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use SortDirection;

use function array_map;
use function iterator_to_array;

final class GH4256Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH4256Category::class,
            GH4256Attribute::class,
            GH4256Tag::class,
        ]);

        $category = new GH4256Category(1);
        $this->_em->persist($category);

        // Insert in an order that differs from the position order
        foreach ([1 => 2, 2 => 3, 3 => 1] as $id => $position) {
            $this->_em->persist(new GH4256Attribute($id, $category, $position));

            $tag = new GH4256Tag($id, $position);
            $this->_em->persist($tag);
            $category->tags->add($tag);
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    protected function tearDown(): void
    {
        $conn = $this->_em->getConnection();

        $conn->executeStatement('DELETE FROM gh4256_category_tag');
        $conn->executeStatement('DELETE FROM GH4256Tag');
        $conn->executeStatement('DELETE FROM GH4256Attribute');
        $conn->executeStatement('DELETE FROM GH4256Category');

        parent::tearDown();
    }

    public function testFindOrdersEagerCollections(): void
    {
        $category = $this->_em->find(GH4256Category::class, 1);

        self::assertNotNull($category);
        self::assertTrue($category->attributes->isInitialized());
        self::assertSame([1, 2, 3], self::positions($category->attributes));
        self::assertTrue($category->tags->isInitialized());
        self::assertSame([1, 2, 3], self::positions($category->tags));
    }

    public function testIterationOrdersEagerCollections(): void
    {
        $query      = $this->_em->createQuery('SELECT c FROM ' . GH4256Category::class . ' c');
        $categories = iterator_to_array($query->toIterable(), false);

        self::assertCount(1, $categories);
        self::assertTrue($categories[0]->attributes->isInitialized());
        self::assertSame([1, 2, 3], self::positions($categories[0]->attributes));
        self::assertTrue($categories[0]->tags->isInitialized());
        self::assertSame([1, 2, 3], self::positions($categories[0]->tags));
    }

    /**
     * @param Collection<int, GH4256Attribute|GH4256Tag> $collection
     *
     * @return list<int>
     */
    private static function positions(Collection $collection): array
    {
        return array_map(
            static fn (GH4256Attribute|GH4256Tag $element): int => $element->position,
            $collection->getValues(),
        );
    }
}

#[ORM\Entity]
class GH4256Category
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    /** @var Collection<int, GH4256Attribute> */
    #[ORM\OneToMany(targetEntity: GH4256Attribute::class, mappedBy: 'category', fetch: 'EAGER')]
    #[ORM\OrderBy(['position' => SortDirection::Ascending])]
    public Collection $attributes;

    /** @var Collection<int, GH4256Tag> */
    #[ORM\ManyToMany(targetEntity: GH4256Tag::class, fetch: 'EAGER')]
    #[ORM\JoinTable(name: 'gh4256_category_tag')]
    #[ORM\OrderBy(['position' => SortDirection::Ascending])]
    public Collection $tags;

    public function __construct(int $id)
    {
        $this->id         = $id;
        $this->attributes = new ArrayCollection();
        $this->tags       = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH4256Attribute
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(inversedBy: 'attributes')]
    #[ORM\JoinColumn(nullable: false)]
    public GH4256Category $category;

    #[ORM\Column]
    public int $position;

    public function __construct(int $id, GH4256Category $category, int $position)
    {
        $this->id       = $id;
        $this->category = $category;
        $this->position = $position;
    }
}

#[ORM\Entity]
class GH4256Tag
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\Column]
    public int $position;

    public function __construct(int $id, int $position)
    {
        $this->id       = $id;
        $this->position = $position;
    }
}
