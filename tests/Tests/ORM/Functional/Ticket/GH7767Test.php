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

#[Group('GH7767')]
class GH7767Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([GH7767ParentEntity::class, GH7767ChildEntity::class]);

        $parent = new GH7767ParentEntity();
        $parent->addChild(200);
        $parent->addChild(100);
        $parent->addChild(300);

        $this->_em->persist($parent);
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testMatchingRespectsCollectionOrdering(): void
    {
        $parent = $this->_em->find(GH7767ParentEntity::class, 1);
        assert($parent instanceof GH7767ParentEntity);

        $children = $parent->getChildren()->matching(Criteria::create());

        self::assertEquals(100, $children[0]->position);
        self::assertEquals(200, $children[1]->position);
        self::assertEquals(300, $children[2]->position);
    }

    public function testMatchingOverrulesCollectionOrdering(): void
    {
        $parent = $this->_em->find(GH7767ParentEntity::class, 1);
        assert($parent instanceof GH7767ParentEntity);

        $children = $parent->getChildren()->matching(
            Criteria::create()->orderBy(['position' => SortDirection::Descending]),
        );

        self::assertEquals(300, $children[0]->position);
        self::assertEquals(200, $children[1]->position);
        self::assertEquals(100, $children[2]->position);
    }
}

#[Entity]
class GH7767ParentEntity
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    private int $id;

    /** @phpstan-var Collection<int, GH7767ChildEntity>&Selectable<int, GH7767ChildEntity> */
    #[OneToMany(targetEntity: GH7767ChildEntity::class, mappedBy: 'parent', fetch: 'EXTRA_LAZY', cascade: ['persist'])]
    #[OrderBy(['position' => SortDirection::Ascending])]
    private $children;

    public function addChild(int $position): void
    {
        $this->children[] = new GH7767ChildEntity($this, $position);
    }

    /** @phpstan-return Collection<int, GH7767ChildEntity>&Selectable<int, GH7767ChildEntity> */
    public function getChildren(): Collection
    {
        return $this->children;
    }
}

#[Entity]
class GH7767ChildEntity
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    private int $id;

    public function __construct(
        #[ManyToOne(targetEntity: GH7767ParentEntity::class, inversedBy: 'children')]
        private GH7767ParentEntity $parent,
        #[Column(type: 'integer')]
        public int $position,
    ) {
    }
}
