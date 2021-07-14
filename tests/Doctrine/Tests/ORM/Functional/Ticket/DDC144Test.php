<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC144Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC144FlowElement::class),
                $this->_em->getClassMetadata(DDC144Operand::class),
            ]
        );
    }

    /**
     * @group DDC-144
     */
    public function testIssue(): void
    {
        $operand                  = new DDC144Operand();
        $operand->property        = 'flowValue';
        $operand->operandProperty = 'operandValue';

        $this->_em->persist($operand);
        $this->_em->flush();

        self::assertSame($operand, $this->_em->find(DDC144Operand::class, $operand->id));
    }
}

/**
 * @Entity
 * @Table(name="ddc144_flowelements")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(type="string", name="discr")
 * @DiscriminatorMap({"flowelement" = "DDC144FlowElement", "operand" = "DDC144Operand"})
 */
class DDC144FlowElement
{
    /**
     * @Id @Column(type="integer") @GeneratedValue
     * @var int
     */
    public $id;

    /**
     * @var string
     * @Column
     */
    public $property;
}

abstract class DDC144Expression extends DDC144FlowElement
{
    abstract public function method(): void;
}

/** @Entity @Table(name="ddc144_operands") */
class DDC144Operand extends DDC144Expression
{
    /**
     * @var string
     * @Column
     */
    public $operandProperty;

    public function method(): void
    {
    }
}
