<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;
use function iterator_to_array;

/**
 * Functional tests for paginator with collection order
 */
class DDC3330Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema(
            [
                DDC3330Building::class,
                DDC3330Hall::class,
            ]
        );
    }

    public function testIssueCollectionOrderWithPaginator(): void
    {
        $this->createBuildingAndHalls();
        $this->createBuildingAndHalls();
        $this->createBuildingAndHalls();

        $this->_em->clear();

        $query = $this->_em->createQuery(
            'SELECT b, h' .
            ' FROM Doctrine\Tests\ORM\Functional\Ticket\DDC3330Building b' .
            ' LEFT JOIN b.halls h' .
            ' ORDER BY b.id ASC, h.name DESC'
        )
        ->setMaxResults(3);

        $paginator = new Paginator($query, true);

        $this->assertEquals(3, count(iterator_to_array($paginator)), 'Count is not correct for pagination');
    }

    /**
     * Create a building and 10 halls
     */
    private function createBuildingAndHalls(): void
    {
        $building = new DDC3330Building();

        for ($i = 0; $i < 10; $i++) {
            $hall       = new DDC3330Hall();
            $hall->name = 'HALL-' . $i;
            $building->addHall($hall);
        }

        $this->_em->persist($building);
        $this->_em->flush();
    }
}

/**
 * @Entity @Table(name="ddc3330_building")
 */
class DDC3330Building
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int, DDC3330Hall>
     * @OneToMany(targetEntity="DDC3330Hall", mappedBy="building", cascade={"persist"})
     */
    public $halls;

    public function addHall(DDC3330Hall $hall): void
    {
        $this->halls[]  = $hall;
        $hall->building = $this;
    }
}

/**
 * @Entity @Table(name="ddc3330_hall")
 */
class DDC3330Hall
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var DDC3330Building
     * @ManyToOne(targetEntity="DDC3330Building", inversedBy="halls")
     */
    public $building;

    /**
     * @var string
     * @Column(type="string", length=100)
     */
    public $name;
}
