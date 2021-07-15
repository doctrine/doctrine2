<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

class PartialObjectExpression extends Node
{
    /** @var string */
    public $identificationVariable;

    /** @var mixed[] */
    public $partialFieldSet;

    /**
     * @param string  $identificationVariable
     * @param mixed[] $partialFieldSet
     */
    public function __construct($identificationVariable, array $partialFieldSet)
    {
        $this->identificationVariable = $identificationVariable;
        $this->partialFieldSet        = $partialFieldSet;
    }
}
