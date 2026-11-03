<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

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
use PHPUnit\Framework\Attributes\Group;

#[Group('GH12697')]
final class GH12697Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(GH12697Parent::class, GH12697Child::class);

        for ($i = 0; $i < 2; $i++) {
            $parent = new GH12697Parent();
            $this->_em->persist($parent);

            foreach (['b' => 0, 'a' => 1] as $name => $score) {
                $child         = new GH12697Child();
                $child->parent = $parent;
                $child->name   = $name;
                $child->score  = $score;
                $this->_em->persist($child);
            }
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->_em)) {
            $connection = $this->_em->getConnection();
            $connection->executeStatement('DELETE FROM GH12697Child');
            $connection->executeStatement('DELETE FROM GH12697Parent');
        }

        parent::tearDown();
    }

    public function testPaginatorWithNestedSubSelect(): void
    {
        $dql = 'SELECT p FROM ' . GH12697Parent::class . ' p'
            . ' LEFT JOIN p.children c'
            . ' WHERE p IN ('
            . '   SELECT p2 FROM ' . GH12697Parent::class . ' p2'
            . '   LEFT JOIN p2.children c2'
            . '   WHERE NOT EXISTS ('
            . '     SELECT 1 FROM ' . GH12697Child::class . ' x'
            . '     WHERE x.parent = p2 AND x.score > c2.score'
            . '   )'
            . '   AND c2.name = :name'
            . ' )'
            . ' ORDER BY c.name ASC';

        $query = $this->_em->createQuery($dql)->setParameter('name', 'a');

        $page = (new OffsetPaginator(true))->paginate($query, new Window(0, 15));

        self::assertCount(2, $page);
    }
}

#[Entity]
class GH12697Parent
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @phpstan-var Collection<int, GH12697Child> */
    #[OneToMany(targetEntity: GH12697Child::class, mappedBy: 'parent')]
    public $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
}

#[Entity]
class GH12697Child
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var GH12697Parent */
    #[ManyToOne(targetEntity: GH12697Parent::class, inversedBy: 'children')]
    #[JoinColumn(name: 'parent_id', nullable: false)]
    public $parent;

    /** @var string */
    #[Column(type: 'string', length: 50)]
    public $name;

    /** @var int */
    #[Column(type: 'integer')]
    public $score;
}
