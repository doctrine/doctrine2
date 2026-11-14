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
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\OrmFunctionalTestCase;
use Doctrine\Tests\TestUtil;
use PHPUnit\Framework\Attributes\Group;

use function serialize;
use function unserialize;

#[Group('GH6502')]
final class GH6502Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            GH6502Group::class,
            GH6502Person::class,
            GH6502Employee::class,
            GH6502Department::class,
            GH6502Manager::class,
        );
    }

    public function testInsertWithCachedSubclassMetadataAndFreshRootMetadata(): void
    {
        $em = $this->createEntityManagerWithCachedMetadataFor(GH6502Employee::class, GH6502Person::class);

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

    public function testInsertWithCachedSubclassMetadataAndFreshIntermediateMetadata(): void
    {
        $em = $this->createEntityManagerWithCachedMetadataFor(GH6502Manager::class, GH6502Employee::class);

        $group = new GH6502Group();
        $em->persist($group);

        $department = new GH6502Department();
        $em->persist($department);

        $manager             = new GH6502Manager();
        $manager->name       = 'Jane Doe';
        $manager->email      = 'jane@example.org';
        $manager->group      = $group;
        $manager->department = $department;
        $manager->extra      = 'extra';
        $manager->level      = 'senior';
        $em->persist($manager);

        $em->flush();

        $row = $em->getConnection()->fetchAssociative(
            'SELECT extra, department_id FROM gh6502_employee WHERE id = ?',
            [$manager->id],
        );

        self::assertSame('extra', $row['extra']);
        self::assertEquals($department->id, $row['department_id']);
    }

    public function testInsertWithSameJoinColumnNameInParentAndChildTable(): void
    {
        $this->createSchemaForModels(GH6502Owner::class, GH6502OwnedRoot::class, GH6502OwnedChild::class);

        $parentOwner = new GH6502Owner();
        $childOwner  = new GH6502Owner();
        $this->_em->persist($parentOwner);
        $this->_em->persist($childOwner);

        $child              = new GH6502OwnedChild();
        $child->parentOwner = $parentOwner;
        $child->childOwner  = $childOwner;
        $child->label       = 'label';
        $this->_em->persist($child);

        $this->_em->flush();

        $row = $this->_em->getConnection()->fetchAssociative(
            'SELECT label, owner_id FROM gh6502_owned_child WHERE id = ?',
            [$child->id],
        );

        self::assertSame('label', $row['label']);
        self::assertEquals($childOwner->id, $row['owner_id']);
    }

    /** @param class-string ...$freshClasses */
    private function createEntityManagerWithCachedMetadataFor(string $cachedClass, string ...$freshClasses): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig([], true);
        TestUtil::configureProxies($config);
        $em = new EntityManager($this->_em->getConnection(), $config);

        $factory = $em->getMetadataFactory();

        foreach ($freshClasses as $freshClass) {
            $factory->getMetadataFor($freshClass);
        }

        $cached = unserialize(serialize($factory->getMetadataFor($cachedClass)));
        $cached->wakeupReflection(new RuntimeReflectionService());
        $factory->setMetadataFor($cachedClass, $cached);

        return $em;
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
#[DiscriminatorMap([
    'person' => GH6502Person::class,
    'employee' => GH6502Employee::class,
    'manager' => GH6502Manager::class,
])]
class GH6502Person extends GH6502AbstractPerson
{
    #[Column]
    public string $email = '';
}

#[Entity]
class GH6502Department
{
    #[Id]
    #[GeneratedValue]
    #[Column]
    public int|null $id = null;
}

#[MappedSuperclass]
abstract class GH6502AbstractEmployee extends GH6502Person
{
    #[ManyToOne]
    public GH6502Department|null $department = null;
}

#[Entity]
#[Table(name: 'gh6502_employee')]
class GH6502Employee extends GH6502AbstractEmployee
{
    #[Column]
    public string $extra = '';
}

#[Entity]
#[Table(name: 'gh6502_manager')]
class GH6502Manager extends GH6502Employee
{
    #[Column]
    public string $level = '';
}

#[Entity]
class GH6502Owner
{
    #[Id]
    #[GeneratedValue]
    #[Column]
    public int|null $id = null;
}

#[Entity]
#[Table(name: 'gh6502_owned_root')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'type', type: 'string')]
#[DiscriminatorMap(['root' => GH6502OwnedRoot::class, 'child' => GH6502OwnedChild::class])]
class GH6502OwnedRoot
{
    #[Id]
    #[GeneratedValue]
    #[Column]
    public int|null $id = null;

    #[ManyToOne]
    #[JoinColumn(name: 'owner_id')]
    public GH6502Owner|null $parentOwner = null;
}

#[Entity]
#[Table(name: 'gh6502_owned_child')]
class GH6502OwnedChild extends GH6502OwnedRoot
{
    #[Column]
    public string $label = '';

    #[ManyToOne]
    #[JoinColumn(name: 'owner_id')]
    public GH6502Owner|null $childOwner = null;
}
