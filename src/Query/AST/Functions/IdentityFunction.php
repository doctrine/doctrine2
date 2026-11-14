<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

use function assert;
use function reset;
use function sprintf;

/**
 * "IDENTITY" "(" SingleValuedAssociationPathExpression {"," string} ")"
 *
 * @link    www.doctrine-project.org
 */
class IdentityFunction extends FunctionNode
{
    public PathExpression $pathExpression;

    public string|null $fieldMapping = null;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        assert($this->pathExpression->field !== null);
        $entityManager = $sqlWalker->getEntityManager();
        $platform      = $entityManager->getConnection()->getDatabasePlatform();
        $quoteStrategy = $entityManager->getConfiguration()->getQuoteStrategy();
        $dqlAlias      = $this->pathExpression->identificationVariable;
        $assocField    = $this->pathExpression->field;
        $assoc         = $sqlWalker->getMetadataForDqlAlias($dqlAlias)->associationMappings[$assocField];
        $targetEntity  = $entityManager->getClassMetadata($assoc->targetEntity);

        if (! $assoc->isToOneOwningSide()) {
            throw QueryException::associationPathInverseSideNotSupported($this->pathExpression);
        }

        $joinColumn = reset($assoc->joinColumns);

        if ($this->fieldMapping !== null) {
            if (! isset($targetEntity->fieldMappings[$this->fieldMapping])) {
                throw new QueryException(sprintf('Undefined reference field mapping "%s"', $this->fieldMapping));
            }

            $field      = $targetEntity->fieldMappings[$this->fieldMapping];
            $joinColumn = null;

            foreach ($assoc->joinColumns as $mapping) {
                if ($mapping->referencedColumnName === $field->columnName) {
                    $joinColumn = $mapping;

                    break;
                }
            }

            if ($joinColumn === null) {
                throw new QueryException(sprintf('Unable to resolve the reference field mapping "%s"', $this->fieldMapping));
            }
        }

        // The table with the relation may be a subclass, so get the table name from the association definition
        $tableName = $entityManager->getClassMetadata($assoc->sourceEntity)->getTableName();

        $tableAlias = $sqlWalker->getSQLTableAlias($tableName, $dqlAlias);
        $columnName = $quoteStrategy->getJoinColumnName($joinColumn, $targetEntity, $platform);

        return $tableAlias . '.' . $columnName;
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->pathExpression = $parser->SingleValuedAssociationPathExpression();

        if ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $parser->match(TokenType::T_STRING);

            $token = $parser->getLexer()->token;
            assert($token !== null);
            $this->fieldMapping = $token->value;
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
