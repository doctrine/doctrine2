<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function reset;

class GH11982Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH11982ColumnIndex::class,
        ]);
    }

    #[Group('GH-11982')]
    public function testSchema(): void
    {
        if ($this->_em->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('This test does not work on psql.');
        }

        $indexes = $this->createSchemaManager()
            ->introspectTable('GH11982ColumnIndex')
            ->getIndexes();

        self::assertCount(3, $indexes); // primary + 2 custom indexes
        self::assertArrayHasKey('class_idx', $indexes);

        unset($indexes['primary']);
        unset($indexes['class_idx']);
        $unnamedIndexColumns = reset($indexes)->getIndexedColumns();
        self::assertCount(1, $unnamedIndexColumns);
        self::assertEquals(
            'indexTrue',
            $unnamedIndexColumns[0]->getColumnName()->toString(),
        );
    }
}

#[ORM\Entity]
#[ORM\Index(
    name: 'class_idx',
    fields: ['classIndex'],
    flags: ['fulltext'],
    options: ['test'],
)]
class GH11982ColumnIndex
{
    #[ORM\Id]
    #[ORM\Column]
    public string $noIndex;

    #[ORM\Column(index: true)]
    public string $indexTrue;

    #[ORM\Column]
    public string $classIndex;
}
