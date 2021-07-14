<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

class GH6394Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(A::class),
                $this->_em->getClassMetadata(B::class),
            ]
        );
    }

    /**
     * Test the the version of an entity can be fetched, when the id field and
     * the id column are different.
     *
     * @group 6393
     */
    public function testFetchVersionValueForDifferentIdFieldAndColumn(): void
    {
        $a = new A(1);
        $this->_em->persist($a);

        $b = new B($a, 'foo');
        $this->_em->persist($b);
        $this->_em->flush();

        self::assertSame(1, $b->version);

        $b->something = 'bar';
        $this->_em->flush();

        self::assertSame(2, $b->version);
    }
}

/**
 * @Entity
 */
class A
{
    /**
     * @Id
     * @Column(type="integer")
     * @var int
     */
    public $id;

    /**
     * @Version
     * @Column(type="integer")
     * @var int
     */
    public $version;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}

/**
 * @Entity
 */
class B
{
    /**
     * @Id
     * @ManyToOne(targetEntity="A")
     * @JoinColumn(name="aid", referencedColumnName="id")
     * @var A
     */
    public $a;

    /**
     * @Column(type="string")
     * @var string
     */
    public $something;

    /**
     * @Version
     * @Column(type="integer")
     * @var int
     */
    public $version;

    public function __construct(A $a, string $something)
    {
        $this->a         = $a;
        $this->something = $something;
    }
}
