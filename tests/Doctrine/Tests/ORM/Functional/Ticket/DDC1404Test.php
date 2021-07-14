<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\NamedQueries;
use Doctrine\ORM\Mapping\NamedQuery;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;

/**
 * @group DDC-1404
 */
class DDC1404Test extends OrmFunctionalTestCase
{
    use VerifyDeprecations;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1404ParentEntity::class),
                    $this->_em->getClassMetadata(DDC1404ChildEntity::class),
                ]
            );

            $this->loadFixtures();
        } catch (Exception $exc) {
        }
    }

    public function testTicket(): void
    {
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/8592');

        $repository  = $this->_em->getRepository(DDC1404ChildEntity::class);
        $queryAll    = $repository->createNamedQuery('all');
        $queryFirst  = $repository->createNamedQuery('first');
        $querySecond = $repository->createNamedQuery('second');

        $this->assertEquals('SELECT p FROM Doctrine\Tests\ORM\Functional\Ticket\DDC1404ChildEntity p', $queryAll->getDQL());
        $this->assertEquals('SELECT p FROM Doctrine\Tests\ORM\Functional\Ticket\DDC1404ChildEntity p WHERE p.id = 1', $queryFirst->getDQL());
        $this->assertEquals('SELECT p FROM Doctrine\Tests\ORM\Functional\Ticket\DDC1404ChildEntity p WHERE p.id = 2', $querySecond->getDQL());

        $this->assertEquals(count($queryAll->getResult()), 2);
        $this->assertEquals(count($queryFirst->getResult()), 1);
        $this->assertEquals(count($querySecond->getResult()), 1);
    }

    public function loadFixtures(): void
    {
        $c1 = new DDC1404ChildEntity('ChildEntity 1');
        $c2 = new DDC1404ChildEntity('ChildEntity 2');

        $this->_em->persist($c1);
        $this->_em->persist($c2);

        $this->_em->flush();
    }
}

/**
 * @MappedSuperclass
 * @NamedQueries({
 *      @NamedQuery(name="all",     query="SELECT p FROM __CLASS__ p"),
 *      @NamedQuery(name="first",   query="SELECT p FROM __CLASS__ p WHERE p.id = 1"),
 * })
 */
class DDC1404ParentEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue()
     */
    protected $id;

    public function getId(): int
    {
        return $this->id;
    }
}

/**
 * @Entity
 * @NamedQueries({
 *      @NamedQuery(name="first",   query="SELECT p FROM __CLASS__ p WHERE p.id = 1"),
 *      @NamedQuery(name="second",  query="SELECT p FROM __CLASS__ p WHERE p.id = 2")
 * })
 */
class DDC1404ChildEntity extends DDC1404ParentEntity
{
    /**
     * @var string
     * @Column(type="string")
     */
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}
