<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * Join ::= ["LEFT" ["OUTER"] | "INNER"] "JOIN" JoinAssociationPathExpression
 *          ["AS"] AliasIdentificationVariable [("ON" | "WITH") ConditionalExpression]
 *
 * @link    www.doctrine-project.org
 */
class Join extends Node
{
    public const JOIN_TYPE_LEFT      = 1;
    public const JOIN_TYPE_LEFTOUTER = 2;
    public const JOIN_TYPE_INNER     = 3;

    /** @var int */
    public $joinType = self::JOIN_TYPE_INNER;

    /** @var Node|null */
    public $joinAssociationDeclaration = null;

    /** @var ConditionalExpression|null */
    public $conditionalExpression = null;

    /**
     * @param int  $joinType
     * @param Node $joinAssociationDeclaration
     */
    public function __construct($joinType, $joinAssociationDeclaration)
    {
        $this->joinType                   = $joinType;
        $this->joinAssociationDeclaration = $joinAssociationDeclaration;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkJoin($this);
    }
}
