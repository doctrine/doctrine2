<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\RootTypeWalker;
use Doctrine\Tests\DbalTypes\Rot13Type;
use Doctrine\Tests\Models\ValueConversionType\AuxiliaryEntity;
use Doctrine\Tests\Models\ValueConversionType\OwningManyToOneIdForeignKeyEntity;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;

class RootTypeWalkerTest extends PaginationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType('rot13', Rot13Type::class);
    }

    #[DataProvider('exampleQueries')]
    public function testResolveTypeMapping(string $dqlQuery, string $expectedType): void
    {
        $query = $this->entityManager->createQuery($dqlQuery);
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, RootTypeWalker::class);

        self::assertSame($expectedType, $query->getSQL());
    }

    /** @return Generator<string, array{string, string}> */
    public static function exampleQueries(): Generator
    {
        yield 'Entity with #Id column of special type' => [
            'SELECT e.id4 FROM ' . AuxiliaryEntity::class . ' e',
            'rot13',
        ];

        yield 'Entity where #Id is a to-one relation with special type identifier' => [
            'SELECT e FROM ' . OwningManyToOneIdForeignKeyEntity::class . ' e',
            'rot13',
        ];

        yield 'Simple integer ID in a query with a JOIN' => [
            'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g',
            'integer',
        ];
    }
}
