<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Name;

use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use InvalidArgumentException;

use function count;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;

/**
 * Parses database object names found in mapping metadata.
 *
 * Adapted from the DBAL name parser. The input is produced by the quote strategy, which quotes
 * identifiers using the platform's syntax, so all three styles must be supported: ANSI SQL
 * double quotes, MySQL backticks and SQL Server square brackets. The closing quote is escaped
 * by doubling it.
 *
 * A name is a sequence of identifiers separated by dots. An identifier is either:
 * - quoted: enclosed in double quotes, backticks or square brackets, and non-empty;
 * - unquoted: any non-empty sequence of characters except whitespace, dots, quote characters and brackets.
 *
 * @internal
 */
final class NameParser
{
    private const IDENTIFIER_PATTERN = <<<'PATTERN'
        /\G
        (?:
            "(?<ansi>[^"]++(?:""[^"]*+)*+)"
          | `(?<backtick>[^`]++(?:``[^`]*+)*+)`
          | \[(?<bracket>[^]]++(?:]][^]]*+)*+)]
          | (?<unquoted>[^\s."`\[\]]++)
        )
        /x
    PATTERN;

    /** @throws InvalidArgumentException */
    public static function parseUnqualifiedName(string $input): UnqualifiedName
    {
        $identifiers = self::parseIdentifiers($input);

        if (count($identifiers) > 1) {
            throw new InvalidArgumentException(sprintf(
                'An unqualified name must consist of 1 identifier, %d given in "%s".',
                count($identifiers),
                $input,
            ));
        }

        return new UnqualifiedName($identifiers[0]);
    }

    /** @throws InvalidArgumentException */
    public static function parseOptionallyQualifiedName(string $input): OptionallyQualifiedName
    {
        $identifiers = self::parseIdentifiers($input);

        return match (count($identifiers)) {
            1 => new OptionallyQualifiedName($identifiers[0], null),
            2 => new OptionallyQualifiedName($identifiers[1], $identifiers[0]),
            default => throw new InvalidArgumentException(sprintf(
                'An optionally qualified name must consist of 1 or 2 identifiers, %d given in "%s".',
                count($identifiers),
                $input,
            )),
        };
    }

    /**
     * @return non-empty-list<Identifier>
     *
     * @throws InvalidArgumentException
     */
    private static function parseIdentifiers(string $input): array
    {
        $offset      = 0;
        $identifiers = [];
        $length      = strlen($input);

        while (true) {
            if ($offset >= $length) {
                throw new InvalidArgumentException(sprintf('Expected an identifier at offset %d in "%s".', $offset, $input));
            }

            if (preg_match(self::IDENTIFIER_PATTERN, $input, $matches, 0, $offset) !== 1) {
                throw new InvalidArgumentException(sprintf('Unable to parse an identifier at offset %d in "%s".', $offset, $input));
            }

            if (($matches['unquoted'] ?? '') !== '') {
                $identifiers[] = Identifier::unquoted($matches['unquoted']);
            } elseif ($matches['ansi'] !== '') {
                $identifiers[] = Identifier::quoted(str_replace('""', '"', $matches['ansi']));
            } elseif (($matches['backtick'] ?? '') !== '') {
                $identifiers[] = Identifier::quoted(str_replace('``', '`', $matches['backtick']));
            } elseif (($matches['bracket'] ?? '') !== '') {
                $identifiers[] = Identifier::quoted(str_replace(']]', ']', $matches['bracket']));
            } else {
                throw new InvalidArgumentException(sprintf('Unable to parse an identifier at offset %d in "%s".', $offset, $input));
            }

            $offset += strlen($matches[0]);

            if ($offset >= $length) {
                return $identifiers;
            }

            if ($input[$offset] !== '.') {
                throw new InvalidArgumentException(sprintf('Expected a dot at offset %d in "%s".', $offset, $input));
            }

            $offset++;
        }
    }
}
