<?php

namespace Doctrine\Tests\Models\DDC6573;

class DDC6573Currency
{
    /**
     * Currency code.
     *
     * @var string
     */
    private $code;

    public function __construct($code)
    {
        if (!is_string($code)) {
            throw new \InvalidArgumentException('Currency code should be string');
        }

        $this->code = $code;
    }

    /**
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }
}
