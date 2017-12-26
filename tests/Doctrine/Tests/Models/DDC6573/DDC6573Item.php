<?php

namespace Doctrine\Tests\Models\DDC6573;

/**
 * @Entity
 * @Table(name="ddc6573_items")
 */
class DDC6573Item
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @Column(type="string", length=255)
     */
    public $name;

    /**
     * @Column(type="string")
     */
    public $priceAmount;

    /**
     * @Column(type="string", length=3)
     */
    public $priceCurrency;

    /**
     * @param $name
     * @param DDC6573Money $price
     */
    public function __construct($name, DDC6573Money $price)
    {
        $this->name = $name;
        $this->priceAmount = $price->getAmount();
        $this->priceCurrency = $price->getCurrency()->getCode();
    }

    /**
     * @return DDC6573Money
     */
    public function getPrice()
    {
        return new DDC6573Money($this->priceAmount, new DDC6573Currency($this->priceCurrency));
    }
}
