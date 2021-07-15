<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * JoinAssociationPathExpression ::= IdentificationVariable "." (SingleValuedAssociationField | CollectionValuedAssociationField)
 *
 * @link    www.doctrine-project.org
 */
class JoinAssociationPathExpression extends Node
{
    /** @var string */
    public $identificationVariable;

    /** @var string */
    public $associationField;

    /**
     * @param string $identificationVariable
     * @param string $associationField
     */
    public function __construct($identificationVariable, $associationField)
    {
        $this->identificationVariable = $identificationVariable;
        $this->associationField       = $associationField;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkPathExpression($this);
    }
}
