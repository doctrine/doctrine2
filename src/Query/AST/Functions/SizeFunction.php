<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

use function assert;

/**
 * "SIZE" "(" CollectionValuedPathExpression ")"
 *
 * @link    www.doctrine-project.org
 */
class SizeFunction extends FunctionNode
{
    public PathExpression $collectionPathExpression;

    /**
     * @inheritdoc
     * @todo If the collection being counted is already joined, the SQL can be simpler (more efficient).
     */
    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        assert($this->collectionPathExpression->field !== null);
        $entityManager = $sqlWalker->getEntityManager();
        $platform      = $entityManager->getConnection()->getDatabasePlatform();
        $quoteStrategy = $entityManager->getConfiguration()->getQuoteStrategy();
        $dqlAlias      = $this->collectionPathExpression->identificationVariable;
        $assocField    = $this->collectionPathExpression->field;

        $class = $sqlWalker->getMetadataForDqlAlias($dqlAlias);
        $assoc = $class->associationMappings[$assocField];
        $sql   = 'SELECT COUNT(*) FROM ';

        if ($assoc->isOneToMany()) {
            $targetClass      = $entityManager->getClassMetadata($assoc->targetEntity);
            $targetTableAlias = $sqlWalker->getSQLTableAlias($targetClass->getTableName());
            $sourceTableAlias = $sqlWalker->getSQLTableAlias($class->getTableName(), $dqlAlias);

            $sql .= $quoteStrategy->getTableName($targetClass, $platform) . ' ' . $targetTableAlias . ' WHERE ';

            $owningAssoc = $targetClass->associationMappings[$assoc->mappedBy];
            assert($owningAssoc->isManyToOne());

            $first = true;

            foreach ($owningAssoc->targetToSourceKeyColumns as $targetColumn => $sourceColumn) {
                if ($first) {
                    $first = false;
                } else {
                    $sql .= ' AND ';
                }

                $sql .= $targetTableAlias . '.' . $sourceColumn
                      . ' = '
                      . $sourceTableAlias . '.' . $quoteStrategy->getColumnName($class->fieldNames[$targetColumn], $class, $platform);
            }
        } else { // many-to-many
            assert($assoc->isManyToMany());
            $owningAssoc = $entityManager->getMetadataFactory()->getOwningSide($assoc);
            $joinTable   = $owningAssoc->joinTable;

            // SQL table aliases
            $joinTableAlias   = $sqlWalker->getSQLTableAlias($joinTable->name);
            $sourceTableAlias = $sqlWalker->getSQLTableAlias($class->getTableName(), $dqlAlias);

            // join to target table
            $targetClass = $entityManager->getClassMetadata($assoc->targetEntity);
            $sql        .= $quoteStrategy->getJoinTableName($owningAssoc, $targetClass, $platform) . ' ' . $joinTableAlias . ' WHERE ';

            $joinColumns = $assoc->isOwningSide()
                ? $joinTable->joinColumns
                : $joinTable->inverseJoinColumns;

            $first = true;

            foreach ($joinColumns as $joinColumn) {
                if ($first) {
                    $first = false;
                } else {
                    $sql .= ' AND ';
                }

                $sourceColumnName = $quoteStrategy->getColumnName(
                    $class->fieldNames[$joinColumn->referencedColumnName],
                    $class,
                    $platform,
                );

                $sql .= $joinTableAlias . '.' . $joinColumn->name
                      . ' = '
                      . $sourceTableAlias . '.' . $sourceColumnName;
            }
        }

        return '(' . $sql . ')';
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->collectionPathExpression = $parser->CollectionValuedPathExpression();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
