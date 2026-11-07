<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('GH-6999')]
class GH6999Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(GH6999User::class, GH6999Access::class);
    }

    public function testReplacingCollectionWithSameElementsDoesNotRemoveThemAsOrphans(): void
    {
        $user = new GH6999User();
        $user->accesses->add(new GH6999Access($user));
        $user->accesses->add(new GH6999Access($user));
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->find(GH6999User::class, $user->id);
        self::assertInstanceOf(GH6999User::class, $user);

        $user->accesses = new ArrayCollection($user->accesses->toArray());
        $this->_em->flush();
        $this->_em->clear();

        $count = $this->_em->createQuery('SELECT COUNT(a) FROM ' . GH6999Access::class . ' a')
            ->getSingleScalarResult();

        self::assertSame(2, (int) $count);
    }
}

#[ORM\Entity]
class GH6999User
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    /** @var Collection<int, GH6999Access> */
    #[ORM\OneToMany(targetEntity: GH6999Access::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $accesses;

    public function __construct()
    {
        $this->accesses = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH6999Access
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: GH6999User::class, inversedBy: 'accesses')]
        public GH6999User $user,
    ) {
    }
}
