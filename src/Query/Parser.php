<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\Common\Lexer\Token;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\DuplicateFieldException;
use Doctrine\ORM\Exception\NoMatchingPropertyException;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST\Functions;
use LogicException;
use ReflectionClass;

use function array_intersect;
use function array_key_exists;
use function array_search;
use function assert;
use function class_exists;
use function count;
use function implode;
use function in_array;
use function interface_exists;
use function is_string;
use function sprintf;
use function str_contains;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * An LL(*) recursive-descent parser for the context-free grammar of the Doctrine Query Language.
 * Parses a DQL query, reports any errors in it, and generates an AST.
 *
 * @phpstan-type DqlToken = Token<TokenType, string>
 * @phpstan-type QueryComponent = array{
 *                 metadata?: ClassMetadata<object>,
 *                 parent?: string|null,
 *                 relation?: AssociationMapping|null,
 *                 map?: string|null,
 *                 resultVariable?: AST\Node|string,
 *                 nestingLevel: int,
 *                 token: DqlToken,
 *             }
 */
final class Parser
{
    /**
     * @readonly Maps BUILT-IN string function names to AST class names.
     * @var array<string, class-string<Functions\FunctionNode>>
     */
    private static array $stringFunctions = [
        'concat'    => Functions\ConcatFunction::class,
        'substring' => Functions\SubstringFunction::class,
        'trim'      => Functions\TrimFunction::class,
        'lower'     => Functions\LowerFunction::class,
        'upper'     => Functions\UpperFunction::class,
        'identity'  => Functions\IdentityFunction::class,
    ];

    /**
     * @readonly Maps BUILT-IN numeric function names to AST class names.
     * @var array<string, class-string<Functions\FunctionNode>>
     */
    private static array $numericFunctions = [
        'length'    => Functions\LengthFunction::class,
        'locate'    => Functions\LocateFunction::class,
        'abs'       => Functions\AbsFunction::class,
        'sqrt'      => Functions\SqrtFunction::class,
        'mod'       => Functions\ModFunction::class,
        'size'      => Functions\SizeFunction::class,
        'date_diff' => Functions\DateDiffFunction::class,
        'bit_and'   => Functions\BitAndFunction::class,
        'bit_or'    => Functions\BitOrFunction::class,

        // Aggregate functions
        'min'       => Functions\MinFunction::class,
        'max'       => Functions\MaxFunction::class,
        'avg'       => Functions\AvgFunction::class,
        'sum'       => Functions\SumFunction::class,
        'count'     => Functions\CountFunction::class,
    ];

    /**
     * @readonly Maps BUILT-IN datetime function names to AST class names.
     * @var array<string, class-string<Functions\FunctionNode>>
     */
    private static array $datetimeFunctions = [
        'current_date'      => Functions\CurrentDateFunction::class,
        'current_time'      => Functions\CurrentTimeFunction::class,
        'current_timestamp' => Functions\CurrentTimestampFunction::class,
        'date_add'          => Functions\DateAddFunction::class,
        'date_sub'          => Functions\DateSubFunction::class,
    ];

    /*
     * Expressions that were encountered during parsing of identifiers and expressions
     * and still need to be validated.
     */

    /** @phpstan-var list<array{token: DqlToken|null, expression: mixed, nestingLevel: int}> */
    private array $deferredIdentificationVariables = [];

    /** @phpstan-var list<array{token: DqlToken|null, expression: AST\PartialObjectExpression, nestingLevel: int}> */
    private array $deferredPartialObjectExpressions = [];

    /** @phpstan-var list<array{token: DqlToken|null, expression: AST\PathExpression, nestingLevel: int}> */
    private array $deferredPathExpressions = [];

    /** @phpstan-var list<array{token: DqlToken|null, expression: mixed, nestingLevel: int}> */
    private array $deferredResultVariables = [];

    /** @phpstan-var list<array{token: DqlToken|null, expression: AST\NewObjectExpression, nestingLevel: int}> */
    private array $deferredNewObjectExpressions = [];

    /**
     * The lexer.
     */
    private readonly Lexer $lexer;

    /**
     * The parser result.
     */
    private readonly ParserResult $parserResult;

    /**
     * The EntityManager.
     */
    private readonly EntityManagerInterface $em;

    /**
     * Map of declared query components in the parsed query.
     *
     * @phpstan-var array<string, QueryComponent>
     */
    private array $queryComponents = [];

    /**
     * Keeps the nesting level of defined ResultVariables.
     */
    private int $nestingLevel = 0;

    /**
     * Keeps the nesting level of subqueries in JOIN WITH conditions.
     */
    private int $withJoinConditionNestingLevel = 0;

    /**
     * Any additional custom tree walkers that modify the AST.
     *
     * @var list<class-string<TreeWalker>>
     */
    private array $customTreeWalkers = [];

    /**
     * The custom last tree walker, if any, that is responsible for producing the output.
     *
     * @var class-string<OutputWalker>|null
     */
    private $customOutputWalker;

    /** @phpstan-var array<string, AST\SelectExpression> */
    private array $identVariableExpressions = [];

    /**
     * Creates a new query parser object.
     *
     * @param Query $query The Query to parse.
     */
    public function __construct(private readonly Query $query)
    {
        $this->em           = $query->getEntityManager();
        $this->lexer        = new Lexer((string) $query->getDQL());
        $this->parserResult = new ParserResult();
    }

    /**
     * Adds a custom tree walker for modifying the AST.
     *
     * @param class-string<TreeWalker> $className
     */
    public function addCustomTreeWalker(string $className): void
    {
        $this->customTreeWalkers[] = $className;
    }

    /**
     * Gets the lexer used by the parser.
     */
    public function getLexer(): Lexer
    {
        return $this->lexer;
    }

    /**
     * Gets the ParserResult that is being filled with information during parsing.
     */
    public function getParserResult(): ParserResult
    {
        return $this->parserResult;
    }

    /**
     * Gets the EntityManager used by the parser.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    /**
     * Parses and builds AST for the given Query.
     */
    public function getAST(): AST\SelectStatement|AST\UpdateStatement|AST\DeleteStatement
    {
        // Parse & build AST
        $AST = $this->QueryLanguage();

        // Process any deferred validations of some nodes in the AST.
        // This also allows post-processing of the AST for modification purposes.
        $this->processDeferredIdentificationVariables();

        if ($this->deferredPartialObjectExpressions) {
            $this->processDeferredPartialObjectExpressions();
        }

        if ($this->deferredPathExpressions) {
            $this->processDeferredPathExpressions();
        }

        if ($this->deferredResultVariables) {
            $this->processDeferredResultVariables();
        }

        if ($this->deferredNewObjectExpressions) {
            $this->processDeferredNewObjectExpressions($AST);
        }

        $this->processRootEntityAliasSelected();

        // TODO: Is there a way to remove this? It may impact the mixed hydration resultset a lot!
        $this->fixIdentificationVariableOrder($AST);

        return $AST;
    }

    /**
     * Attempts to match the given token with the current lookahead token.
     *
     * If they match, updates the lookahead token; otherwise raises a syntax
     * error.
     *
     * @throws QueryException If the tokens don't match.
     */
    public function match(TokenType $token): void
    {
        $lookaheadType = $this->lexer->lookahead->type ?? null;

        // Short-circuit on first condition, usually types match
        if ($lookaheadType === $token) {
            $this->lexer->moveNext();

            return;
        }

        // If parameter is not identifier (1-99) must be exact match
        if ($token->value < TokenType::T_IDENTIFIER->value) {
            $this->syntaxError($this->lexer->getLiteral($token));
        }

        // If parameter is keyword (200+) must be exact match
        if ($token->value > TokenType::T_IDENTIFIER->value) {
            $this->syntaxError($this->lexer->getLiteral($token));
        }

        // If parameter is T_IDENTIFIER, then matches T_IDENTIFIER (100) and keywords (200+)
        if ($token->value === TokenType::T_IDENTIFIER->value && $lookaheadType->value < TokenType::T_IDENTIFIER->value) {
            $this->syntaxError($this->lexer->getLiteral($token));
        }

        $this->lexer->moveNext();
    }

    /**
     * Frees this parser, enabling it to be reused.
     *
     * @param bool $deep     Whether to clean peek and reset errors.
     * @param int  $position Position to reset.
     */
    public function free(bool $deep = false, int $position = 0): void
    {
        // WARNING! Use this method with care. It resets the scanner!
        $this->lexer->resetPosition($position);

        // Deep = true cleans peek and also any previously defined errors
        if ($deep) {
            $this->lexer->resetPeek();
        }

        $this->lexer->token     = null;
        $this->lexer->lookahead = null;
    }

    /**
     * Parses a query string.
     */
    public function parse(): ParserResult
    {
        $AST = $this->getAST();

        $customWalkers = $this->query->getHint(Query::HINT_CUSTOM_TREE_WALKERS);
        if ($customWalkers !== false) {
            $this->customTreeWalkers = $customWalkers;
        }

        $customOutputWalker = $this->query->getHint(Query::HINT_CUSTOM_OUTPUT_WALKER);
        if ($customOutputWalker !== false) {
            $this->customOutputWalker = $customOutputWalker;
        }

        // Run any custom tree walkers over the AST
        if ($this->customTreeWalkers) {
            $treeWalkerChain = new TreeWalkerChain($this->query, $this->parserResult, $this->queryComponents);

            foreach ($this->customTreeWalkers as $walker) {
                $treeWalkerChain->addTreeWalker($walker);
            }

            match (true) {
                $AST instanceof AST\UpdateStatement => $treeWalkerChain->walkUpdateStatement($AST),
                $AST instanceof AST\DeleteStatement => $treeWalkerChain->walkDeleteStatement($AST),
                $AST instanceof AST\SelectStatement => $treeWalkerChain->walkSelectStatement($AST),
            };

            $this->queryComponents = $treeWalkerChain->getQueryComponents();
        }

        $outputWalkerClass = $this->customOutputWalker ?: SqlOutputWalker::class;
        $outputWalker      = new $outputWalkerClass($this->query, $this->parserResult, $this->queryComponents);

        $this->parserResult->setSqlFinalizer($outputWalker->getFinalizer($AST));

        return $this->parserResult;
    }

    /**
     * Fixes order of identification variables.
     *
     * They have to appear in the select clause in the same order as the
     * declarations (from ... x join ... y join ... z ...) appear in the query
     * as the hydration process relies on that order for proper operation.
     */
    private function fixIdentificationVariableOrder(AST\SelectStatement|AST\DeleteStatement|AST\UpdateStatement $AST): void
    {
        if (count($this->identVariableExpressions) <= 1) {
            return;
        }

        assert($AST instanceof AST\SelectStatement);

        foreach ($this->queryComponents as $dqlAlias => $qComp) {
            if (! isset($this->identVariableExpressions[$dqlAlias])) {
                continue;
            }

            $expr = $this->identVariableExpressions[$dqlAlias];
            $key  = array_search($expr, $AST->selectClause->selectExpressions, true);

            unset($AST->selectClause->selectExpressions[$key]);

            $AST->selectClause->selectExpressions[] = $expr;
        }
    }

    /**
     * Generates a new syntax error.
     *
     * @param string        $expected Expected string.
     * @param DqlToken|null $token    Got token.
     *
     * @throws QueryException
     */
    public function syntaxError(string $expected = '', Token|null $token = null): never
    {
        if ($token === null) {
            $token = $this->lexer->lookahead;
        }

        $tokenPos = $token->position ?? '-1';

        $message  = sprintf('line 0, col %d: Error: ', $tokenPos);
        $message .= $expected !== '' ? sprintf('Expected %s, got ', $expected) : 'Unexpected ';
        $message .= $this->lexer->lookahead === null ? 'end of string.' : sprintf("'%s'", $token->value);

        throw QueryException::syntaxError($message, QueryException::dqlError($this->query->getDQL() ?? ''));
    }

    /**
     * Generates a new semantical error.
     *
     * @param string $message Optional message.
     * @phpstan-param DqlToken|null $token
     *
     * @throws QueryException
     */
    public function semanticalError(string $message = '', Token|null $token = null): never
    {
        if ($token === null) {
            $token = $this->lexer->lookahead ?? new Token('fake token', 42, 0);
        }

        // Minimum exposed chars ahead of token
        $distance = 12;

        // Find a position of a final word to display in error string
        $dql    = $this->query->getDQL();
        $length = strlen($dql);
        $pos    = $token->position + $distance;
        $pos    = strpos($dql, ' ', $length > $pos ? $pos : $length);
        $length = $pos !== false ? $pos - $token->position : $distance;

        $tokenPos = $token->position > 0 ? $token->position : '-1';
        $tokenStr = substr($dql, $token->position, $length);

        // Building informative message
        $message = 'line 0, col ' . $tokenPos . " near '" . $tokenStr . "': Error: " . $message;

        throw QueryException::semanticalError($message, QueryException::dqlError($this->query->getDQL()));
    }

    /**
     * Peeks beyond the matched closing parenthesis and returns the first token after that one.
     *
     * @param bool $resetPeek Reset peek after finding the closing parenthesis.
     *
     * @phpstan-return DqlToken|null
     */
    private function peekBeyondClosingParenthesis(bool $resetPeek = true): Token|null
    {
        $token        = $this->lexer->peek();
        $numUnmatched = 1;

        while ($numUnmatched > 0 && $token !== null) {
            switch ($token->type) {
                case TokenType::T_OPEN_PARENTHESIS:
                    ++$numUnmatched;
                    break;

                case TokenType::T_CLOSE_PARENTHESIS:
                    --$numUnmatched;
                    break;

                default:
                    // Do nothing
            }

            $token = $this->lexer->peek();
        }

        if ($resetPeek) {
            $this->lexer->resetPeek();
        }

        return $token;
    }

    /**
     * Checks if the given token indicates a mathematical operator.
     *
     * @phpstan-param DqlToken|null $token
     */
    private function isMathOperator(Token|null $token): bool
    {
        return $token !== null && in_array($token->type, [TokenType::T_PLUS, TokenType::T_MINUS, TokenType::T_DIVIDE, TokenType::T_MULTIPLY], true);
    }

