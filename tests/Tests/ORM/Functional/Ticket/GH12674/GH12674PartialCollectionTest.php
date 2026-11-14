<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12674;

use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\RequiresPhp;

/**
 * Fetch-joining a collection into partial objects must not initialize them.
 *
 * @see https://github.com/doctrine/orm/issues/12674
 * @see https://github.com/doctrine/orm/pull/12675#issuecomment-6232855127
 */
#[RequiresPhp('>=8.4.0')]
final class GH12674PartialCollectionTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(Post::class, Tag::class);

        // Inserted through DBAL on purpose: once a ReflectionProperty has been used on any object,
        // isInitialized() no longer initializes a lazy object through it, which would hide the bug.
        $connection = $this->_em->getConnection();
        $connection->executeStatement('DELETE FROM gh12674_post_tag');
        $connection->executeStatement('DELETE FROM gh12674_post');
        $connection->executeStatement('DELETE FROM gh12674_tag');
        $connection->insert('gh12674_tag', ['id' => 1, 'name' => 'tag']);

        for ($id = 1; $id <= 3; $id++) {
            $connection->insert('gh12674_post', ['id' => $id, 'title' => 'Post ' . $id, 'body' => 'body']);
            $connection->insert('gh12674_post_tag', ['post_id' => $id, 'tag_id' => 1]);
        }
    }

    public function testFetchJoiningACollectionIntoPartialObjectsDoesNotInitializeThem(): void
    {
        $this->getQueryLog()->reset()->enable();

        $posts = $this->_em
            ->createQuery('SELECT PARTIAL p.{id, title}, t FROM ' . Post::class . ' p LEFT JOIN p.tags t ORDER BY p.id')
            ->getResult();

        // Without the fix, the hydrator reads the collection property through the property accessor,
        // which initializes each partial object and runs one extra query per row.
        $this->assertQueryCount(1);
        self::assertCount(3, $posts);

        foreach ($posts as $post) {
            self::assertTrue($this->isUninitializedObject($post));
            self::assertCount(1, $post->tags);
        }
    }
}
