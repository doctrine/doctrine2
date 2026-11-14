<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use SortDirection;

use function assert;

#[Group('GH7836')]
class GH7836Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([GH7836ParentEntity::class, GH7836ChildEntity::class]);

        $parent = new GH7836ParentEntity();
        $parent->addChild(100, 'foo');
        $parent->addChild(100, 'bar');
        $parent->addChild(200, 'baz');

        $this->_em->persist($parent);
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testMatchingRespectsCollectionOrdering(): void
    {
        $parent = $this->_em->find(GH7836ParentEntity::class, 1);
        assert($parent instanceof GH7836ParentEntity);

        $children = $parent->getChildren()->matching(Criteria::create());

        self::assertSame(100, $children[0]->position);
        self::assertSame('bar', $children[0]->name);
        self::assertSame(100, $children[1]->position);
        self::assertSame('foo', $children[1]->name);
        self::assertSame(200, $children[2]->position);
        self::assertSame('baz', $children[2]->name);
    }

    public function testMatchingOverrulesCollectionOrdering(): void
    {
        $parent = $this->_em->find(GH7836ParentEntity::class, 1);
        assert($parent instanceof GH7836ParentEntity);

        $children = $parent->getChildren()->matching(
            Criteria::create()->orderBy(['position' => SortDirection::Descending, 'name' => SortDirection::Ascending]),
        );

        self::assertSame(200, $children[0]->position);
        self::assertSame('baz', $children[0]->name);
        self::assertSame(100, $children[1]->position);
        self::assertSame('bar', $children[1]->name);
        self::assertSame(100, $children[2]->position);
        self::assertSame('foo', $children[2]->name);
    }

    public function testMatchingKeepsOrderOfCriteriaOrderingKeys(): void
    {
        $parent = $this->_em->find(GH7836ParentEntity::class, 1);
        assert($parent instanceof GH7836ParentEntity);

        $children = $parent->getChildren()->matching(
            Criteria::create()->orderBy(
                ['name' => SortDirection::Ascending, 'position' => SortDirection::Ascending],
            ),
        );

        self::assertSame(100, $children[0]->position);
        self::assertSame('bar', $children[0]->name);
        self::assertSame(200, $children[1]->position);
        self::assertSame('baz', $children[1]->name);
        self::assertSame(100, $children[2]->position);
        self::assertSame('foo', $children[2]->name);
    }
}

#[Entity]
class GH7836ParentEntity
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    private int $id;

    /** @var Collection<int, GH7836ChildEntity>&Selectable<int, GH7836ChildEntity> */
    #[OneToMany(targetEntity: GH7836ChildEntity::class, mappedBy: 'parent', fetch: 'EXTRA_LAZY', cascade: ['persist'])]
    #[OrderBy(['position' => SortDirection::Ascending, 'name' => SortDirection::Ascending])]
    private $children;

    public function addChild(int $position, string $name): void
    {
        $this->children[] = new GH7836ChildEntity($this, $position, $name);
    }

    /** @phpstan-return Collection<int, GH7836ChildEntity>&Selectable<int, GH7836ChildEntity> */
    public function getChildren(): Collection
    {
        return $this->children;
    }
}

#[Entity]
class GH7836ChildEntity
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    private int $id;

    public function __construct(
        #[ManyToOne(targetEntity: GH7836ParentEntity::class, inversedBy: 'children')]
        private GH7836ParentEntity $parent,
        #[Column(type: 'integer')]
        public int $position,
        #[Column(type: 'string', length: 255)]
        public string $name,
    ) {
    }
}
