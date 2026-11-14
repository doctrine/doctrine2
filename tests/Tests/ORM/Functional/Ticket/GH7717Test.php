<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Tests\Models\GH7717\GH7717Child;
use Doctrine\Tests\Models\GH7717\GH7717Parent;
use Doctrine\Tests\OrmFunctionalTestCase;
use Override;

final class GH7717Test extends OrmFunctionalTestCase
{
    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            GH7717Parent::class,
            GH7717Child::class,
        );
    }

    public function testManyToManyPersisterIsNullComparison(): void
    {
        $childWithNullProperty                      = new GH7717Child();
        $childWithoutNullProperty                   = new GH7717Child();
        $childWithoutNullProperty->nullableProperty = 'nope';

        $parent           = new GH7717Parent();
        $parent->children = new ArrayCollection([$childWithNullProperty, $childWithoutNullProperty]);

        $this->_em->persist($parent);
        $this->_em->flush();
        $this->_em->clear();

        $parent = $this->_em->find(GH7717Parent::class, 1);

        $this->assertCount(1, $parent->children->matching(Criteria::create()->where(
            Criteria::expr()->isNull('nullableProperty'),
        )));
    }
}
