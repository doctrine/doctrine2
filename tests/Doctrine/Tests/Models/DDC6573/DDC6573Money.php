<?php

namespace Doctrine\Tests\Models\DDC6573;

class DDC6573Money
{
    /**
     * @var string
     */
    private $amount;

    /**
     * @var DDC6573Currency
     */
    private $currency;

    /**
     * @param int|string $amount   Amount, expressed in the smallest units of $currency (eg cents)
     * @param DDC6573Currency   $currency
     *
     * @throws \InvalidArgumentException If amount is not integer
     */
    public function __construct($amount, DDC6573Currency $currency)
    {
        if (filter_var($amount, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('Amount must be an integer(ish) value');
        }

        $this->amount = (string) $amount;
        $this->currency = $currency;
    }

    /**
     * @return string
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * @return DDC6573Currency
     */
    public function getCurrency()
    {
        return $this->currency;
    }
}
