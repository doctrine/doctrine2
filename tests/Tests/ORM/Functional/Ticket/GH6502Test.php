<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\OrmFunctionalTestCase;
use Doctrine\Tests\TestUtil;
use PHPUnit\Framework\Attributes\Group;

use function array_keys;
use function serialize;
use function unserialize;

#[Group('GH6502')]
final class GH6502Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(GH6502Group::class, GH6502Person::class, GH6502Employee::class);
    }

    public function testPropertyOrderOfCachedSubclassMetadataMatchesFreshlyLoadedMetadata(): void
    {
        $fresh = $this->_em->getClassMetadata(GH6502Employee::class);

        $cached = unserialize(serialize($fresh));
        $cached->wakeupReflection(new RuntimeReflectionService());

        self::assertSame(array_keys($fresh->propertyAccessors), array_keys($cached->propertyAccessors));
    }

    public function testInsertWithCachedSubclassMetadataAndFreshRootMetadata(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([], true);
        TestUtil::configureProxies($config);
        $em = new EntityManager($this->_em->getConnection(), $config);

        $factory = $em->getMetadataFactory();
        $factory->getMetadataFor(GH6502Person::class);

        $cached = unserialize(serialize($factory->getMetadataFor(GH6502Employee::class)));
        $cached->wakeupReflection(new RuntimeReflectionService());
        $factory->setMetadataFor(GH6502Employee::class, $cached);

        $group = new GH6502Group();
        $em->persist($group);

        $employee        = new GH6502Employee();
        $employee->name  = 'John Smith';
        $employee->email = 'john@example.org';
        $employee->group = $group;
        $employee->extra = 'extra';
        $em->persist($employee);

        $em->flush();

        $row = $em->getConnection()->fetchAssociative(
            'SELECT name, email, group_id FROM gh6502_person WHERE id = ?',
            [$employee->id],
        );

        self::assertSame('John Smith', $row['name']);
        self::assertSame('john@example.org', $row['email']);
        self::assertEquals($group->id, $row['group_id']);
    }
}

#[Entity]
class GH6502Group
{
    #[Id]
    #[GeneratedValue]
    #[Column]
    public int|null $id = null;
}

#[MappedSuperclass]
abstract class GH6502AbstractPerson
{
    #[Id]
    #[GeneratedValue]
    #[Column]
    public int|null $id = null;

    #[Column]
    public string $name = '';

    #[ManyToOne]
    public GH6502Group|null $group = null;
}

#[Entity]
#[Table(name: 'gh6502_person')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'type', type: 'string')]
#[DiscriminatorMap(['person' => GH6502Person::class, 'employee' => GH6502Employee::class])]
class GH6502Person extends GH6502AbstractPerson
{
    #[Column]
    public string $email = '';
}

#[Entity]
#[Table(name: 'gh6502_employee')]
class GH6502Employee extends GH6502Person
{
    #[Column]
    public string $extra = '';
}
