<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function count;

/**
 * @group DDC-618
 */
class DDC618Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC618Author::class),
                    $this->_em->getClassMetadata(DDC618Book::class),
                ]
            );

            // Create author 10/Joe with two books 22/JoeA and 20/JoeB
            $author       = new DDC618Author();
            $author->id   = 10;
            $author->name = 'Joe';
            $this->_em->persist($author);

            // Create author 11/Alice with two books 21/AliceA and 23/AliceB
            $author       = new DDC618Author();
            $author->id   = 11;
            $author->name = 'Alice';
            $author->addBook('In Wonderland');
            $author->addBook('Reloaded');
            $author->addBook('Test');

            $this->_em->persist($author);

            $this->_em->flush();
            $this->_em->clear();
        } catch (Exception $e) {
        }
    }

    public function testIndexByHydrateObject(): void
    {
        $dql    = 'SELECT A FROM Doctrine\Tests\ORM\Functional\Ticket\DDC618Author A INDEX BY A.name ORDER BY A.name ASC';
        $result = $this->_em->createQuery($dql)->getResult(Query::HYDRATE_OBJECT);

        $joe   = $this->_em->find(DDC618Author::class, 10);
        $alice = $this->_em->find(DDC618Author::class, 11);

        $this->assertArrayHasKey('Joe', $result, "INDEX BY A.name should return an index by the name of 'Joe'.");
        $this->assertArrayHasKey('Alice', $result, "INDEX BY A.name should return an index by the name of 'Alice'.");
    }

    public function testIndexByHydrateArray(): void
    {
        $dql    = 'SELECT A FROM Doctrine\Tests\ORM\Functional\Ticket\DDC618Author A INDEX BY A.name ORDER BY A.name ASC';
        $result = $this->_em->createQuery($dql)->getResult(Query::HYDRATE_ARRAY);

        $joe   = $this->_em->find(DDC618Author::class, 10);
        $alice = $this->_em->find(DDC618Author::class, 11);

        $this->assertArrayHasKey('Joe', $result, "INDEX BY A.name should return an index by the name of 'Joe'.");
        $this->assertArrayHasKey('Alice', $result, "INDEX BY A.name should return an index by the name of 'Alice'.");
    }

    /**
     * @group DDC-1018
     */
    public function testIndexByJoin(): void
    {
        $dql    = 'SELECT A, B FROM Doctrine\Tests\ORM\Functional\Ticket\DDC618Author A ' .
               'INNER JOIN A.books B INDEX BY B.title ORDER BY A.name ASC';
        $result = $this->_em->createQuery($dql)->getResult(Query::HYDRATE_OBJECT);

        $this->assertEquals(3, count($result[0]->books)); // Alice, Joe doesn't appear because he has no books.
        $this->assertEquals('Alice', $result[0]->name);
        $this->assertTrue(isset($result[0]->books['In Wonderland']), 'Indexing by title should have books by title.');
        $this->assertTrue(isset($result[0]->books['Reloaded']), 'Indexing by title should have books by title.');
        $this->assertTrue(isset($result[0]->books['Test']), 'Indexing by title should have books by title.');

        $result = $this->_em->createQuery($dql)->getResult(Query::HYDRATE_ARRAY);

        $this->assertEquals(3, count($result[0]['books'])); // Alice, Joe doesn't appear because he has no books.
        $this->assertEquals('Alice', $result[0]['name']);
        $this->assertTrue(isset($result[0]['books']['In Wonderland']), 'Indexing by title should have books by title.');
        $this->assertTrue(isset($result[0]['books']['Reloaded']), 'Indexing by title should have books by title.');
        $this->assertTrue(isset($result[0]['books']['Test']), 'Indexing by title should have books by title.');
    }

    /**
     * @group DDC-1018
     */
    public function testIndexByToOneJoinSilentlyIgnored(): void
    {
        $dql    = 'SELECT B, A FROM Doctrine\Tests\ORM\Functional\Ticket\DDC618Book B ' .
               'INNER JOIN B.author A INDEX BY A.name ORDER BY A.name ASC';
        $result = $this->_em->createQuery($dql)->getResult(Query::HYDRATE_OBJECT);

        $this->assertInstanceOf(DDC618Book::class, $result[0]);
        $this->assertInstanceOf(DDC618Author::class, $result[0]->author);

        $dql    = 'SELECT B, A FROM Doctrine\Tests\ORM\Functional\Ticket\DDC618Book B ' .
               'INNER JOIN B.author A INDEX BY A.name ORDER BY A.name ASC';
        $result = $this->_em->createQuery($dql)->getResult(Query::HYDRATE_ARRAY);

        $this->assertEquals('Alice', $result[0]['author']['name']);
    }

    /**
     * @group DDC-1018
     */
    public function testCombineIndexBy(): void
    {
        $dql    = 'SELECT A, B FROM Doctrine\Tests\ORM\Functional\Ticket\DDC618Author A INDEX BY A.id ' .
               'INNER JOIN A.books B INDEX BY B.title ORDER BY A.name ASC';
        $result = $this->_em->createQuery($dql)->getResult(Query::HYDRATE_OBJECT);

        $this->assertArrayHasKey(11, $result); // Alice

        $this->assertEquals(3, count($result[11]->books)); // Alice, Joe doesn't appear because he has no books.
        $this->assertEquals('Alice', $result[11]->name);
        $this->assertTrue(isset($result[11]->books['In Wonderland']), 'Indexing by title should have books by title.');
        $this->assertTrue(isset($result[11]->books['Reloaded']), 'Indexing by title should have books by title.');
        $this->assertTrue(isset($result[11]->books['Test']), 'Indexing by title should have books by title.');
    }
}

/**
 * @Entity
 */
class DDC618Author
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @psalm-var Collection<int, DDC618Book>
     * @OneToMany(targetEntity="DDC618Book", mappedBy="author", cascade={"persist"})
     */
    public $books;

    public function __construct()
    {
        $this->books = new ArrayCollection();
    }

    public function addBook(string $title): void
    {
        $book          = new DDC618Book($title, $this);
        $this->books[] = $book;
    }
}

/**
 * @Entity
 */
class DDC618Book
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $title;

    /**
     * @var DDC618Author
     * @ManyToOne(targetEntity="DDC618Author", inversedBy="books")
     */
    public $author;

    public function __construct($title, $author)
    {
        $this->title  = $title;
        $this->author = $author;
    }
}
