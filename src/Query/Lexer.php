<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\Common\Lexer\AbstractLexer;
use Override;

use function constant;
use function ctype_alpha;
use function defined;
use function is_numeric;
use function str_contains;
use function str_replace;
use function stripos;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Scans a DQL query for tokens.
 *
 * @extends AbstractLexer<TokenType, string>
 */
class Lexer extends AbstractLexer
{
    /**
     * Creates a new query scanner object.
     *
     * @param string $input A query string.
     */
    public function __construct(string $input)
    {
        $this->setInput($input);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function getCatchablePatterns(): array
    {
        return [
            '[a-z_][a-z0-9_]*\:[a-z_][a-z0-9_]*(?:\\\[a-z_][a-z0-9_]*)*', // aliased name
            '[a-z_\\\][a-z0-9_]*(?:\\\[a-z_][a-z0-9_]*)*', // identifier or qualified name
            '(?:[0-9]+(?:[\.][0-9]+)*)(?:e[+-]?[0-9]+)?', // numbers
            "'(?:[^']|'')*'", // quoted strings
            '\?[0-9]*|:[a-z_][a-z0-9_]*', // parameters
        ];
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function getNonCatchablePatterns(): array
    {
        return ['\s+', '--.*', '(.)'];
    }

    #[Override]
    protected function getType(string &$value): TokenType
    {
        $type = TokenType::T_NONE;

        switch (true) {
            // Recognize numeric values
            case is_numeric($value):
                if (str_contains($value, '.') || stripos($value, 'e') !== false) {
                    return TokenType::T_FLOAT;
                }

                return TokenType::T_INTEGER;

            // Recognize quoted strings
            case $value[0] === "'":
                $value = str_replace("''", "'", substr($value, 1, strlen($value) - 2));

                return TokenType::T_STRING;

            // Recognize identifiers, aliased or qualified names
            case ctype_alpha($value[0]) || $value[0] === '_' || $value[0] === '\\':
                $name = 'Doctrine\ORM\Query\TokenType::T_' . strtoupper($value);

                if (defined($name)) {
                    $type = constant($name);

                    if ($type->value > 100) {
                        return $type;
                    }
                }

                if (str_contains($value, '\\')) {
                    return TokenType::T_FULLY_QUALIFIED_NAME;
                }

                return TokenType::T_IDENTIFIER;

            // Recognize input parameters
            case $value[0] === '?' || $value[0] === ':':
                return TokenType::T_INPUT_PARAMETER;

            // Recognize symbols
            case $value === '.':
                return TokenType::T_DOT;

            case $value === ',':
                return TokenType::T_COMMA;

            case $value === '(':
                return TokenType::T_OPEN_PARENTHESIS;

            case $value === ')':
                return TokenType::T_CLOSE_PARENTHESIS;

            case $value === '=':
                return TokenType::T_EQUALS;

            case $value === '>':
                return TokenType::T_GREATER_THAN;

            case $value === '<':
                return TokenType::T_LOWER_THAN;

            case $value === '+':
                return TokenType::T_PLUS;

            case $value === '-':
                return TokenType::T_MINUS;

            case $value === '*':
                return TokenType::T_MULTIPLY;

            case $value === '/':
                return TokenType::T_DIVIDE;

            case $value === '!':
                return TokenType::T_NEGATE;

            case $value === '{':
                return TokenType::T_OPEN_CURLY_BRACE;

            case $value === '}':
                return TokenType::T_CLOSE_CURLY_BRACE;

            // Default
            default:
                // Do nothing
        }

        return $type;
    }
}
