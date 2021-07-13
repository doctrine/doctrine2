<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * UpdateItem ::= [IdentificationVariable "."] {StateField | SingleValuedAssociationField} "=" NewValue
 * NewValue ::= SimpleArithmeticExpression | StringPrimary | DatetimePrimary | BooleanPrimary |
 *              EnumPrimary | SimpleEntityExpression | "NULL"
 *
 * @link    www.doctrine-project.org
 */
class UpdateItem extends Node
{
    /** @var PathExpression */
    public $pathExpression;

    /** @var InputParameter|ArithmeticExpression|null */
    public $newValue;

    /**
     * @param PathExpression                           $pathExpression
     * @param InputParameter|ArithmeticExpression|null $newValue
     */
    public function __construct($pathExpression, $newValue)
    {
        $this->pathExpression = $pathExpression;
        $this->newValue       = $newValue;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkUpdateItem($this);
    }
}
