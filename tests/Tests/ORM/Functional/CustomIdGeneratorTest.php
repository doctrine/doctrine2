<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\TestWith;

class CustomIdGeneratorTest extends OrmFunctionalTestCase
{
    #[TestWith([CustomGeneratedEntity::class])]
    #[TestWith([CustomGeneratedChild::class])]
    #[TestWith([CustomGeneratedInheritanceChild::class])]
    public function testCustomGeneratorWithoutStrategy(string $entityClass): void
    {
        $metadata = $this->_em->getClassMetadata($entityClass);
        $this->createSchemaForModels($metadata->rootEntityName);

        $entity = new $entityClass();
        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        self::assertInstanceOf(FixedIdGenerator::class, $metadata->idGenerator);
        self::assertSame(['id' => 42], $metadata->getIdentifierValues($entity));
        self::assertInstanceOf($entityClass, $this->_em->find($entityClass, 42));
    }
}

class FixedIdGenerator extends AbstractIdGenerator
{
    public function generateId(EntityManagerInterface $em, object|null $entity): int
    {
        return 42;
    }
}

#[ORM\Entity]
class CustomGeneratedEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\CustomIdGenerator(FixedIdGenerator::class)]
    public int|null $id = null;
}

#[ORM\MappedSuperclass]
abstract class CustomGeneratedMappedSuperclass
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\CustomIdGenerator(FixedIdGenerator::class)]
    private int|null $id = null;
}

#[ORM\Entity]
class CustomGeneratedChild extends CustomGeneratedMappedSuperclass
{
}

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['parent' => CustomGeneratedInheritanceParent::class, 'child' => CustomGeneratedInheritanceChild::class])]
class CustomGeneratedInheritanceParent
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\CustomIdGenerator(FixedIdGenerator::class)]
    public int|null $id = null;
}

#[ORM\Entity]
class CustomGeneratedInheritanceChild extends CustomGeneratedInheritanceParent
{
}
