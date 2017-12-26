<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Query;
use Doctrine\Tests\Models\DDC6573\DDC6573Currency;
use Doctrine\Tests\Models\DDC6573\DDC6573Item;
use Doctrine\Tests\Models\DDC6573\DDC6573Money;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-6573
 */
class DDC6573Test extends OrmFunctionalTestCase
{
    /**
     * @var DDC6573Item[]
     */
    private $fixtures;

    protected function setUp()
    {
        $this->useModelSet('ddc6573');
        parent::setUp();

        $this->loadFixtures();
    }

    public function provideDataForHydrationMode()
    {
        return [
            [Query::HYDRATE_ARRAY],
            [Query::HYDRATE_OBJECT],
        ];
    }

    private function loadFixtures()
    {
        $item1 = new DDC6573Item('Plate', new DDC6573Money('5', new DDC6573Currency('GBP')));
        $item2 = new DDC6573Item('Teapot', new DDC6573Money(10, new DDC6573Currency('GBP')));
        $item3 = new DDC6573Item('Iron', new DDC6573Money('50', new DDC6573Currency('GBP')));

        $this->_em->persist($item1);
        $this->_em->persist($item2);
        $this->_em->persist($item3);

        $this->_em->flush();
        $this->_em->clear();

        $this->fixtures = [$item1, $item2, $item3];
    }

    /**
     * @dataProvider provideDataForHydrationMode
     */
    public function testShouldSupportsMultipleNewOperator($hydrationMode)
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\DDC6573\DDC6573Money(
                    i.priceAmount,
                    new Doctrine\Tests\Models\DDC6573\DDC6573Currency(i.priceCurrency)
                )
            FROM
                Doctrine\Tests\Models\DDC6573\DDC6573Item i
            ORDER BY
                i.priceAmount";

        $query  = $this->_em->createQuery($dql);
        $result = $query->getResult($hydrationMode);

        $this->assertCount(3, $result);

        $this->assertInstanceOf(DDC6573Money::class, $result[0]);
        $this->assertInstanceOf(DDC6573Money::class, $result[1]);
        $this->assertInstanceOf(DDC6573Money::class, $result[2]);

        $this->assertEquals($this->fixtures[0]->getPrice(), $result[0]);
        $this->assertEquals($this->fixtures[1]->getPrice(), $result[1]);
        $this->assertEquals($this->fixtures[2]->getPrice(), $result[2]);
    }

    /**
     * @dataProvider provideDataForHydrationMode
     */
    public function testShouldSupportsMultipleNewOperatorWithHiddenKeyword($hydrationMode)
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\DDC6573\DDC6573Currency(i.priceCurrency) as HIDDEN currency,
                new Doctrine\Tests\Models\DDC6573\DDC6573Money(
                    i.priceAmount,
                    currency
                )
            FROM
                Doctrine\Tests\Models\DDC6573\DDC6573Item i
            ORDER BY
                i.priceAmount";

        $query  = $this->_em->createQuery($dql);
        $result = $query->getResult($hydrationMode);

        $this->assertCount(3, $result);

        $this->assertInstanceOf(DDC6573Money::class, $result[0]);
        $this->assertInstanceOf(DDC6573Money::class, $result[1]);
        $this->assertInstanceOf(DDC6573Money::class, $result[2]);

        $this->assertEquals($this->fixtures[0]->getPrice(), $result[0]);
        $this->assertEquals($this->fixtures[1]->getPrice(), $result[1]);
        $this->assertEquals($this->fixtures[2]->getPrice(), $result[2]);
    }

    public function testShouldSupportsMultipleNewOperatorWithFunctionApplied()
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\DDC6573\DDC6573Money(
                  sum(i.priceAmount),
                  new Doctrine\Tests\Models\DDC6573\DDC6573Currency('GBP')
                )
            FROM
                Doctrine\Tests\Models\DDC6573\DDC6573Item i
            ORDER BY
                i.priceAmount";

        $query  = $this->_em->createQuery($dql);
        $result = $query->getScalarResult();

        $this->assertEquals(new DDC6573Money('65', new DDC6573Currency('GBP')), $result[0]);
    }

    /**
     * @dataProvider provideDataForHydrationMode
     */
    public function testShouldSupportsBasicUsage($hydrationMode)
    {
        $dql = "
            SELECT
                new Doctrine\Tests\Models\DDC6573\DDC6573Currency(
                    i.priceCurrency
                )
            FROM
                Doctrine\Tests\Models\DDC6573\DDC6573Item i
            ORDER BY
                i.priceAmount";

        $query  = $this->_em->createQuery($dql);
        $result = $query->getResult($hydrationMode);

        $this->assertCount(3, $result);

        $this->assertInstanceOf(DDC6573Currency::class, $result[0]);
        $this->assertInstanceOf(DDC6573Currency::class, $result[1]);
        $this->assertInstanceOf(DDC6573Currency::class, $result[2]);

        $this->assertEquals($this->fixtures[0]->getPrice()->getCurrency(), $result[0]);
        $this->assertEquals($this->fixtures[1]->getPrice()->getCurrency(), $result[1]);
        $this->assertEquals($this->fixtures[2]->getPrice()->getCurrency(), $result[2]);
    }
}
