<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\Tests\OrmFunctionalTestCase;

class GH12174Test extends OrmFunctionalTestCase
{
    use VerifyDeprecations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/10431'); // make test fail on 2.x; in 3.x, it would even throw

        $resolveTargetEntity = new ResolveTargetEntityListener();

        $resolveTargetEntity->addResolveTargetEntity(GH12174Smurf::class, GH12174BlueSmurf::class, []);

        $this->_em->getEventManager()->addEventSubscriber($resolveTargetEntity);

        $this->createSchemaForModels(
            GH12174Smurf::class,
            GH12174BlueSmurf::class,
            GH12174PapaSmurf::class,
        );
    }

    public function testMappedSuperclassNameCanBeUsedToResolveTargetEntityClass(): void
    {
        $smurf = $this->_em->getClassMetadata(GH12174Smurf::class);
        self::assertTrue($smurf->isMappedSuperclass);
        self::assertSame(GH12174Smurf::class, $smurf->getName());
        self::assertSame(GH12174BlueSmurf::class, $smurf->getAssociationMapping('children')->targetEntity);

        $blue = $this->_em->getClassMetadata(GH12174BlueSmurf::class);
        self::assertFalse($blue->isMappedSuperclass);
        self::assertSame(GH12174BlueSmurf::class, $blue->getName());

        $papa = $this->_em->getClassMetadata(GH12174PapaSmurf::class);
        self::assertFalse($papa->isMappedSuperclass);
        self::assertSame(GH12174PapaSmurf::class, $papa->getName());
    }
}

#[ORM\MappedSuperclass]
class GH12174Smurf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public int $id;

    #[ManyToOne(inversedBy: 'children', targetEntity: self::class)]
    private GH12174Smurf $parent;

    /** @var Collection<self::class> */
    #[OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;
}

#[ORM\Entity]
class GH12174BlueSmurf extends GH12174Smurf
{
}

#[ORM\Entity]
class GH12174PapaSmurf extends GH12174Smurf
{
}