    /**
     * Checks if the next-next (after lookahead) token starts a function.
     *
     * @return bool TRUE if the next-next tokens start a function, FALSE otherwise.
     */
    private function isFunction(): bool
    {
        assert($this->lexer->lookahead !== null);
        $lookaheadType = $this->lexer->lookahead->type;
        $peek          = $this->lexer->peek();

        $this->lexer->resetPeek();

        return $lookaheadType->value >= TokenType::T_IDENTIFIER->value && $peek !== null && $peek->type === TokenType::T_OPEN_PARENTHESIS;
    }

    /**
     * Checks whether the given token type indicates an aggregate function.
     *
     * @return bool TRUE if the token type is an aggregate function, FALSE otherwise.
     */
    private function isAggregateFunction(TokenType $tokenType): bool
    {
        return in_array(
            $tokenType,
            [TokenType::T_AVG, TokenType::T_MIN, TokenType::T_MAX, TokenType::T_SUM, TokenType::T_COUNT],
            true,
        );
    }

    /**
     * Checks whether the current lookahead token of the lexer has the type T_ALL, T_ANY or T_SOME.
     */
    private function isNextAllAnySome(): bool
    {
        assert($this->lexer->lookahead !== null);

        return in_array(
            $this->lexer->lookahead->type,
            [TokenType::T_ALL, TokenType::T_ANY, TokenType::T_SOME],
            true,
        );
    }

    /**
     * Validates that the given <tt>IdentificationVariable</tt> is semantically correct.
     * It must exist in query components list.
     */
    private function processDeferredIdentificationVariables(): void
    {
        foreach ($this->deferredIdentificationVariables as $deferredItem) {
            $identVariable = $deferredItem['expression'];

            // Check if IdentificationVariable exists in queryComponents
            if (! isset($this->queryComponents[$identVariable])) {
                $this->semanticalError(
                    sprintf("'%s' is not defined.", $identVariable),
                    $deferredItem['token'],
                );
            }

            $qComp = $this->queryComponents[$identVariable];

            // Check if queryComponent points to an AbstractSchemaName or a ResultVariable
            if (! isset($qComp['metadata'])) {
                $this->semanticalError(
                    sprintf("'%s' does not point to a Class.", $identVariable),
                    $deferredItem['token'],
                );
            }

            // Validate if identification variable nesting level is lower or equal than the current one
            if ($qComp['nestingLevel'] > $deferredItem['nestingLevel']) {
                $this->semanticalError(
                    sprintf("'%s' is used outside the scope of its declaration.", $identVariable),
                    $deferredItem['token'],
                );
            }
        }
    }

    /**
     * Validates that the given <tt>NewObjectExpression</tt>.
     */
    private function processDeferredNewObjectExpressions(AST\SelectStatement $AST): void
    {
        foreach ($this->deferredNewObjectExpressions as $deferredItem) {
            $expression    = $deferredItem['expression'];
            $token         = $deferredItem['token'];
            $className     = $expression->className;
            $args          = $expression->args;
            $fromClassName = $AST->fromClause->identificationVariableDeclarations[0]->rangeVariableDeclaration->abstractSchemaName ?? null;

            // If the namespace is not given then assumes the first FROM entity namespace
            if (! str_contains($className, '\\') && ! class_exists($className) && is_string($fromClassName) && str_contains($fromClassName, '\\')) {
                $namespace = substr($fromClassName, 0, strrpos($fromClassName, '\\'));
                $fqcn      = $namespace . '\\' . $className;

                if (class_exists($fqcn)) {
                    $expression->className = $fqcn;
                    $className             = $fqcn;
                }
            }

            if (! class_exists($className)) {
                $this->semanticalError(sprintf('Class "%s" is not defined.', $className), $token);
            }

            $class = new ReflectionClass($className);

            if (! $class->isInstantiable()) {
                $this->semanticalError(sprintf('Class "%s" can not be instantiated.', $className), $token);
            }

            if ($class->getConstructor() === null) {
                $this->semanticalError(sprintf('Class "%s" has not a valid constructor.', $className), $token);
            }

            if ($class->getConstructor()->getNumberOfRequiredParameters() > count($args)) {
                $this->semanticalError(sprintf('Number of arguments does not match with "%s" constructor declaration.', $className), $token);
            }
        }
    }

    /**
     * Validates that the given <tt>PartialObjectExpression</tt> is semantically correct.
     * It must exist in query components list.
     */
    private function processDeferredPartialObjectExpressions(): void
    {
        foreach ($this->deferredPartialObjectExpressions as $deferredItem) {
            $expr  = $deferredItem['expression'];
            $class = $this->getMetadataForDqlAlias($expr->identificationVariable);

            foreach ($expr->partialFieldSet as $field) {
                if (isset($class->fieldMappings[$field])) {
                    continue;
                }

                if (
                    isset($class->associationMappings[$field]) &&
                    $class->associationMappings[$field]->isToOneOwningSide()
                ) {
                    continue;
                }

                $this->semanticalError(sprintf(
                    "There is no mapped field named '%s' on class %s.",
                    $field,
                    $class->name,
                ), $deferredItem['token']);
            }

            if (array_intersect($class->identifier, $expr->partialFieldSet) !== $class->identifier) {
                $this->semanticalError(
                    'The partial field selection of class ' . $class->name . ' must contain the identifier.',
                    $deferredItem['token'],
                );
            }
        }
    }

    /**
     * Validates that the given <tt>ResultVariable</tt> is semantically correct.
     * It must exist in query components list.
     */
    private function processDeferredResultVariables(): void
    {
        foreach ($this->deferredResultVariables as $deferredItem) {
            $resultVariable = $deferredItem['expression'];

            // Check if ResultVariable exists in queryComponents
            if (! isset($this->queryComponents[$resultVariable])) {
                $this->semanticalError(
                    sprintf("'%s' is not defined.", $resultVariable),
                    $deferredItem['token'],
                );
            }

            $qComp = $this->queryComponents[$resultVariable];

            // Check if queryComponent points to an AbstractSchemaName or a ResultVariable
            if (! isset($qComp['resultVariable'])) {
                $this->semanticalError(
                    sprintf("'%s' does not point to a ResultVariable.", $resultVariable),
                    $deferredItem['token'],
                );
            }

            // Validate if identification variable nesting level is lower or equal than the current one
            if ($qComp['nestingLevel'] > $deferredItem['nestingLevel']) {
                $this->semanticalError(
                    sprintf("'%s' is used outside the scope of its declaration.", $resultVariable),
                    $deferredItem['token'],
                );
            }
        }
    }

    /**
     * Validates that the given <tt>PathExpression</tt> is semantically correct for grammar rules:
     *
     * AssociationPathExpression             ::= CollectionValuedPathExpression | SingleValuedAssociationPathExpression
     * SingleValuedPathExpression            ::= StateFieldPathExpression | SingleValuedAssociationPathExpression
     * StateFieldPathExpression              ::= IdentificationVariable "." StateField
     * SingleValuedAssociationPathExpression ::= IdentificationVariable "." SingleValuedAssociationField
     * CollectionValuedPathExpression        ::= IdentificationVariable "." CollectionValuedAssociationField
     */
    private function processDeferredPathExpressions(): void
    {
        foreach ($this->deferredPathExpressions as $deferredItem) {
            $pathExpression = $deferredItem['expression'];

            $class = $this->getMetadataForDqlAlias($pathExpression->identificationVariable);

            $field = $pathExpression->field;
            if ($field === null) {
                $field = $pathExpression->field = $class->identifier[0];
            }

            // Check if field or association exists
            if (! isset($class->associationMappings[$field]) && ! isset($class->fieldMappings[$field])) {
                $this->semanticalError(
                    'Class ' . $class->name . ' has no field or association named ' . $field,
                    $deferredItem['token'],
                );
            }

            $fieldType = AST\PathExpression::TYPE_STATE_FIELD;

            if (isset($class->associationMappings[$field])) {
                $assoc = $class->associationMappings[$field];

                $fieldType = $assoc->isToOne()
                    ? AST\PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION
                    : AST\PathExpression::TYPE_COLLECTION_VALUED_ASSOCIATION;
            }

            // Validate if PathExpression is one of the expected types
            $expectedType = $pathExpression->expectedType;

            if (! ($expectedType & $fieldType)) {
                // We need to recognize which was expected type(s)
                $expectedStringTypes = [];

                // Validate state field type
                if ($expectedType & AST\PathExpression::TYPE_STATE_FIELD) {
                    $expectedStringTypes[] = 'StateFieldPathExpression';
                }

                // Validate single valued association (*-to-one)
                if ($expectedType & AST\PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION) {
                    $expectedStringTypes[] = 'SingleValuedAssociationField';
                }

                // Validate single valued association (*-to-many)
                if ($expectedType & AST\PathExpression::TYPE_COLLECTION_VALUED_ASSOCIATION) {
                    $expectedStringTypes[] = 'CollectionValuedAssociationField';
                }

                // Build the error message
                $semanticalError  = 'Invalid PathExpression. ';
                $semanticalError .= count($expectedStringTypes) === 1
                    ? 'Must be a ' . $expectedStringTypes[0] . '.'
                    : implode(' or ', $expectedStringTypes) . ' expected.';

                $this->semanticalError($semanticalError, $deferredItem['token']);
            }

            // We need to force the type in PathExpression
            $pathExpression->type = $fieldType;
        }
    }

    private function processRootEntityAliasSelected(): void
    {
        if (! count($this->identVariableExpressions)) {
            return;
        }

        foreach ($this->identVariableExpressions as $dqlAlias => $expr) {
            if (isset($this->queryComponents[$dqlAlias]) && ! isset($this->queryComponents[$dqlAlias]['parent'])) {
                return;
            }
        }

        $this->semanticalError('Cannot select entity through identification variables without choosing at least one root entity alias.');
    }

    /**
     * QueryLanguage ::= SelectStatement | UpdateStatement | DeleteStatement
     */
    public function QueryLanguage(): AST\SelectStatement|AST\UpdateStatement|AST\DeleteStatement
    {
        $statement = null;

        $this->lexer->moveNext();

        switch ($this->lexer->lookahead->type ?? null) {
            case TokenType::T_SELECT:
                $statement = $this->SelectStatement();
                break;

            case TokenType::T_UPDATE:
                $statement = $this->UpdateStatement();
                break;

            case TokenType::T_DELETE:
                $statement = $this->DeleteStatement();
                break;

            default:
                $this->syntaxError('SELECT, UPDATE or DELETE');
                break;
        }

        // Check for end of string
        if ($this->lexer->lookahead !== null) {
            $this->syntaxError('end of string');
        }

        return $statement;
    }

    /**
     * SelectStatement ::= SelectClause FromClause [WhereClause] [GroupByClause] [HavingClause] [OrderByClause]
     */
    public function SelectStatement(): AST\SelectStatement
    {
        $selectStatement = new AST\SelectStatement($this->SelectClause(), $this->FromClause());

        $selectStatement->whereClause   = $this->lexer->isNextToken(TokenType::T_WHERE) ? $this->WhereClause() : null;
        $selectStatement->groupByClause = $this->lexer->isNextToken(TokenType::T_GROUP) ? $this->GroupByClause() : null;
        $selectStatement->havingClause  = $this->lexer->isNextToken(TokenType::T_HAVING) ? $this->HavingClause() : null;
        $selectStatement->orderByClause = $this->lexer->isNextToken(TokenType::T_ORDER) ? $this->OrderByClause() : null;

        return $selectStatement;
    }

    /**
     * UpdateStatement ::= UpdateClause [WhereClause]
     */
    public function UpdateStatement(): AST\UpdateStatement
    {
        $updateStatement = new AST\UpdateStatement($this->UpdateClause());

        $updateStatement->whereClause = $this->lexer->isNextToken(TokenType::T_WHERE) ? $this->WhereClause() : null;

        return $updateStatement;
    }

    /**
     * DeleteStatement ::= DeleteClause [WhereClause]
     */
    public function DeleteStatement(): AST\DeleteStatement
    {
        $deleteStatement = new AST\DeleteStatement($this->DeleteClause());

        $deleteStatement->whereClause = $this->lexer->isNextToken(TokenType::T_WHERE) ? $this->WhereClause() : null;

        return $deleteStatement;
    }

    /**
     * IdentificationVariable ::= identifier
     */
    public function IdentificationVariable(): string
    {
        $this->match(TokenType::T_IDENTIFIER);

        assert($this->lexer->token !== null);
        $identVariable = $this->lexer->token->value;

        $this->deferredIdentificationVariables[] = [
            'expression'   => $identVariable,
            'nestingLevel' => $this->nestingLevel,
            'token'        => $this->lexer->token,
        ];

        return $identVariable;
    }

    /**
     * AliasIdentificationVariable = identifier
     */
    public function AliasIdentificationVariable(): string
    {
        $this->match(TokenType::T_IDENTIFIER);

        assert($this->lexer->token !== null);
        $aliasIdentVariable = $this->lexer->token->value;
        $exists             = isset($this->queryComponents[$aliasIdentVariable]);

        if ($exists) {
            $this->semanticalError(
                sprintf("'%s' is already defined.", $aliasIdentVariable),
                $this->lexer->token,
            );
        }

        return $aliasIdentVariable;
    }

    /**
     * AbstractSchemaName ::= fully_qualified_name | identifier
     */
    public function AbstractSchemaName(): string
    {
        if ($this->lexer->isNextToken(TokenType::T_FULLY_QUALIFIED_NAME)) {
            $this->match(TokenType::T_FULLY_QUALIFIED_NAME);
            assert($this->lexer->token !== null);

            return $this->lexer->token->value;
        }

        $this->match(TokenType::T_IDENTIFIER);
        assert($this->lexer->token !== null);

        return $this->lexer->token->value;
    }

    /**
     * Validates an AbstractSchemaName, making sure the class exists.
     *
     * @param string $schemaName The name to validate.
     *
     * @throws QueryException if the name does not exist.
     */
    private function validateAbstractSchemaName(string $schemaName): void
    {
        assert($this->lexer->token !== null);
        if (! (class_exists($schemaName, true) || interface_exists($schemaName, true))) {
            $this->semanticalError(
                sprintf("Class '%s' is not defined.", $schemaName),
                $this->lexer->token,
            );
        }
    }

