<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\Mocks\AttributeDriverFactory;
use Doctrine\Tests\Models\ReadonlyProperties\Author;
use Doctrine\Tests\Models\ReadonlyProperties\Book;
use Doctrine\Tests\Models\ReadonlyProperties\SimpleBook;
use Doctrine\Tests\OrmFunctionalTestCase;
use Doctrine\Tests\TestUtil;

class ReadonlyPropertiesTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        if (! isset(static::$sharedConn)) {
            static::$sharedConn = TestUtil::getConnection(self::createDbalConfiguration());
        }

        $attributeDriver = AttributeDriverFactory::createAttributeDriver([__DIR__ . '/../../Models/ReadonlyProperties']);

        $this->_em         = $this->getEntityManager(null, $attributeDriver);
        $this->_schemaTool = new SchemaTool($this->_em);

        parent::setUp();

        $this->setUpEntitySchema([Author::class, Book::class, SimpleBook::class]);
    }

    public function testSimpleEntity(): void
    {
        $connection = $this->_em->getConnection();

        $connection->insert('author', ['name' => 'Jane Austen']);
        $authorId = $connection->lastInsertId();

        $author = $this->_em->find(Author::class, $authorId);

        self::assertSame('Jane Austen', $author->getName());
        self::assertEquals($authorId, $author->getId());
    }

    public function testEntityWithLazyManyToOne(): void
    {
        $connection = $this->_em->getConnection();

        $connection->insert('author', ['name' => 'Jane Austen']);
        $authorId = $connection->lastInsertId();

        $connection->insert('simple_book', ['title' => 'Pride and Prejudice', 'author_id' => $authorId]);
        $bookId = $connection->lastInsertId();

        $book = $this->_em->find(SimpleBook::class, $bookId);

        self::assertSame('Pride and Prejudice', $book->getTitle());
        self::assertEquals($bookId, $book->getId());
        self::assertSame('Jane Austen', $book->getAuthor()->getName());
    }

    public function testEntityWithEagerManyToOne(): void
    {
        $connection = $this->_em->getConnection();

        $connection->insert('author', ['name' => 'Jane Austen']);
        $authorId = $connection->lastInsertId();

        $connection->insert('simple_book', ['title' => 'Pride and Prejudice', 'author_id' => $authorId]);
        $bookId = $connection->lastInsertId();

        [$book] = $this->_em->createQueryBuilder()
            ->from(SimpleBook::class, 'b')
            ->join('b.author', 'a')
            ->select('b', 'a')
            ->where('b.id = :id')
            ->setParameter('id', $bookId)
            ->getQuery()
            ->execute();

        self::assertInstanceOf(SimpleBook::class, $book);
        self::assertSame('Pride and Prejudice', $book->getTitle());
        self::assertEquals($bookId, $book->getId());
        self::assertSame('Jane Austen', $book->getAuthor()->getName());
    }

    public function testEntityWithManyToMany(): void
    {
        $connection = $this->_em->getConnection();

        $connection->insert('author', ['name' => 'Jane Austen']);
        $authorId = $connection->lastInsertId();

        $connection->insert('book', ['title' => 'Pride and Prejudice']);
        $bookId = $connection->lastInsertId();

        $connection->insert('book_author', ['book_id' => $bookId, 'author_id' => $authorId]);

        $book = $this->_em->find(Book::class, $bookId);

        self::assertSame('Pride and Prejudice', $book->getTitle());
        self::assertEquals($bookId, $book->getId());
        self::assertSame('Jane Austen', $book->getAuthors()[0]->getName());
    }
}
