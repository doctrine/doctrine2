<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\StockExchange;

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * Bonds have many stocks. This uses a many to many association and fails to model how many of a
 * particular stock a bond has. But i Need a many-to-many association, so please bear with my modelling skills ;)
 *
 * @Entity
 * @Table(name="exchange_bonds")
 */
class Bond
{
    /**
     * @Id @GeneratedValue @Column(type="integer")
     * @var int
     */
    private $id;

    /**
     * @Column(type="string")
     * @var string
     */
    private $name;

    /**
     * @ManyToMany(targetEntity="Stock", indexBy="symbol")
     * @JoinTable(name="exchange_bonds_stocks")
     * @var Stock[]
     */
    public $stocks;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function addStock(Stock $stock): void
    {
        $this->stocks[$stock->getSymbol()] = $stock;
    }
}