    /**
     * AliasResultVariable ::= identifier
     */
    public function AliasResultVariable(): string
    {
        $this->match(TokenType::T_IDENTIFIER);

        assert($this->lexer->token !== null);
        $resultVariable = $this->lexer->token->value;
        $exists         = isset($this->queryComponents[$resultVariable]);

        if ($exists) {
            $this->semanticalError(
                sprintf("'%s' is already defined.", $resultVariable),
                $this->lexer->token,
            );
        }

        return $resultVariable;
    }

    /**
     * ResultVariable ::= identifier
     */
    public function ResultVariable(): string
    {
        $this->match(TokenType::T_IDENTIFIER);

        assert($this->lexer->token !== null);
        $resultVariable = $this->lexer->token->value;

        // Defer ResultVariable validation
        $this->deferredResultVariables[] = [
            'expression'   => $resultVariable,
            'nestingLevel' => $this->nestingLevel,
            'token'        => $this->lexer->token,
        ];

        return $resultVariable;
    }

    /**
     * JoinAssociationPathExpression ::= IdentificationVariable "." (CollectionValuedAssociationField | SingleValuedAssociationField)
     */
    public function JoinAssociationPathExpression(): AST\JoinAssociationPathExpression
    {
        $identVariable = $this->IdentificationVariable();

        if (! isset($this->queryComponents[$identVariable])) {
            $this->semanticalError(
                'Identification Variable ' . $identVariable . ' used in join path expression but was not defined before.',
            );
        }

        $this->match(TokenType::T_DOT);
        $this->match(TokenType::T_IDENTIFIER);

        assert($this->lexer->token !== null);
        $field = $this->lexer->token->value;

        // Validate association field
        $class = $this->getMetadataForDqlAlias($identVariable);

        if (! $class->hasAssociation($field)) {
            $this->semanticalError('Class ' . $class->name . ' has no association named ' . $field);
        }

        return new AST\JoinAssociationPathExpression($identVariable, $field);
    }

    /**
     * Parses an arbitrary path expression and defers semantical validation
     * based on expected types.
     *
     * PathExpression ::= IdentificationVariable {"." identifier}*
     *
     * @phpstan-param int-mask-of<AST\PathExpression::TYPE_*> $expectedTypes
     */
    public function PathExpression(int $expectedTypes): AST\PathExpression
    {
        $identVariable = $this->IdentificationVariable();
        $field         = null;

        assert($this->lexer->token !== null);
        if ($this->lexer->isNextToken(TokenType::T_DOT)) {
            $this->match(TokenType::T_DOT);
            $this->match(TokenType::T_IDENTIFIER);

            $field = $this->lexer->token->value;

            while ($this->lexer->isNextToken(TokenType::T_DOT)) {
                $this->match(TokenType::T_DOT);
                $this->match(TokenType::T_IDENTIFIER);
                $field .= '.' . $this->lexer->token->value;
            }
        }

        // Creating AST node
        $pathExpr = new AST\PathExpression($expectedTypes, $identVariable, $field);

        // Defer PathExpression validation if requested to be deferred
        $this->deferredPathExpressions[] = [
            'expression'   => $pathExpr,
            'nestingLevel' => $this->nestingLevel,
            'token'        => $this->lexer->token,
        ];

        return $pathExpr;
    }

    /**
     * AssociationPathExpression ::= CollectionValuedPathExpression | SingleValuedAssociationPathExpression
     */
    public function AssociationPathExpression(): AST\PathExpression
    {
        return $this->PathExpression(
            AST\PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION |
            AST\PathExpression::TYPE_COLLECTION_VALUED_ASSOCIATION,
        );
    }

    /**
     * SingleValuedPathExpression ::= StateFieldPathExpression | SingleValuedAssociationPathExpression
     */
    public function SingleValuedPathExpression(): AST\PathExpression
    {
        return $this->PathExpression(
            AST\PathExpression::TYPE_STATE_FIELD |
            AST\PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
        );
    }

    /**
     * StateFieldPathExpression ::= IdentificationVariable "." StateField
     */
    public function StateFieldPathExpression(): AST\PathExpression
    {
        return $this->PathExpression(AST\PathExpression::TYPE_STATE_FIELD);
    }

    /**
     * SingleValuedAssociationPathExpression ::= IdentificationVariable "." SingleValuedAssociationField
     */
    public function SingleValuedAssociationPathExpression(): AST\PathExpression
    {
        return $this->PathExpression(AST\PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION);
    }

    /**
     * CollectionValuedPathExpression ::= IdentificationVariable "." CollectionValuedAssociationField
     */
    public function CollectionValuedPathExpression(): AST\PathExpression
    {
        return $this->PathExpression(AST\PathExpression::TYPE_COLLECTION_VALUED_ASSOCIATION);
    }

    /**
     * EntityAsDtoArgumentExpression ::= IdentificationVariable
     */
    public function EntityAsDtoArgumentExpression(): AST\EntityAsDtoArgumentExpression
    {
        assert($this->lexer->lookahead !== null);
        $expression    = null;
        $identVariable = null;
        $peek          = $this->lexer->glimpse();
        $lookaheadType = $this->lexer->lookahead->type;
        assert($peek !== null);

        assert($lookaheadType === TokenType::T_IDENTIFIER);
        assert($peek->type !== TokenType::T_DOT);
        assert($peek->type !== TokenType::T_OPEN_PARENTHESIS);

        $expression = $identVariable = $this->IdentificationVariable();

        // [["AS"] AliasResultVariable]
        $mustHaveAliasResultVariable = false;

        if ($this->lexer->isNextToken(TokenType::T_AS)) {
            $this->match(TokenType::T_AS);

            $mustHaveAliasResultVariable = true;
        }

        $aliasResultVariable = null;

        if ($mustHaveAliasResultVariable || $this->lexer->isNextToken(TokenType::T_IDENTIFIER)) {
            $token               = $this->lexer->lookahead;
            $aliasResultVariable = $this->AliasResultVariable();

            // Include AliasResultVariable in query components.
            $this->queryComponents[$aliasResultVariable] = [
                'resultVariable' => $expression,
                'nestingLevel'   => $this->nestingLevel,
                'token'          => $token,
            ];
        }

        return new AST\EntityAsDtoArgumentExpression($expression, $identVariable, $aliasResultVariable);
    }

    /**
     * SelectClause ::= "SELECT" ["DISTINCT"] SelectExpression {"," SelectExpression}
     */
    public function SelectClause(): AST\SelectClause
    {
        $isDistinct = false;
        $this->match(TokenType::T_SELECT);

        // Check for DISTINCT
        if ($this->lexer->isNextToken(TokenType::T_DISTINCT)) {
            $this->match(TokenType::T_DISTINCT);

            $isDistinct = true;
        }

        // Process SelectExpressions (1..N)
        $selectExpressions   = [];
        $selectExpressions[] = $this->SelectExpression();

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);

