<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\StockExchange;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="exchange_markets")
 */
class Market
{
    /**
     * @Id @Column(type="integer") @GeneratedValue
     * @var int
     */
    private $id;

    /**
     * @Column(type="string")
     * @var string
     */
    private $name;

    /**
     * @OneToMany(targetEntity="Stock", mappedBy="market", indexBy="symbol")
     * @psalm-var ArrayCollection<string, Stock>
     */
    public $stocks;

    public function __construct(string $name)
    {
        $this->name   = $name;
        $this->stocks = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addStock(Stock $stock): void
    {
        $this->stocks[$stock->getSymbol()] = $stock;
    }

    public function getStock(string $symbol): Stock
    {
        return $this->stocks[$symbol];
    }
}
