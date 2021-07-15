<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * SubselectIdentificationVariableDeclaration ::= AssociationPathExpression ["AS"] AliasIdentificationVariable
 *
 * @link    www.doctrine-project.org
 */
class SubselectIdentificationVariableDeclaration
{
    /** @var PathExpression */
    public $associationPathExpression;

    /** @var string */
    public $aliasIdentificationVariable;

    /**
     * @param PathExpression $associationPathExpression
     * @param string         $aliasIdentificationVariable
     */
    public function __construct($associationPathExpression, $aliasIdentificationVariable)
    {
        $this->associationPathExpression   = $associationPathExpression;
        $this->aliasIdentificationVariable = $aliasIdentificationVariable;
    }
}
