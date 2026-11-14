<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Override;

use function array_diff;
use function array_keys;
use function sprintf;

/**
 * An adapter implementation of the TreeWalker interface. The methods in this class
 * are empty. This class exists as convenience for creating tree walkers.
 *
 * @phpstan-import-type QueryComponent from Parser
 */
abstract class TreeWalkerAdapter implements TreeWalker
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __construct(
        private readonly AbstractQuery $query,
        private readonly ParserResult $parserResult,
        private array $queryComponents,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getQueryComponents(): array
    {
        return $this->queryComponents;
    }

    #[Override]
    public function walkSelectStatement(AST\SelectStatement $selectStatement): void
    {
    }

    #[Override]
    public function walkUpdateStatement(AST\UpdateStatement $updateStatement): void
    {
    }

    #[Override]
    public function walkDeleteStatement(AST\DeleteStatement $deleteStatement): void
    {
    }

    /**
     * Sets or overrides a query component for a given dql alias.
     *
     * @phpstan-param QueryComponent $queryComponent
     */
    protected function setQueryComponent(string $dqlAlias, array $queryComponent): void
    {
        $requiredKeys = ['metadata', 'parent', 'relation', 'map', 'nestingLevel', 'token'];

        if (array_diff($requiredKeys, array_keys($queryComponent))) {
            throw QueryException::invalidQueryComponent($dqlAlias);
        }

        $this->queryComponents[$dqlAlias] = $queryComponent;
    }

    /**
     * Retrieves the Query Instance responsible for the current walkers execution.
     */
    protected function _getQuery(): AbstractQuery
    {
        return $this->query;
    }

    /**
     * Retrieves the ParserResult.
     */
    protected function _getParserResult(): ParserResult
    {
        return $this->parserResult;
    }

    protected function getMetadataForDqlAlias(string $dqlAlias): ClassMetadata
    {
        return $this->queryComponents[$dqlAlias]['metadata']
            ?? throw new LogicException(sprintf('No metadata for DQL alias: %s', $dqlAlias));
    }
}
