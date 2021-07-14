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
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;

/**
 * @group DDC-2350
 * @group non-cacheable
 */
class DDC2350Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC2350User::class),
                $this->_em->getClassMetadata(DDC2350Bug::class),
            ]
        );
    }

    public function testEagerCollectionsAreOnlyRetrievedOnce(): void
    {
        $user       = new DDC2350User();
        $bug1       = new DDC2350Bug();
        $bug1->user = $user;
        $bug2       = new DDC2350Bug();
        $bug2->user = $user;

        $this->_em->persist($user);
        $this->_em->persist($bug1);
        $this->_em->persist($bug2);
        $this->_em->flush();

        $this->_em->clear();

        $cnt  = $this->getCurrentQueryCount();
        $user = $this->_em->find(DDC2350User::class, $user->id);

        $this->assertEquals($cnt + 1, $this->getCurrentQueryCount());

        $this->assertEquals(2, count($user->reportedBugs));

        $this->assertEquals($cnt + 1, $this->getCurrentQueryCount());
    }
}

/**
 * @Entity
 */
class DDC2350User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int, DDC2350Bug>
     * @OneToMany(targetEntity="DDC2350Bug", mappedBy="user", fetch="EAGER")
     */
    public $reportedBugs;
}

/**
 * @Entity
 */
class DDC2350Bug
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
    /**
     * @var DDC2350User
     * @ManyToOne(targetEntity="DDC2350User", inversedBy="reportedBugs")
     */
    public $user;
}
