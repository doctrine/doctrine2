<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * RangeVariableDeclaration ::= AbstractSchemaName ["AS"] AliasIdentificationVariable
 *
 * @link    www.doctrine-project.org
 */
class RangeVariableDeclaration extends Node
{
    /** @var string */
    public $abstractSchemaName;

    /** @var string */
    public $aliasIdentificationVariable;

    /** @var bool */
    public $isRoot;

    /**
     * @param string $abstractSchemaName
     * @param string $aliasIdentificationVar
     * @param bool   $isRoot
     */
    public function __construct($abstractSchemaName, $aliasIdentificationVar, $isRoot = true)
    {
        $this->abstractSchemaName          = $abstractSchemaName;
        $this->aliasIdentificationVariable = $aliasIdentificationVar;
        $this->isRoot                      = $isRoot;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($walker)
    {
        return $walker->walkRangeVariableDeclaration($this);
    }
}
