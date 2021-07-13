<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * JoinAssociationDeclaration ::= JoinAssociationPathExpression ["AS"] AliasIdentificationVariable
 *
 * @link    www.doctrine-project.org
 */
class JoinAssociationDeclaration extends Node
{
    /** @var JoinAssociationPathExpression */
    public $joinAssociationPathExpression;

    /** @var string */
    public $aliasIdentificationVariable;

    /** @var IndexBy|null */
    public $indexBy;

    /**
     * @param JoinAssociationPathExpression $joinAssociationPathExpression
     * @param string                        $aliasIdentificationVariable
     * @param IndexBy|null                  $indexBy
     */
    public function __construct($joinAssociationPathExpression, $aliasIdentificationVariable, $indexBy)
    {
        $this->joinAssociationPathExpression = $joinAssociationPathExpression;
        $this->aliasIdentificationVariable   = $aliasIdentificationVariable;
        $this->indexBy                       = $indexBy;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkJoinAssociationDeclaration($this);
    }
}
