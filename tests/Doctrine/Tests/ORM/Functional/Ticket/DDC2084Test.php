<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-2084
 */
class DDC2084Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC2084\MyEntity1'),
                    $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC2084\MyEntity2'),
                ]
            );
        } catch (Exception $exc) {
        }
    }

    public function loadFixture(): DDC2084\MyEntity1
    {
        $e2 = new DDC2084\MyEntity2('Foo');
        $e1 = new DDC2084\MyEntity1($e2);

        $this->_em->persist($e2);
        $this->_em->flush();

        $this->_em->persist($e1);
        $this->_em->flush();

        $this->_em->clear();

        return $e1;
    }

    public function testIssue(): void
    {
        $e1 = $this->loadFixture();
        $e2 = $e1->getMyEntity2();
        $e  = $this->_em->find(__NAMESPACE__ . '\DDC2084\MyEntity1', $e2);

        $this->assertInstanceOf(__NAMESPACE__ . '\DDC2084\MyEntity1', $e);
        $this->assertInstanceOf(__NAMESPACE__ . '\DDC2084\MyEntity2', $e->getMyEntity2());
        $this->assertEquals('Foo', $e->getMyEntity2()->getValue());
    }

    public function testinvalidIdentifierBindingEntityException(): void
    {
        $this->expectException('Doctrine\ORM\ORMInvalidArgumentException');
        $this->expectExceptionMessage('Binding entities to query parameters only allowed for entities that have an identifier.');
        $this->_em->find(__NAMESPACE__ . '\DDC2084\MyEntity1', new DDC2084\MyEntity2('Foo'));
    }
}

namespace Doctrine\Tests\ORM\Functional\Ticket\DDC2084;

/**
 * @Entity
 * @Table(name="DDC2084_ENTITY1")
 */
class MyEntity1
{
    /**
     * @var MyEntity2
     * @Id
     * @OneToOne(targetEntity="MyEntity2")
     * @JoinColumn(name="entity2_id", referencedColumnName="id", nullable=false)
     */
    private $entity2;

    public function __construct(MyEntity2 $myEntity2)
    {
        $this->entity2 = $myEntity2;
    }

    public function setMyEntity2(MyEntity2 $myEntity2): void
    {
        $this->entity2 = $myEntity2;
    }

    public function getMyEntity2(): MyEntity2
    {
        return $this->entity2;
    }
}

/**
 * @Entity
 * @Table(name="DDC2084_ENTITY2")
 */
class MyEntity2
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column
     */
    private $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}
