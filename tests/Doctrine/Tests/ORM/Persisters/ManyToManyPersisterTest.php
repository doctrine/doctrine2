<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Persisters;

use Doctrine\ORM\ORMException;
use Doctrine\ORM\Persisters\Collection\ManyToManyPersister;
use Doctrine\Tests\Mocks\ConnectionMock;
use Doctrine\Tests\Models\ManyToManyPersister\ChildClass;
use Doctrine\Tests\Models\ManyToManyPersister\OtherParentClass;
use Doctrine\Tests\Models\ManyToManyPersister\ParentClass;
use Doctrine\Tests\OrmTestCase;
use function array_pop;

/**
 * @covers \Doctrine\ORM\Persisters\Collection\ManyToManyPersister
 */
final class ManyToManyPersisterTest extends OrmTestCase
{
    /**
     * @group 6991
     * @group ManyToManyPersister
     *
     * @throws ORMException
     */
    public function testDeleteManyToManyCollection() : void
    {
        $parent      = new ParentClass(1);
        $otherParent = new OtherParentClass(42);
        $child       = new ChildClass(1, $otherParent);

        $parent->children->add($child);
        $child->parents->add($parent);

        $em = $this->getTestEntityManager();
        $em->persist($parent);
        $em->flush();

        /** @var ChildClass|null $childReloaded */
        $childReloaded = $em->find(ChildClass::class, ['id1' => 1, 'otherParent' => $otherParent]);

        self::assertNotNull($childReloaded);

        $persister = new ManyToManyPersister($em);
        $persister->delete($childReloaded->parents);

        /** @var ConnectionMock $conn */
        $conn = $em->getConnection();

        $updates    = $conn->getExecuteUpdates();
        $lastUpdate = array_pop($updates);

        self::assertEquals('DELETE FROM "parent_child" WHERE "child_id1" = ? AND "child_id2" = ?', $lastUpdate['query']);
        self::assertEquals([1, 42], $lastUpdate['params']);
    }
}
