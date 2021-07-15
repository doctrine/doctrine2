<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * InstanceOfExpression ::= IdentificationVariable ["NOT"] "INSTANCE" ["OF"] (InstanceOfParameter | "(" InstanceOfParameter {"," InstanceOfParameter}* ")")
 * InstanceOfParameter  ::= AbstractSchemaName | InputParameter
 *
 * @link    www.doctrine-project.org
 */
class InstanceOfExpression extends Node
{
    /** @var bool */
    public $not;

    /** @var string */
    public $identificationVariable;

    /** @var mixed[] */
    public $value;

    /**
     * @param string $identVariable
     */
    public function __construct($identVariable)
    {
        $this->identificationVariable = $identVariable;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkInstanceOfExpression($this);
    }
}
