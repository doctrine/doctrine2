<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\OrmFunctionalTestCase;

class GH8089Test extends OrmFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH8089Invoice::class,
        ]);
    }

    public function testEntityIsFetched()
    {
        $entity = new GH8089Invoice(new GH8089InvoiceCode(1, 2020));
        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        /** @var GH8089Invoice $fetched */
        $fetched = $this->_em->find(GH8089Invoice::class, $entity->getId());
        $this->assertInstanceOf(GH8089Invoice::class, $fetched);

        $this->assertSame(1, $fetched->getCode()->getNumber());
        $this->assertSame(2020, $fetched->getCode()->getYear());

        $this->_em->clear();
    }
}

/**
 * @Embeddable
 */
class GH8089InvoiceCode extends GH8089AbstractYearSequenceValue
{
}

/**
 * @Embeddable
 * @MappedSuperclass
 */
abstract class GH8089AbstractYearSequenceValue
{
    /**
     * @Column(type="integer", name="number", length=6)
     * @var int
     */
    protected $number;

    /**
     * @Column(type="smallint", name="year", length=4)
     * @var int
     */
    protected $year;

    public function __construct(int $number, int $year)
    {
        $this->number = $number;
        $this->year = $year;
    }

    public function getNumber() : int
    {
        return $this->number;
    }

    public function getYear() : int
    {
        return $this->year;
    }
}

/**
 * @Entity
 */
class GH8089Invoice
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    private $id;

    /**
     * @Embedded(class=GH8089InvoiceCode::class)
     * @var GH8089InvoiceCode
     */
    private $code;

    public function __construct(GH8089InvoiceCode $code)
    {
        $this->code = $code;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCode() : GH8089InvoiceCode
    {
        return $this->code;
    }
}
