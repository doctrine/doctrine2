<?php
declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\ValueGenerators\AssociationIdentifier;
use Doctrine\Tests\Models\ValueGenerators\AssociationIdentifierTarget;
use Doctrine\Tests\Models\ValueGenerators\BarGenerator;
use Doctrine\Tests\Models\ValueGenerators\CompositeGeneratedIdentifier;
use Doctrine\Tests\Models\ValueGenerators\FooGenerator;
use Doctrine\Tests\Models\ValueGenerators\InheritanceGeneratorsChildA;
use Doctrine\Tests\Models\ValueGenerators\InheritanceGeneratorsChildB;
use Doctrine\Tests\Models\ValueGenerators\InheritanceGeneratorsRoot;
use Doctrine\Tests\Models\ValueGenerators\NonIdentifierGenerators;
use Doctrine\Tests\OrmFunctionalTestCase;

class ValueGeneratorsTest extends OrmFunctionalTestCase
{

    public function setUp()
    {
        $this->useModelSet('valueGenerators');
        parent::setUp();
    }

    public function testCompositeIdentifierWithMultipleGenerators() : void
    {
        $entity = new CompositeGeneratedIdentifier();
        $this->em->persist($entity);
        $this->em->flush();

        self::assertSame(FooGenerator::VALUE, $entity->getA());
        self::assertSame(BarGenerator::VALUE, $entity->getB());

        $this->em->clear();

        $entity = $this->getEntityManager()->find(
            CompositeGeneratedIdentifier::class,
            ['a' => FooGenerator::VALUE, 'b' => BarGenerator::VALUE]
        );
        self::assertNotNull($entity);
    }

    public function testNonIdentifierGenerators() : void
    {
        $entity = new NonIdentifierGenerators();

        $this->em->persist($entity);
        $this->em->flush();

        self::assertNotNull($entity->getId());
        self::assertSame(FooGenerator::VALUE, $entity->getFoo());
        self::assertSame(BarGenerator::VALUE, $entity->getBar());

        $this->em->clear();

        $entity = $this->getEntityManager()->find(NonIdentifierGenerators::class, $entity->getId());
        self::assertNotNull($entity);
    }

    public function testValueGeneratorsInInheritance() : void
    {
        $rootEntity = new InheritanceGeneratorsRoot();

        $this->em->persist($rootEntity);
        $this->em->flush();

        $this->assertNotNull($rootEntity->getId());

        $childAEntity = new InheritanceGeneratorsChildA();

        $this->em->persist($childAEntity);
        $this->em->flush();

        $this->assertNotNull($childAEntity);
        $this->assertSame(FooGenerator::VALUE, $childAEntity->getA());

        $childBEntity = new InheritanceGeneratorsChildB();

        $this->em->persist($childBEntity);
        $this->em->flush();

        $this->assertNotNull($childBEntity);
        $this->assertSame(FooGenerator::VALUE, $childBEntity->getA());
        $this->assertSame(BarGenerator::VALUE, $childBEntity->getB());
    }

    public function testGeneratorsWithAssociationInIdentifier() : void
    {
        $entity = new AssociationIdentifier();

        $this->em->persist($entity);
        $this->em->flush();

        $this->assertSame(FooGenerator::VALUE, $entity->getId());
        $this->assertSame(BarGenerator::VALUE, $entity->getRegular());

        $entity = $this->em->find(
            AssociationIdentifier::class,
            ['id' => FooGenerator::VALUE, 'target' => AssociationIdentifierTarget::ID]
        );

        $this->assertNotNull($entity);
    }
}