            $selectExpressions[] = $this->SelectExpression();
        }

        return new AST\SelectClause($selectExpressions, $isDistinct);
    }

    /**
     * SimpleSelectClause ::= "SELECT" ["DISTINCT"] SimpleSelectExpression
     */
    public function SimpleSelectClause(): AST\SimpleSelectClause
    {
        $isDistinct = false;
        $this->match(TokenType::T_SELECT);

        if ($this->lexer->isNextToken(TokenType::T_DISTINCT)) {
            $this->match(TokenType::T_DISTINCT);

            $isDistinct = true;
        }

        return new AST\SimpleSelectClause($this->SimpleSelectExpression(), $isDistinct);
    }

    /**
     * UpdateClause ::= "UPDATE" AbstractSchemaName ["AS"] AliasIdentificationVariable "SET" UpdateItem {"," UpdateItem}*
     */
    public function UpdateClause(): AST\UpdateClause
    {
        $this->match(TokenType::T_UPDATE);
        assert($this->lexer->lookahead !== null);

        $token              = $this->lexer->lookahead;
        $abstractSchemaName = $this->AbstractSchemaName();

        $this->validateAbstractSchemaName($abstractSchemaName);

        if ($this->lexer->isNextToken(TokenType::T_AS)) {
            $this->match(TokenType::T_AS);
        }

        $aliasIdentificationVariable = $this->AliasIdentificationVariable();

        $class = $this->em->getClassMetadata($abstractSchemaName);

        // Building queryComponent
        $queryComponent = [
            'metadata'     => $class,
            'parent'       => null,
            'relation'     => null,
            'map'          => null,
            'nestingLevel' => $this->nestingLevel,
            'token'        => $token,
        ];

        $this->queryComponents[$aliasIdentificationVariable] = $queryComponent;

        $this->match(TokenType::T_SET);

        $updateItems   = [];
        $updateItems[] = $this->UpdateItem();

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);

            $updateItems[] = $this->UpdateItem();
        }

        $updateClause                              = new AST\UpdateClause($abstractSchemaName, $updateItems);
        $updateClause->aliasIdentificationVariable = $aliasIdentificationVariable;

        return $updateClause;
    }

    /**
     * DeleteClause ::= "DELETE" ["FROM"] AbstractSchemaName ["AS"] AliasIdentificationVariable
     */
    public function DeleteClause(): AST\DeleteClause
    {
        $this->match(TokenType::T_DELETE);

        if ($this->lexer->isNextToken(TokenType::T_FROM)) {
            $this->match(TokenType::T_FROM);
        }

        assert($this->lexer->lookahead !== null);
        $token              = $this->lexer->lookahead;
        $abstractSchemaName = $this->AbstractSchemaName();

        $this->validateAbstractSchemaName($abstractSchemaName);

        $deleteClause = new AST\DeleteClause($abstractSchemaName);

        if ($this->lexer->isNextToken(TokenType::T_AS)) {
            $this->match(TokenType::T_AS);
        }

        $aliasIdentificationVariable = $this->lexer->isNextToken(TokenType::T_IDENTIFIER)
            ? $this->AliasIdentificationVariable()
            : 'alias_should_have_been_set';

        $deleteClause->aliasIdentificationVariable = $aliasIdentificationVariable;
        $class                                     = $this->em->getClassMetadata($deleteClause->abstractSchemaName);

        // Building queryComponent
        $queryComponent = [
            'metadata'     => $class,
            'parent'       => null,
            'relation'     => null,
            'map'          => null,
            'nestingLevel' => $this->nestingLevel,
            'token'        => $token,
        ];

        $this->queryComponents[$aliasIdentificationVariable] = $queryComponent;

        return $deleteClause;
    }

    /**
     * FromClause ::= "FROM" IdentificationVariableDeclaration {"," IdentificationVariableDeclaration}*
     */
    public function FromClause(): AST\FromClause
    {
        $this->match(TokenType::T_FROM);

        $identificationVariableDeclarations   = [];
        $identificationVariableDeclarations[] = $this->IdentificationVariableDeclaration();

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);

            $identificationVariableDeclarations[] = $this->IdentificationVariableDeclaration();
        }

        return new AST\FromClause($identificationVariableDeclarations);
    }

    /**
     * SubselectFromClause ::= "FROM" SubselectIdentificationVariableDeclaration {"," SubselectIdentificationVariableDeclaration}*
     */
    public function SubselectFromClause(): AST\SubselectFromClause
    {
        $this->match(TokenType::T_FROM);

        $identificationVariables   = [];
        $identificationVariables[] = $this->SubselectIdentificationVariableDeclaration();

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);

            $identificationVariables[] = $this->SubselectIdentificationVariableDeclaration();
        }

        return new AST\SubselectFromClause($identificationVariables);
    }

    /**
     * WhereClause ::= "WHERE" ConditionalExpression
     */
    public function WhereClause(): AST\WhereClause
    {
        $this->match(TokenType::T_WHERE);

        return new AST\WhereClause($this->ConditionalExpression());
    }

    /**
     * HavingClause ::= "HAVING" ConditionalExpression
     */
    public function HavingClause(): AST\HavingClause
    {
        $this->match(TokenType::T_HAVING);

        return new AST\HavingClause($this->ConditionalExpression());
    }

    /**
     * GroupByClause ::= "GROUP" "BY" GroupByItem {"," GroupByItem}*
     */
    public function GroupByClause(): AST\GroupByClause
    {
        $this->match(TokenType::T_GROUP);
        $this->match(TokenType::T_BY);

        $groupByItems = [$this->GroupByItem()];

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);

            $groupByItems[] = $this->GroupByItem();
        }

        return new AST\GroupByClause($groupByItems);
    }

    /**
     * OrderByClause ::= "ORDER" "BY" OrderByItem {"," OrderByItem}*
     */
    public function OrderByClause(): AST\OrderByClause
    {
        $this->match(TokenType::T_ORDER);
        $this->match(TokenType::T_BY);

        $orderByItems   = [];
        $orderByItems[] = $this->OrderByItem();

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);

            $orderByItems[] = $this->OrderByItem();
        }

        return new AST\OrderByClause($orderByItems, $this->withJoinConditionNestingLevel === 0);
    }

    /**
     * Subselect ::= SimpleSelectClause SubselectFromClause [WhereClause] [GroupByClause] [HavingClause] [OrderByClause]
     */
    public function Subselect(): AST\Subselect
    {
        // Increase query nesting level
        $this->nestingLevel++;

        $subselect = new AST\Subselect($this->SimpleSelectClause(), $this->SubselectFromClause());

        $subselect->whereClause   = $this->lexer->isNextToken(TokenType::T_WHERE) ? $this->WhereClause() : null;
        $subselect->groupByClause = $this->lexer->isNextToken(TokenType::T_GROUP) ? $this->GroupByClause() : null;
        $subselect->havingClause  = $this->lexer->isNextToken(TokenType::T_HAVING) ? $this->HavingClause() : null;
        $subselect->orderByClause = $this->lexer->isNextToken(TokenType::T_ORDER) ? $this->OrderByClause() : null;

        // Decrease query nesting level
        $this->nestingLevel--;

        return $subselect;
    }

    /**
     * UpdateItem ::= SingleValuedPathExpression "=" NewValue
     */
    public function UpdateItem(): AST\UpdateItem
    {
        $pathExpr = $this->SingleValuedPathExpression();

        $this->match(TokenType::T_EQUALS);

        return new AST\UpdateItem($pathExpr, $this->NewValue());
    }

    /**
     * GroupByItem ::= IdentificationVariable | ResultVariable | SingleValuedPathExpression
     */
    public function GroupByItem(): string|AST\PathExpression
    {
        // We need to check if we are in a IdentificationVariable or SingleValuedPathExpression
        $glimpse = $this->lexer->glimpse();

        if ($glimpse !== null && $glimpse->type === TokenType::T_DOT) {
            return $this->SingleValuedPathExpression();
        }

        assert($this->lexer->lookahead !== null);
        // Still need to decide between IdentificationVariable or ResultVariable
        $lookaheadValue = $this->lexer->lookahead->value;

        if (! isset($this->queryComponents[$lookaheadValue])) {
            $this->semanticalError('Cannot group by undefined identification or result variable.');
        }

        return isset($this->queryComponents[$lookaheadValue]['metadata'])
            ? $this->IdentificationVariable()
            : $this->ResultVariable();
    }

    /**
     * OrderByItem ::= (
     *      SimpleArithmeticExpression | SingleValuedPathExpression | CaseExpression |
     *      ScalarExpression | ResultVariable | FunctionDeclaration
     * ) ["ASC" | "DESC"]
     */
    public function OrderByItem(): AST\OrderByItem
    {
        $this->lexer->peek(); // lookahead => '.'
        $this->lexer->peek(); // lookahead => token after '.'

        $peek = $this->lexer->peek(); // lookahead => token after the token after the '.'

        $this->lexer->resetPeek();

        $glimpse = $this->lexer->glimpse();

        assert($this->lexer->lookahead !== null);
        $expr = match (true) {
            $this->isMathOperator($peek) || $this->isMathOperator($glimpse) => $this->SimpleArithmeticExpression(),
            $glimpse !== null && $glimpse->type === TokenType::T_DOT => $this->SingleValuedPathExpression(),
            $this->lexer->peek() && $this->isMathOperator($this->peekBeyondClosingParenthesis()) => $this->ScalarExpression(),
            $this->lexer->lookahead->type === TokenType::T_CASE => $this->CaseExpression(),
            $this->isFunction() => $this->FunctionDeclaration(),
            default => $this->ResultVariable(),
        };

        $type = 'ASC';
        $item = new AST\OrderByItem($expr);

        switch (true) {
            case $this->lexer->isNextToken(TokenType::T_DESC):
                $this->match(TokenType::T_DESC);
                $type = 'DESC';
                break;

            case $this->lexer->isNextToken(TokenType::T_ASC):
                $this->match(TokenType::T_ASC);
                break;

            default:
                // Do nothing
        }

        $item->type = $type;

        return $item;
    }

    /**
     * NewValue ::= SimpleArithmeticExpression | StringPrimary | DatetimePrimary | BooleanPrimary |
     *      EnumPrimary | SimpleEntityExpression | "NULL"
     *
     * NOTE: Since it is not possible to correctly recognize individual types, here is the full
     * grammar that needs to be supported:
     *
     * NewValue ::= SimpleArithmeticExpression | "NULL"
     *
     * SimpleArithmeticExpression covers all *Primary grammar rules and also SimpleEntityExpression
     */
    public function NewValue(): AST\ArithmeticExpression|AST\InputParameter|null
    {
        if ($this->lexer->isNextToken(TokenType::T_NULL)) {
            $this->match(TokenType::T_NULL);

            return null;
        }

        if ($this->lexer->isNextToken(TokenType::T_INPUT_PARAMETER)) {
            $this->match(TokenType::T_INPUT_PARAMETER);
            assert($this->lexer->token !== null);

            return new AST\InputParameter($this->lexer->token->value);
        }

        return $this->ArithmeticExpression();
    }

    /**
     * IdentificationVariableDeclaration ::= RangeVariableDeclaration [IndexBy] {Join}*
     */
    public function IdentificationVariableDeclaration(): AST\IdentificationVariableDeclaration
    {
        $joins                    = [];
        $rangeVariableDeclaration = $this->RangeVariableDeclaration();
        $indexBy                  = $this->lexer->isNextToken(TokenType::T_INDEX)
            ? $this->IndexBy()
            : null;

        $rangeVariableDeclaration->isRoot = true;

        while (
            $this->lexer->isNextToken(TokenType::T_LEFT) ||
            $this->lexer->isNextToken(TokenType::T_INNER) ||
            $this->lexer->isNextToken(TokenType::T_JOIN)
        ) {
            $joins[] = $this->Join();
        }

        return new AST\IdentificationVariableDeclaration(
            $rangeVariableDeclaration,
            $indexBy,
            $joins,
        );
    }

    /**
     * SubselectIdentificationVariableDeclaration ::= IdentificationVariableDeclaration
     *
     * {Internal note: WARNING: Solution is harder than a bare implementation.
     * Desired EBNF support:
     *
     * SubselectIdentificationVariableDeclaration ::= IdentificationVariableDeclaration | (AssociationPathExpression ["AS"] AliasIdentificationVariable)
     *
     * It demands that entire SQL generation to become programmatical. This is
     * needed because association based subselect requires "WHERE" conditional
     * expressions to be injected, but there is no scope to do that. Only scope
     * accessible is "FROM", prohibiting an easy implementation without larger
     * changes.}
     */
    public function SubselectIdentificationVariableDeclaration(): AST\IdentificationVariableDeclaration
    {
        /*
        NOT YET IMPLEMENTED!

        $glimpse = $this->lexer->glimpse();

        if ($glimpse->type == TokenType::T_DOT) {
            $associationPathExpression = $this->AssociationPathExpression();

            if ($this->lexer->isNextToken(TokenType::T_AS)) {
                $this->match(TokenType::T_AS);
            }

            $aliasIdentificationVariable = $this->AliasIdentificationVariable();
            $identificationVariable      = $associationPathExpression->identificationVariable;
            $field                       = $associationPathExpression->associationField;

            $class       = $this->queryComponents[$identificationVariable]['metadata'];
            $targetClass = $this->em->getClassMetadata($class->associationMappings[$field]['targetEntity']);

            // Building queryComponent
            $joinQueryComponent = array(
                'metadata'     => $targetClass,
                'parent'       => $identificationVariable,
                'relation'     => $class->getAssociationMapping($field),
                'map'          => null,
                'nestingLevel' => $this->nestingLevel,
                'token'        => $this->lexer->lookahead
            );

            $this->queryComponents[$aliasIdentificationVariable] = $joinQueryComponent;

            return new AST\SubselectIdentificationVariableDeclaration(
                $associationPathExpression, $aliasIdentificationVariable
            );
        }
        */

        return $this->IdentificationVariableDeclaration();
    }

    /**
     * Join ::= ["LEFT" ["OUTER"] | "INNER"] "JOIN"
     *          (JoinAssociationDeclaration ["WITH" ConditionalExpression] | RangeVariableDeclaration ["ON" ConditionalExpression])
     */
    public function Join(): AST\Join
    {
        // Check Join type
        $joinType = AST\Join::JOIN_TYPE_INNER;

        switch (true) {
            case $this->lexer->isNextToken(TokenType::T_LEFT):
                $this->match(TokenType::T_LEFT);

                $joinType = AST\Join::JOIN_TYPE_LEFT;

                // Possible LEFT OUTER join
                if ($this->lexer->isNextToken(TokenType::T_OUTER)) {
                    $this->match(TokenType::T_OUTER);

                    $joinType = AST\Join::JOIN_TYPE_LEFTOUTER;
                }

                break;

            case $this->lexer->isNextToken(TokenType::T_INNER):
                $this->match(TokenType::T_INNER);
                break;

            default:
                // Do nothing
        }

        $this->match(TokenType::T_JOIN);

        $next = $this->lexer->glimpse();
        assert($next !== null);
        $conditionalExpression = null;

        if ($next->type === TokenType::T_DOT) {
            $joinDeclaration = $this->JoinAssociationDeclaration();

            if ($this->lexer->isNextToken(TokenType::T_WITH)) {
                $this->match(TokenType::T_WITH);

                try {
                    $this->withJoinConditionNestingLevel++;

                    $conditionalExpression = $this->ConditionalExpression();
                } finally {
                    $this->withJoinConditionNestingLevel--;
                }
            }
        } else {
            $joinDeclaration         = $this->RangeVariableDeclaration();
            $joinDeclaration->isRoot = false;

            if ($this->lexer->isNextToken(TokenType::T_ON)) {
                $this->match(TokenType::T_ON);
                $conditionalExpression = $this->ConditionalExpression();
            }
        }

        $join                        = new AST\Join($joinType, $joinDeclaration);
        $join->conditionalExpression = $conditionalExpression;

        return $join;
    }

    /**
     * RangeVariableDeclaration ::= AbstractSchemaName ["AS"] AliasIdentificationVariable
     *
     * @throws QueryException
     */
    public function RangeVariableDeclaration(): AST\RangeVariableDeclaration
    {
        if ($this->lexer->isNextToken(TokenType::T_OPEN_PARENTHESIS) && $this->lexer->glimpse()->type === TokenType::T_SELECT) {
            $this->semanticalError('Subquery is not supported here', $this->lexer->token);
        }

        $abstractSchemaName = $this->AbstractSchemaName();

        $this->validateAbstractSchemaName($abstractSchemaName);

        if ($this->lexer->isNextToken(TokenType::T_AS)) {
            $this->match(TokenType::T_AS);
        }

        assert($this->lexer->lookahead !== null);
        $token                       = $this->lexer->lookahead;
        $aliasIdentificationVariable = $this->AliasIdentificationVariable();
        $classMetadata               = $this->em->getClassMetadata($abstractSchemaName);

        // Building queryComponent
        $queryComponent = [
            'metadata'     => $classMetadata,
            'parent'       => null,
            'relation'     => null,
            'map'          => null,
            'nestingLevel' => $this->nestingLevel,
            'token'        => $token,
        ];

        $this->queryComponents[$aliasIdentificationVariable] = $queryComponent;

        return new AST\RangeVariableDeclaration($abstractSchemaName, $aliasIdentificationVariable);
    }

    /**
     * JoinAssociationDeclaration ::= JoinAssociationPathExpression ["AS"] AliasIdentificationVariable [IndexBy]
     */
    public function JoinAssociationDeclaration(): AST\JoinAssociationDeclaration
    {
        $joinAssociationPathExpression = $this->JoinAssociationPathExpression();

        if ($this->lexer->isNextToken(TokenType::T_AS)) {
            $this->match(TokenType::T_AS);
        }

        assert($this->lexer->lookahead !== null);

        $aliasIdentificationVariable = $this->AliasIdentificationVariable();
        $indexBy                     = $this->lexer->isNextToken(TokenType::T_INDEX) ? $this->IndexBy() : null;

        $identificationVariable = $joinAssociationPathExpression->identificationVariable;
        $field                  = $joinAssociationPathExpression->associationField;

        $class       = $this->getMetadataForDqlAlias($identificationVariable);
        $targetClass = $this->em->getClassMetadata($class->associationMappings[$field]->targetEntity);

        // Building queryComponent
        $joinQueryComponent = [
            'metadata'     => $targetClass,
            'parent'       => $joinAssociationPathExpression->identificationVariable,
            'relation'     => $class->getAssociationMapping($field),
            'map'          => null,
            'nestingLevel' => $this->nestingLevel,
            'token'        => $this->lexer->lookahead,
        ];

        $this->queryComponents[$aliasIdentificationVariable] = $joinQueryComponent;

        return new AST\JoinAssociationDeclaration($joinAssociationPathExpression, $aliasIdentificationVariable, $indexBy);
    }

    /**
     * PartialObjectExpression ::= "PARTIAL" IdentificationVariable "." PartialFieldSet
     * PartialFieldSet ::= "{" SimpleStateField {"," SimpleStateField}* "}"
     */
    public function PartialObjectExpression(): AST\PartialObjectExpression
    {
        $this->match(TokenType::T_PARTIAL);

        $partialFieldSet = [];

        $identificationVariable = $this->IdentificationVariable();

        $this->match(TokenType::T_DOT);
        $this->match(TokenType::T_OPEN_CURLY_BRACE);
        $this->match(TokenType::T_IDENTIFIER);

        assert($this->lexer->token !== null);
        $field = $this->lexer->token->value;

        // First field in partial expression might be embeddable property
        while ($this->lexer->isNextToken(TokenType::T_DOT)) {
            $this->match(TokenType::T_DOT);
            $this->match(TokenType::T_IDENTIFIER);
            $field .= '.' . $this->lexer->token->value;
        }

        $partialFieldSet[] = $field;

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);
            $this->match(TokenType::T_IDENTIFIER);

            $field = $this->lexer->token->value;

            while ($this->lexer->isNextToken(TokenType::T_DOT)) {
                $this->match(TokenType::T_DOT);
                $this->match(TokenType::T_IDENTIFIER);
                $field .= '.' . $this->lexer->token->value;
            }

            $partialFieldSet[] = $field;
        }

        $this->match(TokenType::T_CLOSE_CURLY_BRACE);

        $partialObjectExpression = new AST\PartialObjectExpression($identificationVariable, $partialFieldSet);

        // Defer PartialObjectExpression validation
        $this->deferredPartialObjectExpressions[] = [
            'expression'   => $partialObjectExpression,
            'nestingLevel' => $this->nestingLevel,
            'token'        => $this->lexer->token,
        ];

        return $partialObjectExpression;
    }

    /**
     * NewObjectExpression ::= "NEW" AbstractSchemaName "(" NewObjectArg {"," NewObjectArg}* ")"
     */
    public function NewObjectExpression(): AST\NewObjectExpression
    {
        $useNamedArguments =  false;
        $args              = [];
        $argFieldAlias     = [];
        $this->match(TokenType::T_NEW);

        if ($this->lexer->isNextToken(TokenType::T_NAMED)) {
            $this->match(TokenType::T_NAMED);
            $useNamedArguments = true;
        }

        /** @var class-string $className */
        $className = $this->AbstractSchemaName(); // note that this is not yet validated
        $token     = $this->lexer->token;

        $this->match(TokenType::T_OPEN_PARENTHESIS);

        $this->addArgument($args, $useNamedArguments);

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);
            $this->addArgument($args, $useNamedArguments);
        }

        $this->match(TokenType::T_CLOSE_PARENTHESIS);

        $expression = new AST\NewObjectExpression($className, $args);

        // Defer NewObjectExpression validation
        $this->deferredNewObjectExpressions[] = [
            'token'        => $token,
            'expression'   => $expression,
            'nestingLevel' => $this->nestingLevel,
        ];

        return $expression;
    }

    /** @param array<mixed> $args */
    public function addArgument(array &$args, bool $useNamedArguments): void
    {
        $fieldAlias = null;

        if ($useNamedArguments) {
            $startToken = $this->lexer->lookahead?->position ?? 0;

            $newArg = $this->NewObjectArg($fieldAlias);

            $key = $fieldAlias ?? $newArg->field ?? null;

            if ($key === null) {
                throw NoMatchingPropertyException::create(trim(substr(
                    ($this->query->getDQL() ?? ''),
                    $startToken,
                    ($this->lexer->lookahead->position ?? 0) - $startToken,
                )));
            }

            if (array_key_exists($key, $args)) {
                throw DuplicateFieldException::create($key, trim(substr(
                    ($this->query->getDQL() ?? ''),
                    $startToken,
                    ($this->lexer->lookahead->position ?? 0) - $startToken,
                )));
            }

            $args[$key] = $newArg;
        } else {
            $args[] = $this->NewObjectArg($fieldAlias);
        }
    }

    /**
     * NewObjectArg ::= (ScalarExpression | "(" Subselect ")" | NewObjectExpression) ["AS" AliasResultVariable]
     */
    public function NewObjectArg(string|null &$fieldAlias = null): mixed
    {
        $fieldAlias = null;

        assert($this->lexer->lookahead !== null);
        $token = $this->lexer->lookahead;
        $peek  = $this->lexer->glimpse();

        assert($peek !== null);

        $expression = null;

        if ($token->type === TokenType::T_OPEN_PARENTHESIS && $peek->type === TokenType::T_SELECT) {
            $this->match(TokenType::T_OPEN_PARENTHESIS);
            $expression = $this->Subselect();
            $this->match(TokenType::T_CLOSE_PARENTHESIS);
        } elseif ($token->type === TokenType::T_NEW) {
            $expression = $this->NewObjectExpression();
        } elseif ($token->type === TokenType::T_IDENTIFIER && $peek->type !== TokenType::T_DOT && $peek->type !== TokenType::T_OPEN_PARENTHESIS) {
            $expression = $this->EntityAsDtoArgumentExpression();
            $fieldAlias = $expression->aliasVariable;
        } else {
            $expression = $this->ScalarExpression();
        }

        if ($this->lexer->isNextToken(TokenType::T_AS)) {
            $this->match(TokenType::T_AS);
            $this->match(TokenType::T_IDENTIFIER);

            assert($this->lexer->token !== null);

            $fieldAlias = $this->lexer->token->value;
        }

        return $expression;
    }

    /**
     * IndexBy ::= "INDEX" "BY" SingleValuedPathExpression
     */
    public function IndexBy(): AST\IndexBy
    {
        $this->match(TokenType::T_INDEX);
        $this->match(TokenType::T_BY);
        $pathExpr = $this->SingleValuedPathExpression();

        // Add the INDEX BY info to the query component
        $this->queryComponents[$pathExpr->identificationVariable]['map'] = $pathExpr->field;

        return new AST\IndexBy($pathExpr);
    }

    /**
     * ScalarExpression ::= SimpleArithmeticExpression | StringPrimary | DatetimePrimary |
     *                      StateFieldPathExpression | BooleanPrimary | CaseExpression |
     *                      InstanceOfExpression | "NULL"
     *
     * @return mixed One of the possible expressions or subexpressions.
     */
    public function ScalarExpression(): mixed
    {
        assert($this->lexer->token !== null);
        assert($this->lexer->lookahead !== null);
        $lookahead = $this->lexer->lookahead->type;
        $peek      = $this->lexer->glimpse();

        switch (true) {
            case $lookahead === TokenType::T_INTEGER:
            case $lookahead === TokenType::T_FLOAT:
            // SimpleArithmeticExpression : (- u.value ) or ( + u.value )  or ( - 1 ) or ( + 1 )
            case $lookahead === TokenType::T_MINUS:
            case $lookahead === TokenType::T_PLUS:
                return $this->SimpleArithmeticExpression();

            case $lookahead === TokenType::T_STRING:
                return $this->StringPrimary();

            case $lookahead === TokenType::T_TRUE:
            case $lookahead === TokenType::T_FALSE:
                $this->match($lookahead);

                return new AST\Literal(AST\Literal::BOOLEAN, $this->lexer->token->value);

            case $lookahead === TokenType::T_NULL:
                $this->match(TokenType::T_NULL);

                return new AST\Literal(AST\Literal::NULL, null);

            case $lookahead === TokenType::T_INPUT_PARAMETER:
                return match (true) {
                    $this->isMathOperator($peek) => $this->SimpleArithmeticExpression(),
                    default => $this->InputParameter(),
                };

            case $lookahead === TokenType::T_CASE:
            case $lookahead === TokenType::T_COALESCE:
            case $lookahead === TokenType::T_NULLIF:
                // Since NULLIF and COALESCE can be identified as a function,
                // we need to check these before checking for FunctionDeclaration
                return $this->CaseExpression();

            case $lookahead === TokenType::T_OPEN_PARENTHESIS:
                return $this->SimpleArithmeticExpression();

            // this check must be done before checking for a filed path expression
            case $this->isFunction():
                $this->lexer->peek();

                return match (true) {
                    $this->isMathOperator($this->peekBeyondClosingParenthesis()) => $this->SimpleArithmeticExpression(),
                    default => $this->FunctionDeclaration(),
                };

            // it is no function, so it must be a field path
            case $lookahead === TokenType::T_IDENTIFIER:
                $this->lexer->peek(); // lookahead => '.'
                $this->lexer->peek(); // lookahead => token after '.'
                $peek = $this->lexer->peek(); // lookahead => token after the token after the '.'
                $this->lexer->resetPeek();

                if ($this->isMathOperator($peek)) {
                    return $this->SimpleArithmeticExpression();
                }

                return $this->StateFieldPathExpression();

            default:
                $this->syntaxError();
        }
    }

    /**
     * CaseExpression ::= GeneralCaseExpression | SimpleCaseExpression | CoalesceExpression | NullIfExpression
     * GeneralCaseExpression ::= "CASE" WhenClause {WhenClause}* "ELSE" ScalarExpression "END"
     * WhenClause ::= "WHEN" ConditionalExpression "THEN" ScalarExpression
     * SimpleCaseExpression ::= "CASE" CaseOperand SimpleWhenClause {SimpleWhenClause}* "ELSE" ScalarExpression "END"
     * CaseOperand ::= StateFieldPathExpression
     * SimpleWhenClause ::= "WHEN" ScalarExpression "THEN" ScalarExpression
     * CoalesceExpression ::= "COALESCE" "(" ScalarExpression {"," ScalarExpression}* ")"
     * NullIfExpression ::= "NULLIF" "(" ScalarExpression "," ScalarExpression ")"
     *
     * @return mixed One of the possible expressions or subexpressions.
     */
    public function CaseExpression(): mixed
    {
        assert($this->lexer->lookahead !== null);
        $lookahead = $this->lexer->lookahead->type;

        switch ($lookahead) {
            case TokenType::T_NULLIF:
                return $this->NullIfExpression();

            case TokenType::T_COALESCE:
                return $this->CoalesceExpression();

            case TokenType::T_CASE:
                $this->lexer->resetPeek();
                $peek = $this->lexer->peek();

                assert($peek !== null);
                if ($peek->type === TokenType::T_WHEN) {
                    return $this->GeneralCaseExpression();
                }

                return $this->SimpleCaseExpression();

            default:
                // Do nothing
                break;
        }

        $this->syntaxError();
    }

    /**
     * CoalesceExpression ::= "COALESCE" "(" ScalarExpression {"," ScalarExpression}* ")"
     */
    public function CoalesceExpression(): AST\CoalesceExpression
    {
        $this->match(TokenType::T_COALESCE);
        $this->match(TokenType::T_OPEN_PARENTHESIS);

        // Process ScalarExpressions (1..N)
        $scalarExpressions   = [];
        $scalarExpressions[] = $this->ScalarExpression();

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);

            $scalarExpressions[] = $this->ScalarExpression();
        }

        $this->match(TokenType::T_CLOSE_PARENTHESIS);

        return new AST\CoalesceExpression($scalarExpressions);
    }

    /**
     * NullIfExpression ::= "NULLIF" "(" ScalarExpression "," ScalarExpression ")"
     */
    public function NullIfExpression(): AST\NullIfExpression
    {
        $this->match(TokenType::T_NULLIF);
        $this->match(TokenType::T_OPEN_PARENTHESIS);

        $firstExpression = $this->ScalarExpression();
        $this->match(TokenType::T_COMMA);
        $secondExpression = $this->ScalarExpression();

        $this->match(TokenType::T_CLOSE_PARENTHESIS);

        return new AST\NullIfExpression($firstExpression, $secondExpression);
    }

    /**
     * GeneralCaseExpression ::= "CASE" WhenClause {WhenClause}* "ELSE" ScalarExpression "END"
     */
    public function GeneralCaseExpression(): AST\GeneralCaseExpression
    {
        $this->match(TokenType::T_CASE);

        // Process WhenClause (1..N)
        $whenClauses = [];

        do {
            $whenClauses[] = $this->WhenClause();
        } while ($this->lexer->isNextToken(TokenType::T_WHEN));

        $this->match(TokenType::T_ELSE);
        $scalarExpression = $this->ScalarExpression();
        $this->match(TokenType::T_END);

        return new AST\GeneralCaseExpression($whenClauses, $scalarExpression);
    }

    /**
     * SimpleCaseExpression ::= "CASE" CaseOperand SimpleWhenClause {SimpleWhenClause}* "ELSE" ScalarExpression "END"
     * CaseOperand ::= StateFieldPathExpression
     */
    public function SimpleCaseExpression(): AST\SimpleCaseExpression
    {
        $this->match(TokenType::T_CASE);
        $caseOperand = $this->StateFieldPathExpression();

        // Process SimpleWhenClause (1..N)
        $simpleWhenClauses = [];

        do {
            $simpleWhenClauses[] = $this->SimpleWhenClause();
        } while ($this->lexer->isNextToken(TokenType::T_WHEN));

        $this->match(TokenType::T_ELSE);
        $scalarExpression = $this->ScalarExpression();
        $this->match(TokenType::T_END);

        return new AST\SimpleCaseExpression($caseOperand, $simpleWhenClauses, $scalarExpression);
    }

    /**
     * WhenClause ::= "WHEN" ConditionalExpression "THEN" ScalarExpression
     */
    public function WhenClause(): AST\WhenClause
    {
        $this->match(TokenType::T_WHEN);
        $conditionalExpression = $this->ConditionalExpression();
        $this->match(TokenType::T_THEN);

        return new AST\WhenClause($conditionalExpression, $this->ScalarExpression());
    }

    /**
     * SimpleWhenClause ::= "WHEN" ScalarExpression "THEN" ScalarExpression
     */
    public function SimpleWhenClause(): AST\SimpleWhenClause
    {
        $this->match(TokenType::T_WHEN);
        $conditionalExpression = $this->ScalarExpression();
        $this->match(TokenType::T_THEN);

        return new AST\SimpleWhenClause($conditionalExpression, $this->ScalarExpression());
    }

    /**
     * SelectExpression ::= (
     *     IdentificationVariable | ScalarExpression | AggregateExpression | FunctionDeclaration |
     *     PartialObjectExpression | "(" Subselect ")" | CaseExpression | NewObjectExpression
     * ) [["AS"] ["HIDDEN"] AliasResultVariable]
     */
    public function SelectExpression(): AST\SelectExpression
    {
        assert($this->lexer->lookahead !== null);
        $expression    = null;
        $identVariable = null;
        $peek          = $this->lexer->glimpse();
        $lookaheadType = $this->lexer->lookahead->type;
        assert($peek !== null);

        switch (true) {
            // ScalarExpression (u.name)
            case $lookaheadType === TokenType::T_IDENTIFIER && $peek->type === TokenType::T_DOT:
                $expression = $this->ScalarExpression();
                break;

            // IdentificationVariable (u)
            case $lookaheadType === TokenType::T_IDENTIFIER && $peek->type !== TokenType::T_OPEN_PARENTHESIS:
                $expression = $identVariable = $this->IdentificationVariable();
                break;

            // ScalarExpression (NULL)
            case $lookaheadType === TokenType::T_NULL:
                $expression = $this->ScalarExpression();
                break;

            // CaseExpression (CASE ... or NULLIF(...) or COALESCE(...))
            case $lookaheadType === TokenType::T_CASE:
            case $lookaheadType === TokenType::T_COALESCE:
            case $lookaheadType === TokenType::T_NULLIF:
                $expression = $this->CaseExpression();
                break;

            // DQL Function (SUM(u.value) or SUM(u.value) + 1)
            case $this->isFunction():
                $this->lexer->peek(); // "("

                $expression = match (true) {
                    $this->isMathOperator($this->peekBeyondClosingParenthesis()) => $this->ScalarExpression(),
                    default => $this->FunctionDeclaration(),
                };

                break;

            // PartialObjectExpression (PARTIAL u.{id, name})
            case $lookaheadType === TokenType::T_PARTIAL:
                $expression    = $this->PartialObjectExpression();
                $identVariable = $expression->identificationVariable;
                break;

            // Subselect
            case $lookaheadType === TokenType::T_OPEN_PARENTHESIS && $peek->type === TokenType::T_SELECT:
                $this->match(TokenType::T_OPEN_PARENTHESIS);
                $expression = $this->Subselect();
                $this->match(TokenType::T_CLOSE_PARENTHESIS);
                break;

            // Shortcut: ScalarExpression => SimpleArithmeticExpression
            case $lookaheadType === TokenType::T_OPEN_PARENTHESIS:
            case $lookaheadType === TokenType::T_INTEGER:
            case $lookaheadType === TokenType::T_STRING:
            case $lookaheadType === TokenType::T_FLOAT:
            // SimpleArithmeticExpression : (- u.value ) or ( + u.value )
            case $lookaheadType === TokenType::T_MINUS:
            case $lookaheadType === TokenType::T_PLUS:
                $expression = $this->SimpleArithmeticExpression();
                break;

            // NewObjectExpression (New ClassName(id, name))
            case $lookaheadType === TokenType::T_NEW:
                $expression = $this->NewObjectExpression();
                break;

            default:
                $this->syntaxError(
                    'IdentificationVariable | ScalarExpression | AggregateExpression | FunctionDeclaration | PartialObjectExpression | "(" Subselect ")" | CaseExpression',
                    $this->lexer->lookahead,
                );
        }

        // [["AS"] ["HIDDEN"] AliasResultVariable]
        $mustHaveAliasResultVariable = false;

        if ($this->lexer->isNextToken(TokenType::T_AS)) {
            $this->match(TokenType::T_AS);

            $mustHaveAliasResultVariable = true;
        }

        $hiddenAliasResultVariable = false;

        if ($this->lexer->isNextToken(TokenType::T_HIDDEN)) {
            $this->match(TokenType::T_HIDDEN);

            $hiddenAliasResultVariable = true;
        }

        $aliasResultVariable = null;

        if ($mustHaveAliasResultVariable || $this->lexer->isNextToken(TokenType::T_IDENTIFIER)) {
            assert($expression instanceof AST\Node || is_string($expression));
            $token               = $this->lexer->lookahead;
            $aliasResultVariable = $this->AliasResultVariable();

            // Include AliasResultVariable in query components.
            $this->queryComponents[$aliasResultVariable] = [
                'resultVariable' => $expression,
                'nestingLevel'   => $this->nestingLevel,
                'token'          => $token,
            ];
        }

        // AST

        $expr = new AST\SelectExpression($expression, $aliasResultVariable, $hiddenAliasResultVariable);

        if ($identVariable) {
            $this->identVariableExpressions[$identVariable] = $expr;
        }

        return $expr;
    }

    /**
     * SimpleSelectExpression ::= (
     *      StateFieldPathExpression | IdentificationVariable | FunctionDeclaration |
     *      AggregateExpression | "(" Subselect ")" | ScalarExpression
     * ) [["AS"] AliasResultVariable]
     */
    public function SimpleSelectExpression(): AST\SimpleSelectExpression
    {
        assert($this->lexer->lookahead !== null);
        $peek = $this->lexer->glimpse();
        assert($peek !== null);

        switch ($this->lexer->lookahead->type) {
            case TokenType::T_IDENTIFIER:
                switch (true) {
                    case $peek->type === TokenType::T_DOT:
                        $expression = $this->StateFieldPathExpression();

                        return new AST\SimpleSelectExpression($expression);

                    case $peek->type !== TokenType::T_OPEN_PARENTHESIS:
                        $expression = $this->IdentificationVariable();

                        return new AST\SimpleSelectExpression($expression);

                    case $this->isFunction():
                        // SUM(u.id) + COUNT(u.id)
                        if ($this->isMathOperator($this->peekBeyondClosingParenthesis())) {
                            return new AST\SimpleSelectExpression($this->ScalarExpression());
                        }

                        // COUNT(u.id)
                        if ($this->isAggregateFunction($this->lexer->lookahead->type)) {
                            return new AST\SimpleSelectExpression($this->AggregateExpression());
                        }

                        // IDENTITY(u)
                        return new AST\SimpleSelectExpression($this->FunctionDeclaration());

                    default:
                        // Do nothing
                }

                break;

            case TokenType::T_OPEN_PARENTHESIS:
                if ($peek->type !== TokenType::T_SELECT) {
                    // Shortcut: ScalarExpression => SimpleArithmeticExpression
                    $expression = $this->SimpleArithmeticExpression();

                    return new AST\SimpleSelectExpression($expression);
                }

                // Subselect
                $this->match(TokenType::T_OPEN_PARENTHESIS);
                $expression = $this->Subselect();
                $this->match(TokenType::T_CLOSE_PARENTHESIS);

                return new AST\SimpleSelectExpression($expression);

            default:
                // Do nothing
        }

        $this->lexer->peek();

        $expression = $this->ScalarExpression();
        $expr       = new AST\SimpleSelectExpression($expression);

        if ($this->lexer->isNextToken(TokenType::T_AS)) {
            $this->match(TokenType::T_AS);
        }

        if ($this->lexer->isNextToken(TokenType::T_IDENTIFIER)) {
            $token                             = $this->lexer->lookahead;
            $resultVariable                    = $this->AliasResultVariable();
            $expr->fieldIdentificationVariable = $resultVariable;

            // Include AliasResultVariable in query components.
            $this->queryComponents[$resultVariable] = [
                'resultvariable' => $expr,
                'nestingLevel'   => $this->nestingLevel,
                'token'          => $token,
            ];
        }

        return $expr;
    }

    /**
     * ConditionalExpression ::= ConditionalTerm {"OR" ConditionalTerm}*
     */
    public function ConditionalExpression(): AST\ConditionalExpression|AST\ConditionalFactor|AST\ConditionalPrimary|AST\ConditionalTerm
    {
        $conditionalTerms   = [];
        $conditionalTerms[] = $this->ConditionalTerm();

        while ($this->lexer->isNextToken(TokenType::T_OR)) {
            $this->match(TokenType::T_OR);

            $conditionalTerms[] = $this->ConditionalTerm();
        }

        // Phase 1 AST optimization: Prevent AST\ConditionalExpression
        // if only one AST\ConditionalTerm is defined
        if (count($conditionalTerms) === 1) {
            return $conditionalTerms[0];
        }

        return new AST\ConditionalExpression($conditionalTerms);
    }

    /**
     * ConditionalTerm ::= ConditionalFactor {"AND" ConditionalFactor}*
     */
    public function ConditionalTerm(): AST\ConditionalFactor|AST\ConditionalPrimary|AST\ConditionalTerm
    {
        $conditionalFactors   = [];
        $conditionalFactors[] = $this->ConditionalFactor();

        while ($this->lexer->isNextToken(TokenType::T_AND)) {
            $this->match(TokenType::T_AND);

            $conditionalFactors[] = $this->ConditionalFactor();
        }

        // Phase 1 AST optimization: Prevent AST\ConditionalTerm
        // if only one AST\ConditionalFactor is defined
        if (count($conditionalFactors) === 1) {
            return $conditionalFactors[0];
        }

        return new AST\ConditionalTerm($conditionalFactors);
    }

    /**
     * ConditionalFactor ::= ["NOT"] ConditionalPrimary
     */
    public function ConditionalFactor(): AST\ConditionalFactor|AST\ConditionalPrimary
    {
        $not = false;

        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $this->match(TokenType::T_NOT);

            $not = true;
        }

        $conditionalPrimary = $this->ConditionalPrimary();

        // Phase 1 AST optimization: Prevent AST\ConditionalFactor
        // if only one AST\ConditionalPrimary is defined
        if (! $not) {
            return $conditionalPrimary;
        }

        return new AST\ConditionalFactor($conditionalPrimary, $not);
    }

    /**
     * ConditionalPrimary ::= SimpleConditionalExpression | "(" ConditionalExpression ")"
     */
    public function ConditionalPrimary(): AST\ConditionalPrimary
    {
        $condPrimary = new AST\ConditionalPrimary();

        if (! $this->lexer->isNextToken(TokenType::T_OPEN_PARENTHESIS)) {
            $condPrimary->simpleConditionalExpression = $this->SimpleConditionalExpression();

            return $condPrimary;
        }

        // Peek beyond the matching closing parenthesis ')'
        $peek = $this->peekBeyondClosingParenthesis();

        if (
            $peek !== null && (
            in_array($peek->value, ['=', '<', '<=', '<>', '>', '>=', '!='], true) ||
            in_array($peek->type, [TokenType::T_NOT, TokenType::T_BETWEEN, TokenType::T_LIKE, TokenType::T_IN, TokenType::T_IS, TokenType::T_EXISTS], true) ||
            $this->isMathOperator($peek)
            )
        ) {
            $condPrimary->simpleConditionalExpression = $this->SimpleConditionalExpression();

            return $condPrimary;
        }

        $this->match(TokenType::T_OPEN_PARENTHESIS);
        $condPrimary->conditionalExpression = $this->ConditionalExpression();
        $this->match(TokenType::T_CLOSE_PARENTHESIS);

        return $condPrimary;
    }

    /**
     * SimpleConditionalExpression ::=
     *      ComparisonExpression | BetweenExpression | LikeExpression |
     *      InExpression | NullComparisonExpression | ExistsExpression |
     *      EmptyCollectionComparisonExpression | CollectionMemberExpression |
     *      InstanceOfExpression
     */
    public function SimpleConditionalExpression(): AST\ExistsExpression|AST\BetweenExpression|AST\LikeExpression|AST\InListExpression|AST\InSubselectExpression|AST\InstanceOfExpression|AST\CollectionMemberExpression|AST\NullComparisonExpression|AST\EmptyCollectionComparisonExpression|AST\ComparisonExpression
    {
        assert($this->lexer->lookahead !== null);
        if ($this->lexer->isNextToken(TokenType::T_EXISTS)) {
            return $this->ExistsExpression();
        }

        $token     = $this->lexer->lookahead;
        $peek      = $this->lexer->glimpse();
        $lookahead = $token;

        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $token = $this->lexer->glimpse();
        }

        assert($token !== null);
        assert($peek !== null);
        if ($token->type === TokenType::T_IDENTIFIER || $token->type === TokenType::T_INPUT_PARAMETER || $this->isFunction()) {
            // Peek beyond the matching closing parenthesis.
            $beyond = $this->lexer->peek();

            switch ($peek->value) {
                case '(':
                    // Peeks beyond the matched closing parenthesis.
                    $token = $this->peekBeyondClosingParenthesis(false);
                    assert($token !== null);

                    if ($token->type === TokenType::T_NOT) {
                        $token = $this->lexer->peek();
                        assert($token !== null);
                    }

                    if ($token->type === TokenType::T_IS) {
                        $lookahead = $this->lexer->peek();
                    }

                    break;

                default:
                    // Peek beyond the PathExpression or InputParameter.
                    $token = $beyond;

                    while ($token->value === '.') {
                        $this->lexer->peek();

                        $token = $this->lexer->peek();
                        assert($token !== null);
                    }

                    // Also peek beyond a NOT if there is one.
                    assert($token !== null);
                    if ($token->type === TokenType::T_NOT) {
                        $token = $this->lexer->peek();
                        assert($token !== null);
                    }

                    // We need to go even further in case of IS (differentiate between NULL and EMPTY)
                    $lookahead = $this->lexer->peek();
            }

            assert($lookahead !== null);
            // Also peek beyond a NOT if there is one.
            if ($lookahead->type === TokenType::T_NOT) {
                $lookahead = $this->lexer->peek();
            }

            $this->lexer->resetPeek();
        }

        if ($token->type === TokenType::T_BETWEEN) {
            return $this->BetweenExpression();
        }

        if ($token->type === TokenType::T_LIKE) {
            return $this->LikeExpression();
        }

        if ($token->type === TokenType::T_IN) {
            return $this->InExpression();
        }

        if ($token->type === TokenType::T_INSTANCE) {
            return $this->InstanceOfExpression();
        }

        if ($token->type === TokenType::T_MEMBER) {
            return $this->CollectionMemberExpression();
        }

        assert($lookahead !== null);
        if ($token->type === TokenType::T_IS && $lookahead->type === TokenType::T_NULL) {
            return $this->NullComparisonExpression();
        }

        if ($token->type === TokenType::T_IS && $lookahead->type === TokenType::T_EMPTY) {
            return $this->EmptyCollectionComparisonExpression();
        }

        return $this->ComparisonExpression();
    }

    /**
     * EmptyCollectionComparisonExpression ::= CollectionValuedPathExpression "IS" ["NOT"] "EMPTY"
     */
    public function EmptyCollectionComparisonExpression(): AST\EmptyCollectionComparisonExpression
    {
        $pathExpression = $this->CollectionValuedPathExpression();
        $this->match(TokenType::T_IS);

        $not = false;
        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $this->match(TokenType::T_NOT);
            $not = true;
        }

        $this->match(TokenType::T_EMPTY);

        return new AST\EmptyCollectionComparisonExpression(
            $pathExpression,
            $not,
        );
    }

    /**
     * CollectionMemberExpression ::= EntityExpression ["NOT"] "MEMBER" ["OF"] CollectionValuedPathExpression
     *
     * EntityExpression ::= SingleValuedAssociationPathExpression | SimpleEntityExpression
     * SimpleEntityExpression ::= IdentificationVariable | InputParameter
     */
    public function CollectionMemberExpression(): AST\CollectionMemberExpression
    {
        $not        = false;
        $entityExpr = $this->EntityExpression();

        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $this->match(TokenType::T_NOT);

            $not = true;
        }

        $this->match(TokenType::T_MEMBER);

        if ($this->lexer->isNextToken(TokenType::T_OF)) {
            $this->match(TokenType::T_OF);
        }

        return new AST\CollectionMemberExpression(
            $entityExpr,
            $this->CollectionValuedPathExpression(),
            $not,
        );
    }

    /**
     * Literal ::= string | char | integer | float | boolean
     */
    public function Literal(): AST\Literal
    {
        assert($this->lexer->lookahead !== null);
        assert($this->lexer->token !== null);
        switch ($this->lexer->lookahead->type) {
            case TokenType::T_STRING:
                $this->match(TokenType::T_STRING);

                return new AST\Literal(AST\Literal::STRING, $this->lexer->token->value);

            case TokenType::T_INTEGER:
            case TokenType::T_FLOAT:
                $this->match(
                    $this->lexer->isNextToken(TokenType::T_INTEGER) ? TokenType::T_INTEGER : TokenType::T_FLOAT,
                );

                return new AST\Literal(AST\Literal::NUMERIC, $this->lexer->token->value);

            case TokenType::T_TRUE:
            case TokenType::T_FALSE:
                $this->match(
                    $this->lexer->isNextToken(TokenType::T_TRUE) ? TokenType::T_TRUE : TokenType::T_FALSE,
                );

                return new AST\Literal(AST\Literal::BOOLEAN, $this->lexer->token->value);

            default:
                $this->syntaxError('Literal');
        }
    }

    /**
     * InParameter ::= ArithmeticExpression | InputParameter
     */
    public function InParameter(): AST\InputParameter|AST\ArithmeticExpression
    {
        assert($this->lexer->lookahead !== null);
        if ($this->lexer->lookahead->type === TokenType::T_INPUT_PARAMETER) {
            return $this->InputParameter();
        }

        return $this->ArithmeticExpression();
    }

    /**
     * InputParameter ::= PositionalParameter | NamedParameter
     */
    public function InputParameter(): AST\InputParameter
    {
        $this->match(TokenType::T_INPUT_PARAMETER);
        assert($this->lexer->token !== null);

        return new AST\InputParameter($this->lexer->token->value);
    }

    /**
     * ArithmeticExpression ::= SimpleArithmeticExpression | "(" Subselect ")"
     */
    public function ArithmeticExpression(): AST\ArithmeticExpression
    {
        $expr = new AST\ArithmeticExpression();

        if ($this->lexer->isNextToken(TokenType::T_OPEN_PARENTHESIS)) {
            $peek = $this->lexer->glimpse();
            assert($peek !== null);

            if ($peek->type === TokenType::T_SELECT) {
                $this->match(TokenType::T_OPEN_PARENTHESIS);
                $expr->subselect = $this->Subselect();
                $this->match(TokenType::T_CLOSE_PARENTHESIS);

                return $expr;
            }
        }

        $expr->simpleArithmeticExpression = $this->SimpleArithmeticExpression();

        return $expr;
    }

    /**
     * SimpleArithmeticExpression ::= ArithmeticTerm {("+" | "-") ArithmeticTerm}*
     */
    public function SimpleArithmeticExpression(): AST\Node|string
    {
        $terms   = [];
        $terms[] = $this->ArithmeticTerm();

        while (($isPlus = $this->lexer->isNextToken(TokenType::T_PLUS)) || $this->lexer->isNextToken(TokenType::T_MINUS)) {
            $this->match($isPlus ? TokenType::T_PLUS : TokenType::T_MINUS);

            assert($this->lexer->token !== null);
            $terms[] = $this->lexer->token->value;
            $terms[] = $this->ArithmeticTerm();
        }

        // Phase 1 AST optimization: Prevent AST\SimpleArithmeticExpression
        // if only one AST\ArithmeticTerm is defined
        if (count($terms) === 1) {
            return $terms[0];
        }

        return new AST\SimpleArithmeticExpression($terms);
    }

    /**
     * ArithmeticTerm ::= ArithmeticFactor {("*" | "/") ArithmeticFactor}*
     */
    public function ArithmeticTerm(): AST\Node|string
    {
        $factors   = [];
        $factors[] = $this->ArithmeticFactor();

        while (($isMult = $this->lexer->isNextToken(TokenType::T_MULTIPLY)) || $this->lexer->isNextToken(TokenType::T_DIVIDE)) {
            $this->match($isMult ? TokenType::T_MULTIPLY : TokenType::T_DIVIDE);

            assert($this->lexer->token !== null);
            $factors[] = $this->lexer->token->value;
            $factors[] = $this->ArithmeticFactor();
        }

        // Phase 1 AST optimization: Prevent AST\ArithmeticTerm
        // if only one AST\ArithmeticFactor is defined
        if (count($factors) === 1) {
            return $factors[0];
        }

        return new AST\ArithmeticTerm($factors);
    }

    /**
     * ArithmeticFactor ::= [("+" | "-")] ArithmeticPrimary
     */
    public function ArithmeticFactor(): AST\Node|string|AST\ArithmeticFactor
    {
        $sign = null;

        $isPlus = $this->lexer->isNextToken(TokenType::T_PLUS);
        if ($isPlus || $this->lexer->isNextToken(TokenType::T_MINUS)) {
            $this->match($isPlus ? TokenType::T_PLUS : TokenType::T_MINUS);
            $sign = $isPlus;
        }

        $primary = $this->ArithmeticPrimary();

        // Phase 1 AST optimization: Prevent AST\ArithmeticFactor
        // if only one AST\ArithmeticPrimary is defined
        if ($sign === null) {
            return $primary;
        }

        return new AST\ArithmeticFactor($primary, $sign);
    }

    /**
     * ArithmeticPrimary ::= SingleValuedPathExpression | Literal | ParenthesisExpression
     *          | FunctionsReturningNumerics | AggregateExpression | FunctionsReturningStrings
     *          | FunctionsReturningDatetime | IdentificationVariable | ResultVariable
     *          | InputParameter | CaseExpression
     */
    public function ArithmeticPrimary(): AST\Node|string
    {
        if ($this->lexer->isNextToken(TokenType::T_OPEN_PARENTHESIS)) {
            $this->match(TokenType::T_OPEN_PARENTHESIS);

            $expr = $this->SimpleArithmeticExpression();

            $this->match(TokenType::T_CLOSE_PARENTHESIS);

            return new AST\ParenthesisExpression($expr);
        }

        if ($this->lexer->lookahead === null) {
            $this->syntaxError('ArithmeticPrimary');
        }

        switch ($this->lexer->lookahead->type) {
            case TokenType::T_COALESCE:
            case TokenType::T_NULLIF:
            case TokenType::T_CASE:
                return $this->CaseExpression();

            case TokenType::T_IDENTIFIER:
                $peek = $this->lexer->glimpse();

                if ($peek !== null && $peek->value === '(') {
                    return $this->FunctionDeclaration();
                }

                if ($peek !== null && $peek->value === '.') {
                    return $this->SingleValuedPathExpression();
                }

                if (isset($this->queryComponents[$this->lexer->lookahead->value]['resultVariable'])) {
                    return $this->ResultVariable();
                }

                return $this->StateFieldPathExpression();

            case TokenType::T_INPUT_PARAMETER:
                return $this->InputParameter();

            default:
                $peek = $this->lexer->glimpse();

                if ($peek !== null && $peek->value === '(') {
                    return $this->FunctionDeclaration();
                }

                return $this->Literal();
        }
    }

    /**
     * StringExpression ::= StringPrimary | ResultVariable | "(" Subselect ")"
     */
    public function StringExpression(): AST\Subselect|AST\Node|string
    {
        $peek = $this->lexer->glimpse();
        assert($peek !== null);

        // Subselect
        if ($this->lexer->isNextToken(TokenType::T_OPEN_PARENTHESIS) && $peek->type === TokenType::T_SELECT) {
            $this->match(TokenType::T_OPEN_PARENTHESIS);
            $expr = $this->Subselect();
            $this->match(TokenType::T_CLOSE_PARENTHESIS);

            return $expr;
        }

        assert($this->lexer->lookahead !== null);
        // ResultVariable (string)
        if (
            $this->lexer->isNextToken(TokenType::T_IDENTIFIER) &&
            isset($this->queryComponents[$this->lexer->lookahead->value]['resultVariable'])
        ) {
            return $this->ResultVariable();
        }

        return $this->StringPrimary();
    }

    /**
     * StringPrimary ::= StateFieldPathExpression | string | InputParameter | FunctionsReturningStrings | AggregateExpression | CaseExpression
     */
    public function StringPrimary(): AST\Node
    {
        assert($this->lexer->lookahead !== null);
        $lookaheadType = $this->lexer->lookahead->type;

        switch ($lookaheadType) {
            case TokenType::T_IDENTIFIER:
                $peek = $this->lexer->glimpse();
                assert($peek !== null);

                if ($peek->value === '.') {
                    return $this->StateFieldPathExpression();
                }

                if ($peek->value === '(') {
                    // do NOT directly go to FunctionsReturningString() because it doesn't check for custom functions.
                    return $this->FunctionDeclaration();
                }

                $this->syntaxError("'.' or '('");
                break;

            case TokenType::T_STRING:
                $this->match(TokenType::T_STRING);
                assert($this->lexer->token !== null);

                return new AST\Literal(AST\Literal::STRING, $this->lexer->token->value);

            case TokenType::T_INPUT_PARAMETER:
                return $this->InputParameter();

            case TokenType::T_CASE:
            case TokenType::T_COALESCE:
            case TokenType::T_NULLIF:
                return $this->CaseExpression();

            default:
                assert($lookaheadType !== null);
                if ($this->isAggregateFunction($lookaheadType)) {
                    return $this->AggregateExpression();
                }
        }

        $this->syntaxError(
            'StateFieldPathExpression | string | InputParameter | FunctionsReturningStrings | AggregateExpression',
        );
    }

    /**
     * EntityExpression ::= SingleValuedAssociationPathExpression | SimpleEntityExpression
     */
    public function EntityExpression(): AST\InputParameter|AST\PathExpression
    {
        $glimpse = $this->lexer->glimpse();
        assert($glimpse !== null);

        if ($this->lexer->isNextToken(TokenType::T_IDENTIFIER) && $glimpse->value === '.') {
            return $this->SingleValuedAssociationPathExpression();
        }

        return $this->SimpleEntityExpression();
    }

    /**
     * SimpleEntityExpression ::= IdentificationVariable | InputParameter
     */
    public function SimpleEntityExpression(): AST\InputParameter|AST\PathExpression
    {
        if ($this->lexer->isNextToken(TokenType::T_INPUT_PARAMETER)) {
            return $this->InputParameter();
        }

        return $this->StateFieldPathExpression();
    }

    /**
     * AggregateExpression ::=
     *  ("AVG" | "MAX" | "MIN" | "SUM" | "COUNT") "(" ["DISTINCT"] SimpleArithmeticExpression ")"
     */
    public function AggregateExpression(): AST\AggregateExpression
    {
        assert($this->lexer->lookahead !== null);
        $lookaheadType = $this->lexer->lookahead->type;
        $isDistinct    = false;

        if (! in_array($lookaheadType, [TokenType::T_COUNT, TokenType::T_AVG, TokenType::T_MAX, TokenType::T_MIN, TokenType::T_SUM], true)) {
            $this->syntaxError('One of: MAX, MIN, AVG, SUM, COUNT');
        }

        $this->match($lookaheadType);
        assert($this->lexer->token !== null);
        $functionName = $this->lexer->token->value;
        $this->match(TokenType::T_OPEN_PARENTHESIS);

        if ($this->lexer->isNextToken(TokenType::T_DISTINCT)) {
            $this->match(TokenType::T_DISTINCT);
            $isDistinct = true;
        }

        $pathExp = $this->SimpleArithmeticExpression();

        $this->match(TokenType::T_CLOSE_PARENTHESIS);

        return new AST\AggregateExpression($functionName, $pathExp, $isDistinct);
    }

    /**
     * QuantifiedExpression ::= ("ALL" | "ANY" | "SOME") "(" Subselect ")"
     */
    public function QuantifiedExpression(): AST\QuantifiedExpression
    {
        assert($this->lexer->lookahead !== null);
        $lookaheadType = $this->lexer->lookahead->type;
        $value         = $this->lexer->lookahead->value;

        if (! in_array($lookaheadType, [TokenType::T_ALL, TokenType::T_ANY, TokenType::T_SOME], true)) {
            $this->syntaxError('ALL, ANY or SOME');
        }

        $this->match($lookaheadType);
        $this->match(TokenType::T_OPEN_PARENTHESIS);

        $qExpr       = new AST\QuantifiedExpression($this->Subselect());
        $qExpr->type = $value;

        $this->match(TokenType::T_CLOSE_PARENTHESIS);

        return $qExpr;
    }

    /**
     * BetweenExpression ::= ArithmeticExpression ["NOT"] "BETWEEN" ArithmeticExpression "AND" ArithmeticExpression
     */
    public function BetweenExpression(): AST\BetweenExpression
    {
        $not        = false;
        $arithExpr1 = $this->ArithmeticExpression();

        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $this->match(TokenType::T_NOT);
            $not = true;
        }

        $this->match(TokenType::T_BETWEEN);
        $arithExpr2 = $this->ArithmeticExpression();
        $this->match(TokenType::T_AND);
        $arithExpr3 = $this->ArithmeticExpression();

        return new AST\BetweenExpression($arithExpr1, $arithExpr2, $arithExpr3, $not);
    }

    /**
     * ComparisonExpression ::= ArithmeticExpression ComparisonOperator ( QuantifiedExpression | ArithmeticExpression )
     */
    public function ComparisonExpression(): AST\ComparisonExpression
    {
        $this->lexer->glimpse();

        $leftExpr  = $this->ArithmeticExpression();
        $operator  = $this->ComparisonOperator();
        $rightExpr = $this->isNextAllAnySome()
            ? $this->QuantifiedExpression()
            : $this->ArithmeticExpression();

        return new AST\ComparisonExpression($leftExpr, $operator, $rightExpr);
    }

    /**
     * InExpression ::= SingleValuedPathExpression ["NOT"] "IN" "(" (InParameter {"," InParameter}* | Subselect) ")"
     */
    public function InExpression(): AST\InListExpression|AST\InSubselectExpression
    {
        $expression = $this->ArithmeticExpression();

        $not = false;
        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $this->match(TokenType::T_NOT);
            $not = true;
        }

        $this->match(TokenType::T_IN);
        $this->match(TokenType::T_OPEN_PARENTHESIS);

        if ($this->lexer->isNextToken(TokenType::T_SELECT)) {
            $inExpression = new AST\InSubselectExpression(
                $expression,
                $this->Subselect(),
                $not,
            );
        } else {
            $literals = [$this->InParameter()];

            while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
                $this->match(TokenType::T_COMMA);
                $literals[] = $this->InParameter();
            }

            $inExpression = new AST\InListExpression(
                $expression,
                $literals,
                $not,
            );
        }

        $this->match(TokenType::T_CLOSE_PARENTHESIS);

        return $inExpression;
    }

    /**
     * InstanceOfExpression ::= IdentificationVariable ["NOT"] "INSTANCE" ["OF"] (InstanceOfParameter | "(" InstanceOfParameter {"," InstanceOfParameter}* ")")
     */
    public function InstanceOfExpression(): AST\InstanceOfExpression
    {
        $identificationVariable = $this->IdentificationVariable();

        $not = false;
        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $this->match(TokenType::T_NOT);
            $not = true;
        }

        $this->match(TokenType::T_INSTANCE);
        $this->match(TokenType::T_OF);

        $exprValues = $this->lexer->isNextToken(TokenType::T_OPEN_PARENTHESIS)
            ? $this->InstanceOfParameterList()
            : [$this->InstanceOfParameter()];

        return new AST\InstanceOfExpression(
            $identificationVariable,
            $exprValues,
            $not,
        );
    }

    /** @return non-empty-list<AST\InputParameter|string> */
    public function InstanceOfParameterList(): array
    {
        $this->match(TokenType::T_OPEN_PARENTHESIS);

        $exprValues = [$this->InstanceOfParameter()];

        while ($this->lexer->isNextToken(TokenType::T_COMMA)) {
            $this->match(TokenType::T_COMMA);

            $exprValues[] = $this->InstanceOfParameter();
        }

        $this->match(TokenType::T_CLOSE_PARENTHESIS);

        return $exprValues;
    }

    /**
     * InstanceOfParameter ::= AbstractSchemaName | InputParameter
     */
    public function InstanceOfParameter(): AST\InputParameter|string
    {
        if ($this->lexer->isNextToken(TokenType::T_INPUT_PARAMETER)) {
            $this->match(TokenType::T_INPUT_PARAMETER);
            assert($this->lexer->token !== null);

            return new AST\InputParameter($this->lexer->token->value);
        }

        $abstractSchemaName = $this->AbstractSchemaName();

        $this->validateAbstractSchemaName($abstractSchemaName);

        return $abstractSchemaName;
    }

    /**
     * LikeExpression ::= StringExpression ["NOT"] "LIKE" StringPrimary ["ESCAPE" char]
     */
    public function LikeExpression(): AST\LikeExpression
    {
        $stringExpr = $this->StringExpression();
        $not        = false;

        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $this->match(TokenType::T_NOT);
            $not = true;
        }

        $this->match(TokenType::T_LIKE);

        if ($this->lexer->isNextToken(TokenType::T_INPUT_PARAMETER)) {
            $this->match(TokenType::T_INPUT_PARAMETER);
            assert($this->lexer->token !== null);
            $stringPattern = new AST\InputParameter($this->lexer->token->value);
        } else {
            $stringPattern = $this->StringPrimary();
        }

        $escapeChar = null;

        if ($this->lexer->lookahead !== null && $this->lexer->lookahead->type === TokenType::T_ESCAPE) {
            $this->match(TokenType::T_ESCAPE);
            $this->match(TokenType::T_STRING);
            assert($this->lexer->token !== null);

            $escapeChar = new AST\Literal(AST\Literal::STRING, $this->lexer->token->value);
        }

        return new AST\LikeExpression($stringExpr, $stringPattern, $escapeChar, $not);
    }

    /**
     * NullComparisonExpression ::= (InputParameter | NullIfExpression | CoalesceExpression | AggregateExpression | FunctionDeclaration | IdentificationVariable | SingleValuedPathExpression | ResultVariable) "IS" ["NOT"] "NULL"
     */
    public function NullComparisonExpression(): AST\NullComparisonExpression
    {
        switch (true) {
            case $this->lexer->isNextToken(TokenType::T_INPUT_PARAMETER):
                $this->match(TokenType::T_INPUT_PARAMETER);
                assert($this->lexer->token !== null);

                $expr = new AST\InputParameter($this->lexer->token->value);
                break;

            case $this->lexer->isNextToken(TokenType::T_NULLIF):
                $expr = $this->NullIfExpression();
                break;

            case $this->lexer->isNextToken(TokenType::T_COALESCE):
                $expr = $this->CoalesceExpression();
                break;

            case $this->isFunction():
                $expr = $this->FunctionDeclaration();
                break;

            default:
                // We need to check if we are in a IdentificationVariable or SingleValuedPathExpression
                $glimpse = $this->lexer->glimpse();
                assert($glimpse !== null);

                if ($glimpse->type === TokenType::T_DOT) {
                    $expr = $this->SingleValuedPathExpression();

                    // Leave switch statement
                    break;
                }

                assert($this->lexer->lookahead !== null);
                $lookaheadValue = $this->lexer->lookahead->value;

                // Validate existing component
                if (! isset($this->queryComponents[$lookaheadValue])) {
                    $this->semanticalError('Cannot add having condition on undefined result variable.');
                }

                // Validate SingleValuedPathExpression (ie.: "product")
                if (isset($this->queryComponents[$lookaheadValue]['metadata'])) {
                    $expr = $this->SingleValuedPathExpression();
                    break;
                }

                // Validating ResultVariable
                if (! isset($this->queryComponents[$lookaheadValue]['resultVariable'])) {
                    $this->semanticalError('Cannot add having condition on a non result variable.');
                }

                $expr = $this->ResultVariable();
                break;
        }

        $this->match(TokenType::T_IS);

        $not = false;
        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $this->match(TokenType::T_NOT);

            $not = true;
        }

        $this->match(TokenType::T_NULL);

        return new AST\NullComparisonExpression($expr, $not);
    }

    /**
     * ExistsExpression ::= ["NOT"] "EXISTS" "(" Subselect ")"
     */
    public function ExistsExpression(): AST\ExistsExpression
    {
        $not = false;

        if ($this->lexer->isNextToken(TokenType::T_NOT)) {
            $this->match(TokenType::T_NOT);
            $not = true;
        }

        $this->match(TokenType::T_EXISTS);
        $this->match(TokenType::T_OPEN_PARENTHESIS);

        $subselect = $this->Subselect();

        $this->match(TokenType::T_CLOSE_PARENTHESIS);

        return new AST\ExistsExpression($subselect, $not);
    }

    /**
     * ComparisonOperator ::= "=" | "<" | "<=" | "<>" | ">" | ">=" | "!="
     */
    public function ComparisonOperator(): string
    {
        assert($this->lexer->lookahead !== null);
        switch ($this->lexer->lookahead->value) {
            case '=':
                $this->match(TokenType::T_EQUALS);

                return '=';

            case '<':
                $this->match(TokenType::T_LOWER_THAN);
                $operator = '<';

                if ($this->lexer->isNextToken(TokenType::T_EQUALS)) {
                    $this->match(TokenType::T_EQUALS);
                    $operator .= '=';
                } elseif ($this->lexer->isNextToken(TokenType::T_GREATER_THAN)) {
                    $this->match(TokenType::T_GREATER_THAN);
                    $operator .= '>';
                }

                return $operator;

            case '>':
                $this->match(TokenType::T_GREATER_THAN);
                $operator = '>';

                if ($this->lexer->isNextToken(TokenType::T_EQUALS)) {
                    $this->match(TokenType::T_EQUALS);
                    $operator .= '=';
                }

                return $operator;

            case '!':
                $this->match(TokenType::T_NEGATE);
                $this->match(TokenType::T_EQUALS);

                return '<>';

            default:
                $this->syntaxError('=, <, <=, <>, >, >=, !=');
        }
    }

    /**
     * FunctionDeclaration ::= FunctionsReturningStrings | FunctionsReturningNumerics | FunctionsReturningDatetime
     */
    public function FunctionDeclaration(): Functions\FunctionNode
    {
        assert($this->lexer->lookahead !== null);
        $token    = $this->lexer->lookahead;
        $funcName = strtolower($token->value);

        $customFunctionDeclaration = $this->CustomFunctionDeclaration();

        // Check for custom functions functions first!
        switch (true) {
            case $customFunctionDeclaration !== null:
                return $customFunctionDeclaration;

            case isset(self::$stringFunctions[$funcName]):
                return $this->FunctionsReturningStrings();

            case isset(self::$numericFunctions[$funcName]):
                return $this->FunctionsReturningNumerics();

            case isset(self::$datetimeFunctions[$funcName]):
                return $this->FunctionsReturningDatetime();

            default:
                $this->syntaxError('known function', $token);
        }
    }

    /**
     * Helper function for FunctionDeclaration grammar rule.
     */
    private function CustomFunctionDeclaration(): Functions\FunctionNode|null
    {
        assert($this->lexer->lookahead !== null);
        $token    = $this->lexer->lookahead;
        $funcName = strtolower($token->value);

        // Check for custom functions afterwards
        $config = $this->em->getConfiguration();

        return match (true) {
            $config->getCustomStringFunction($funcName) !== null => $this->CustomFunctionsReturningStrings(),
            $config->getCustomNumericFunction($funcName) !== null => $this->CustomFunctionsReturningNumerics(),
            $config->getCustomDatetimeFunction($funcName) !== null => $this->CustomFunctionsReturningDatetime(),
            default => null,
        };
    }

    /**
     * FunctionsReturningNumerics ::=
     *      "LENGTH" "(" StringPrimary ")" |
     *      "LOCATE" "(" StringPrimary "," StringPrimary ["," SimpleArithmeticExpression]")" |
     *      "ABS" "(" SimpleArithmeticExpression ")" |
     *      "SQRT" "(" SimpleArithmeticExpression ")" |
     *      "MOD" "(" SimpleArithmeticExpression "," SimpleArithmeticExpression ")" |
     *      "SIZE" "(" CollectionValuedPathExpression ")" |
     *      "DATE_DIFF" "(" ArithmeticPrimary "," ArithmeticPrimary ")" |
     *      "BIT_AND" "(" ArithmeticPrimary "," ArithmeticPrimary ")" |
     *      "BIT_OR" "(" ArithmeticPrimary "," ArithmeticPrimary ")"
     */
    public function FunctionsReturningNumerics(): AST\Functions\FunctionNode
    {
        assert($this->lexer->lookahead !== null);
        $funcNameLower = strtolower($this->lexer->lookahead->value);
        $funcClass     = self::$numericFunctions[$funcNameLower];

        $function = new $funcClass($funcNameLower);
        $function->parse($this);

        return $function;
    }

    public function CustomFunctionsReturningNumerics(): AST\Functions\FunctionNode
    {
        assert($this->lexer->lookahead !== null);
        // getCustomNumericFunction is case-insensitive
        $functionName  = strtolower($this->lexer->lookahead->value);
        $functionClass = $this->em->getConfiguration()->getCustomNumericFunction($functionName);

        assert($functionClass !== null);

        if (is_string($functionClass)) {
            $function = new $functionClass($functionName);
            assert($function instanceof AST\Functions\FunctionNode);
        } else {
            $function = $functionClass($functionName);
        }

        $function->parse($this);

        return $function;
    }

    /**
     * FunctionsReturningDatetime ::=
     *     "CURRENT_DATE" |
     *     "CURRENT_TIME" |
     *     "CURRENT_TIMESTAMP" |
     *     "DATE_ADD" "(" ArithmeticPrimary "," ArithmeticPrimary "," StringPrimary ")" |
     *     "DATE_SUB" "(" ArithmeticPrimary "," ArithmeticPrimary "," StringPrimary ")"
     */
    public function FunctionsReturningDatetime(): AST\Functions\FunctionNode
    {
        assert($this->lexer->lookahead !== null);
        $funcNameLower = strtolower($this->lexer->lookahead->value);
        $funcClass     = self::$datetimeFunctions[$funcNameLower];

        $function = new $funcClass($funcNameLower);
        $function->parse($this);

        return $function;
    }

    public function CustomFunctionsReturningDatetime(): AST\Functions\FunctionNode
    {
        assert($this->lexer->lookahead !== null);
        // getCustomDatetimeFunction is case-insensitive
        $functionName  = $this->lexer->lookahead->value;
        $functionClass = $this->em->getConfiguration()->getCustomDatetimeFunction($functionName);

        assert($functionClass !== null);

        $function = is_string($functionClass)
            ? new $functionClass($functionName)
            : $functionClass($functionName);

        $function->parse($this);

        return $function;
    }

    /**
     * FunctionsReturningStrings ::=
     *   "CONCAT" "(" StringPrimary "," StringPrimary {"," StringPrimary}* ")" |
     *   "SUBSTRING" "(" StringPrimary "," SimpleArithmeticExpression "," SimpleArithmeticExpression ")" |
     *   "TRIM" "(" [["LEADING" | "TRAILING" | "BOTH"] [char] "FROM"] StringPrimary ")" |
     *   "LOWER" "(" StringPrimary ")" |
     *   "UPPER" "(" StringPrimary ")" |
     *   "IDENTITY" "(" SingleValuedAssociationPathExpression {"," string} ")"
     */
    public function FunctionsReturningStrings(): AST\Functions\FunctionNode
    {
        assert($this->lexer->lookahead !== null);
        $funcNameLower = strtolower($this->lexer->lookahead->value);
        $funcClass     = self::$stringFunctions[$funcNameLower];

        $function = new $funcClass($funcNameLower);
        $function->parse($this);

        return $function;
    }

    public function CustomFunctionsReturningStrings(): Functions\FunctionNode
    {
        assert($this->lexer->lookahead !== null);
        // getCustomStringFunction is case-insensitive
        $functionName  = $this->lexer->lookahead->value;
        $functionClass = $this->em->getConfiguration()->getCustomStringFunction($functionName);

        assert($functionClass !== null);

        $function = is_string($functionClass)
            ? new $functionClass($functionName)
            : $functionClass($functionName);

        $function->parse($this);

        return $function;
    }

    private function getMetadataForDqlAlias(string $dqlAlias): ClassMetadata
    {
        if (! isset($this->queryComponents[$dqlAlias]['metadata'])) {
            throw new LogicException(sprintf('No metadata for DQL alias: %s', $dqlAlias));
        }

        return $this->queryComponents[$dqlAlias]['metadata'];
    }
}
