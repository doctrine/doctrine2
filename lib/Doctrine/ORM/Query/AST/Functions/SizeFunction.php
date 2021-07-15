<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * "SIZE" "(" CollectionValuedPathExpression ")"
 *
 * @link    www.doctrine-project.org
 */
class SizeFunction extends FunctionNode
{
    /** @var PathExpression */
    public $collectionPathExpression;

    /**
     * @override
     * @inheritdoc
     * @todo If the collection being counted is already joined, the SQL can be simpler (more efficient).
     */
    public function getSql(SqlWalker $sqlWalker)
    {
        $platform      = $sqlWalker->getEntityManager()->getConnection()->getDatabasePlatform();
        $quoteStrategy = $sqlWalker->getEntityManager()->getConfiguration()->getQuoteStrategy();
        $dqlAlias      = $this->collectionPathExpression->identificationVariable;
        $assocField    = $this->collectionPathExpression->field;

        $qComp = $sqlWalker->getQueryComponent($dqlAlias);
        $class = $qComp['metadata'];
        $assoc = $class->associationMappings[$assocField];
        $sql   = 'SELECT COUNT(*) FROM ';

        if ($assoc['type'] === ClassMetadata::ONE_TO_MANY) {
            $targetClass      = $sqlWalker->getEntityManager()->getClassMetadata($assoc['targetEntity']);
            $targetTableAlias = $sqlWalker->getSQLTableAlias($targetClass->getTableName());
            $sourceTableAlias = $sqlWalker->getSQLTableAlias($class->getTableName(), $dqlAlias);

            $sql .= $quoteStrategy->getTableName($targetClass, $platform) . ' ' . $targetTableAlias . ' WHERE ';

            $owningAssoc = $targetClass->associationMappings[$assoc['mappedBy']];

            $first = true;

            foreach ($owningAssoc['targetToSourceKeyColumns'] as $targetColumn => $sourceColumn) {
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
            $targetClass = $sqlWalker->getEntityManager()->getClassMetadata($assoc['targetEntity']);

            $owningAssoc = $assoc['isOwningSide'] ? $assoc : $targetClass->associationMappings[$assoc['mappedBy']];
            $joinTable   = $owningAssoc['joinTable'];

            // SQL table aliases
            $joinTableAlias   = $sqlWalker->getSQLTableAlias($joinTable['name']);
            $sourceTableAlias = $sqlWalker->getSQLTableAlias($class->getTableName(), $dqlAlias);

            // join to target table
            $sql .= $quoteStrategy->getJoinTableName($owningAssoc, $targetClass, $platform) . ' ' . $joinTableAlias . ' WHERE ';

            $joinColumns = $assoc['isOwningSide']
                ? $joinTable['joinColumns']
                : $joinTable['inverseJoinColumns'];

            $first = true;

            foreach ($joinColumns as $joinColumn) {
                if ($first) {
                    $first = false;
                } else {
                    $sql .= ' AND ';
                }

                $sourceColumnName = $quoteStrategy->getColumnName(
                    $class->fieldNames[$joinColumn['referencedColumnName']],
                    $class,
                    $platform
                );

                $sql .= $joinTableAlias . '.' . $joinColumn['name']
                      . ' = '
                      . $sourceTableAlias . '.' . $sourceColumnName;
            }
        }

        return '(' . $sql . ')';
    }

    /**
     * @override
     * @inheritdoc
     */
    public function parse(Parser $parser)
    {
        $parser->match(Lexer::T_IDENTIFIER);
        $parser->match(Lexer::T_OPEN_PARENTHESIS);

        $this->collectionPathExpression = $parser->CollectionValuedPathExpression();

        $parser->match(Lexer::T_CLOSE_PARENTHESIS);
    }
}
