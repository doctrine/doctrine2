<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\ORM\ORMInvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

use function spl_object_id;
use function uniqid;

/**
 * @covers \Doctrine\ORM\ORMInvalidArgumentException
 */
class ORMInvalidArgumentExceptionTest extends TestCase
{
    /**
     * @param mixed $value
     *
     * @dataProvider invalidEntityNames
     */
    public function testInvalidEntityName($value, string $expectedMessage): void
    {
        $exception = ORMInvalidArgumentException::invalidEntityName($value);

        self::assertInstanceOf(ORMInvalidArgumentException::class, $exception);
        self::assertSame($expectedMessage, $exception->getMessage());
    }

    /**
     * @return string[][]
     */
    public function invalidEntityNames(): array
    {
        return [
            [null, 'Entity name must be a string, NULL given'],
            [true, 'Entity name must be a string, boolean given'],
            [123, 'Entity name must be a string, integer given'],
            [123.45, 'Entity name must be a string, double given'],
            [new stdClass(), 'Entity name must be a string, object given'],
        ];
    }

    /**
     * @dataProvider newEntitiesFoundThroughRelationshipsErrorMessages
     */
    public function testNewEntitiesFoundThroughRelationships(array $newEntities, string $expectedMessage): void
    {
        $exception = ORMInvalidArgumentException::newEntitiesFoundThroughRelationships($newEntities);

        self::assertInstanceOf(ORMInvalidArgumentException::class, $exception);
        self::assertSame($expectedMessage, $exception->getMessage());
    }

    public function newEntitiesFoundThroughRelationshipsErrorMessages(): array
    {
        $stringEntity3 = uniqid('entity3', true);
        $entity1       = new stdClass();
        $entity2       = new stdClass();
        $entity3       = $this->getMockBuilder(stdClass::class)->setMethods(['__toString'])->getMock();
        $association1  = [
            'sourceEntity' => 'foo1',
            'fieldName'    => 'bar1',
            'targetEntity' => 'baz1',
        ];
        $association2  = [
            'sourceEntity' => 'foo2',
            'fieldName'    => 'bar2',
            'targetEntity' => 'baz2',
        ];
        $association3  = [
            'sourceEntity' => 'foo3',
            'fieldName'    => 'bar3',
            'targetEntity' => 'baz3',
        ];

        $entity3->expects(self::any())->method('__toString')->willReturn($stringEntity3);

        return [
            'one entity found' => [
                [
                    [
                        $association1,
                        $entity1,
                    ],
                ],
                'A new entity was found through the relationship \'foo1#bar1\' that was not configured to cascade '
                . 'persist operations for entity: stdClass@' . spl_object_id($entity1)
                . '. To solve this issue: Either explicitly call EntityManager#persist() on this unknown entity '
                . 'or configure cascade persist this association in the mapping for example '
                . '@ManyToOne(..,cascade={"persist"}). If you cannot find out which entity causes the problem '
                . 'implement \'baz1#__toString()\' to get a clue.',
            ],
            'two entities found' => [
                [
                    [
                        $association1,
                        $entity1,
                    ],
                    [
                        $association2,
                        $entity2,
                    ],
                ],
                'Multiple non-persisted new entities were found through the given association graph:' . "\n\n"
                . ' * A new entity was found through the relationship \'foo1#bar1\' that was not configured to '
                . 'cascade persist operations for entity: stdClass@' . spl_object_id($entity1) . '. '
                . 'To solve this issue: Either explicitly call EntityManager#persist() on this unknown entity '
                . 'or configure cascade persist this association in the mapping for example '
                . '@ManyToOne(..,cascade={"persist"}). If you cannot find out which entity causes the problem '
                . 'implement \'baz1#__toString()\' to get a clue.' . "\n"
                . ' * A new entity was found through the relationship \'foo2#bar2\' that was not configured to '
                . 'cascade persist operations for entity: stdClass@' . spl_object_id($entity2) . '. To solve '
                . 'this issue: Either explicitly call EntityManager#persist() on this unknown entity or '
                . 'configure cascade persist this association in the mapping for example '
                . '@ManyToOne(..,cascade={"persist"}). If you cannot find out which entity causes the problem '
                . 'implement \'baz2#__toString()\' to get a clue.',
            ],
            'two entities found, one is stringable' => [
                [
                    [
                        $association3,
                        $entity3,
                    ],
                ],
                'A new entity was found through the relationship \'foo3#bar3\' that was not configured to cascade '
                . 'persist operations for entity: ' . $stringEntity3
                . '. To solve this issue: Either explicitly call EntityManager#persist() on this unknown entity '
                . 'or configure cascade persist this association in the mapping for example '
                . '@ManyToOne(..,cascade={"persist"}).',
            ],
        ];
    }
}
