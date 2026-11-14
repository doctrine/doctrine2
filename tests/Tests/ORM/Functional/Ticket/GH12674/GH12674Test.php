<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12674;

use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\RequiresPhp;

use function array_column;
use function array_filter;
use function array_values;
use function str_starts_with;

/** @see https://github.com/doctrine/orm/issues/12674 */
#[RequiresPhp('>=8.4.0')]
final class GH12674Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(Category::class, Item::class);

        // Inserted through DBAL on purpose: once a ReflectionProperty has been used on any object,
        // isInitialized() no longer initializes a lazy object through it, which would hide the bug.
        $connection = $this->_em->getConnection();
        $connection->executeStatement('DELETE FROM gh12674_item');
        $connection->executeStatement('DELETE FROM gh12674_category');
        $connection->insert('gh12674_category', ['id' => 'c1', 'label' => 'Category']);
        $connection->insert('gh12674_item', ['id' => 1, 'name' => 'before', 'category_id' => 'c1']);
    }

    public function testToOneProxyOfAnEntityWithAnEmbeddedIdentifierStaysUninitialized(): void
    {
        $item = $this->_em->find(Item::class, 1);

        self::assertInstanceOf(Item::class, $item);
        self::assertTrue($this->isUninitializedObject($item->category));

        // The identifier stays readable, and reading it must not initialize the proxy.
        self::assertSame(
            ['id.value' => 'c1'],
            $this->_em->getClassMetadata(Category::class)->getIdentifierValues($item->category),
        );
        self::assertTrue($this->isUninitializedObject($item->category));
    }

    public function testFlushDoesNotRewriteAnUnchangedToOneTargetWithAnEmbeddedIdentifier(): void
    {
        $item = $this->_em->find(Item::class, 1);

        self::assertNotNull($item);

        $item->name = 'after';

        $this->getQueryLog()->reset()->enable();
        $this->_em->flush();

        $updates = array_values(array_filter(
            array_column($this->getQueryLog()->queries, 'sql'),
            static fn (string $sql): bool => str_starts_with($sql, 'UPDATE'),
        ));

        self::assertSame(['UPDATE gh12674_item SET name = ? WHERE id = ?'], $updates);
    }
}
