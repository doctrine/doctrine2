<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * AssociationPathExpression ::= CollectionValuedPathExpression | SingleValuedAssociationPathExpression
 * SingleValuedPathExpression ::= StateFieldPathExpression | SingleValuedAssociationPathExpression
 * StateFieldPathExpression ::= SimpleStateFieldPathExpression | SimpleStateFieldAssociationPathExpression
 * SingleValuedAssociationPathExpression ::= IdentificationVariable "." SingleValuedAssociationField
 * CollectionValuedPathExpression ::= IdentificationVariable "." CollectionValuedAssociationField
 * StateField ::= {EmbeddedClassStateField "."}* SimpleStateField
 * SimpleStateFieldPathExpression ::= IdentificationVariable "." StateField
 */
class PathExpression extends Node
{
    final public const int TYPE_COLLECTION_VALUED_ASSOCIATION = 2;
    final public const int TYPE_SINGLE_VALUED_ASSOCIATION     = 4;
    final public const int TYPE_STATE_FIELD                   = 8;

    /** @phpstan-var self::TYPE_*|null */
    public int|null $type = null;

    /** @phpstan-param int-mask-of<self::TYPE_*> $expectedType */
    public function __construct(
        public int $expectedType,
        public string $identificationVariable,
        public string|null $field = null,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkPathExpression($this);
    }
}
