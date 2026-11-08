<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\Tests\Models\DynamicProperties\Article;
use Doctrine\Tests\Models\DynamicProperties\Comment;
use Doctrine\Tests\Models\DynamicProperties\Tag;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_column;
use function array_keys;
use function array_map;
use function array_values;
use function sort;
use function strtolower;

class DynamicPropertiesToManyTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([Article::class, Tag::class, Comment::class]);
    }

    protected function tearDown(): void
    {
        $conn = $this->_em->getConnection();

        $conn->executeStatement('DELETE FROM dynamic_properties_article_tag');
        $conn->executeStatement('DELETE FROM dynamic_properties_comment');
        $conn->executeStatement('DELETE FROM dynamic_properties_article');
        $conn->executeStatement('DELETE FROM dynamic_properties_tag');

        parent::tearDown();
    }

    public function testMappingIsValid(): void
    {
        $validator = new SchemaValidator($this->_em);

        foreach ([Article::class, Tag::class, Comment::class] as $className) {
            self::assertSame([], $validator->validateClass($this->_em->getClassMetadata($className)), $className);
        }
    }

    public function testSchemaContainsJoinTable(): void
    {
        $columns = array_map(
            strtolower(...),
            array_keys($this->createSchemaManager()->listTableColumns('dynamic_properties_article_tag')),
        );
        sort($columns);

        self::assertSame(['article_id', 'tag_id'], $columns);
    }

    public function testPersistAndLoadCollections(): void
    {
        $article = $this->createArticle(['php', 'doctrine'], ['first', 'second']);

        $article = $this->_em->find(Article::class, $article->id);

        $tags = $article->get('tags');
        self::assertInstanceOf(PersistentCollection::class, $tags);
        self::assertFalse($tags->isInitialized());
        self::assertSame(['doctrine', 'php'], $this->names($tags));

        $comments = $article->get('comments');
        self::assertInstanceOf(PersistentCollection::class, $comments);
        self::assertSame(['first', 'second'], array_map(static fn (Comment $comment) => $comment->body, $comments->toArray()));

        $tag = $this->_em->getRepository(Tag::class)->findOneBy(['name' => 'php']);
        self::assertSame(['Hello'], array_map(static fn (Article $article) => $article->title, $tag->get('articles')->toArray()));
    }

    public function testLoadedEntityIsNotDirty(): void
    {
        $article = $this->createArticle(['php'], ['first']);

        $article = $this->_em->find(Article::class, $article->id);

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertSame([], $uow->getEntityChangeSet($article));
        self::assertSame([], $uow->getScheduledCollectionUpdates());
    }

    public function testAddingToLoadedCollection(): void
    {
        $article = $this->createArticle(['php'], []);

        $tag = new Tag('orm');
        $this->_em->persist($tag);

        $article = $this->_em->find(Article::class, $article->id);
        $article->get('tags')->add($tag);
        $article->get('comments')->add(new Comment($article, 'new'));
        $this->_em->flush();
        $this->_em->clear();

        $article = $this->_em->find(Article::class, $article->id);

        self::assertSame(['orm', 'php'], $this->names($article->get('tags')));
        self::assertCount(1, $article->get('comments'));
    }

    public function testOrphanRemoval(): void
    {
        $article = $this->createArticle([], ['first', 'second']);

        $article  = $this->_em->find(Article::class, $article->id);
        $comments = $article->get('comments');
        $comments->removeElement($comments->first());
        $this->_em->flush();
        $this->_em->clear();

        self::assertEquals(1, $this->_em->getConnection()->fetchOne('SELECT COUNT(*) FROM dynamic_properties_comment'));
    }

    public function testRemovingKeyDeletesCollection(): void
    {
        $article = $this->createArticle(['php', 'doctrine'], []);

        $article = $this->_em->find(Article::class, $article->id);
        $article->remove('tags');
        $this->_em->flush();
        $this->_em->clear();

        self::assertEquals(0, $this->_em->getConnection()->fetchOne('SELECT COUNT(*) FROM dynamic_properties_article_tag'));
        self::assertCount(0, $this->_em->find(Article::class, $article->id)->get('tags'));
    }

    public function testQueryingCollections(): void
    {
        $this->createArticle(['php', 'doctrine'], ['first'], 'Hello');
        $this->createArticle(['java'], [], 'Other');

        $result = $this->_em->createQuery(
            'SELECT a.title FROM ' . Article::class . ' a JOIN a.tags t WHERE t.name = :name',
        )->setParameter('name', 'doctrine')->getScalarResult();
        self::assertSame(['Hello'], array_column($result, 'title'));

        $result = $this->_em->createQuery(
            'SELECT a.title FROM ' . Article::class . ' a JOIN a.comments c WHERE c.body = :body',
        )->setParameter('body', 'first')->getScalarResult();
        self::assertSame(['Hello'], array_column($result, 'title'));

        $result = $this->_em->createQuery(
            'SELECT t.name FROM ' . Tag::class . ' t JOIN t.articles a WHERE a.title = :title ORDER BY t.name',
        )->setParameter('title', 'Hello')->getScalarResult();
        self::assertSame(['doctrine', 'php'], array_column($result, 'name'));

        $java   = $this->_em->getRepository(Tag::class)->findOneBy(['name' => 'java']);
        $result = $this->_em->createQuery(
            'SELECT a.title FROM ' . Article::class . ' a WHERE :tag MEMBER OF a.tags',
        )->setParameter('tag', $java)->getScalarResult();
        self::assertSame(['Other'], array_column($result, 'title'));

        $this->_em->clear();

        $articles = $this->_em->createQuery(
            'SELECT a, t FROM ' . Article::class . ' a JOIN a.tags t WHERE a.title = :title',
        )->setParameter('title', 'Hello')->getResult();

        self::assertCount(1, $articles);
        self::assertTrue($articles[0]->get('tags')->isInitialized());
        self::assertSame(['doctrine', 'php'], $this->names($articles[0]->get('tags')));
    }

    /**
     * @param list<string> $tagNames
     * @param list<string> $commentBodies
     */
    private function createArticle(array $tagNames, array $commentBodies, string $title = 'Hello'): Article
    {
        $article = new Article($title);
        $tags    = new ArrayCollection();

        foreach ($tagNames as $name) {
            $tag = new Tag($name);
            $tags->add($tag);
            $this->_em->persist($tag);
        }

        $article->set('tags', $tags);
        $article->set('comments', new ArrayCollection(array_map(
            static fn (string $body) => new Comment($article, $body),
            $commentBodies,
        )));

        $this->_em->persist($article);
        $this->_em->flush();
        $this->_em->clear();

        return $article;
    }

    /**
     * Returns the tag names in collection order, the association is ordered by name.
     *
     * @param Collection<int, Tag> $tags
     *
     * @return list<string>
     */
    private function names(Collection $tags): array
    {
        return array_values(array_map(static fn (Tag $tag) => $tag->name, $tags->toArray()));
    }
}
