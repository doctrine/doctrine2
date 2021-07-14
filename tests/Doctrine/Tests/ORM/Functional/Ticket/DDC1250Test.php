<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;
use PDOException;

/**
 * @group DDC-1250
 */
class DDC1250Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [$this->_em->getClassMetadata(DDC1250ClientHistory::class)]
            );
        } catch (PDOException $e) {
        }
    }

    public function testIssue(): void
    {
        $c1                         = new DDC1250ClientHistory();
        $c2                         = new DDC1250ClientHistory();
        $c1->declinedClientsHistory = $c2;
        $c1->declinedBy             = $c2;
        $c2->declinedBy             = $c1;
        $c2->declinedClientsHistory = $c1;

        $this->_em->persist($c1);
        $this->_em->persist($c2);
        $this->_em->flush();
        $this->_em->clear();

        $history = $this->_em->createQuery('SELECT h FROM ' . __NAMESPACE__ . '\\DDC1250ClientHistory h WHERE h.id = ?1')
                  ->setParameter(1, $c2->id)->getSingleResult();

        $this->assertInstanceOf(DDC1250ClientHistory::class, $history);
    }
}

/**
 * @Entity
 */
class DDC1250ClientHistory
{
    /**
     * @var int
     * @Id @GeneratedValue @Column(type="integer")
     */
    public $id;

    /**
     * @var DDC1250ClientHistory
     * @OneToOne(targetEntity="DDC1250ClientHistory", inversedBy="declinedBy")
     * @JoinColumn(name="declined_clients_history_id", referencedColumnName="id")
     */
    public $declinedClientsHistory;

    /**
     * @var DDC1250ClientHistory
     * @OneToOne(targetEntity="DDC1250ClientHistory", mappedBy="declinedClientsHistory")
     */
    public $declinedBy;
}

/*
 *
Entities\ClientsHistory:
type: entity
table: clients_history
fields:
id:
id: true
type: integer
unsigned: false
nullable: false
generator:
strategy: IDENTITY
[...skiped...]
oneToOne:
declinedClientsHistory:
targetEntity: Entities\ClientsHistory
joinColumn:
name: declined_clients_history_id
referencedColumnName: id
inversedBy: declinedBy
declinedBy:
targetEntity: Entities\ClientsHistory
mappedBy: declinedClientsHistory
lifecycleCallbacks: { }
repositoryClass: Entities\ClientsHistoryRepository


 */
