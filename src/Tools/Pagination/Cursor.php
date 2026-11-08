<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Tools\Pagination\Exception\InvalidCursor;
use JsonException;

use function base64_decode;
use function base64_encode;
use function count;
use function is_array;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function rtrim;
use function strtr;

use const JSON_THROW_ON_ERROR;

/**
 * Represents a cursor for cursor-based pagination.
 *
 * A cursor contains the parameters needed to fetch the next or previous page of results.
 */
final class Cursor
{
    /**
     * Key of the single-entry JSON object that carries a parameter JSON cannot hold as such:
     * a string that is not valid UTF-8, e.g. the binary database value of a UUID, Base64 encoded.
     */
    private const BASE64_KEY = 'base64';

    /** @param array<string, scalar> $parameters */
    public function __construct(
        private readonly array $parameters,
        private readonly bool $isNext = true,
    ) {
    }

    /**
     * @internal
     *
     * @return array<string, scalar>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Returns whether the cursor is for navigating to the next page.
     */
    public function isNext(): bool
    {
        return $this->isNext;
    }

    /**
     * Returns whether the cursor is for navigating to the previous page.
     */
    public function isPrevious(): bool
    {
        return ! $this->isNext;
    }

    /**
     * Returns the parameters, as they are bound, along with the "_isNext" flag.
     *
     * A binary parameter, such as a UUID stored as binary, is raw bytes here, which
     * JSON cannot hold as such: see {@see encodeToString()}.
     *
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return [...$this->parameters, '_isNext' => $this->isNext];
    }

    /**
     * Encodes the cursor to a URL-safe Base64 JSON string.
     *
     * A parameter that is not valid UTF-8, such as a UUID stored as binary, cannot be
     * put in JSON as such: it is carried Base64 encoded, as {"base64": "..."}.
     *
     * @throws JsonException If a parameter cannot be encoded as JSON, e.g. a non-finite float.
     */
    public function encodeToString(): string
    {
        $payload = [];

        foreach ($this->toArray() as $key => $value) {
            $payload[$key] = is_string($value) && preg_match('//u', $value) !== 1
                ? [self::BASE64_KEY => base64_encode($value)]
                : $value;
        }

        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * Decodes a cursor from an encoded string.
     *
     * @see CursorWalker::buildCursorCondition() for the security model around cursor manipulation.
     *
     * @throws InvalidCursor If decoding fails or the payload is invalid.
     */
    public static function fromEncodedString(mixed $encodedString): self
    {
        if (! is_string($encodedString)) {
            throw new InvalidCursor('(non-string value)');
        }

        $decoded = base64_decode(strtr($encodedString, '-_', '+/'), strict: true);

        if ($decoded === false) {
            throw new InvalidCursor($encodedString);
        }

        try {
            $parameters = json_decode($decoded, associative: true, depth: 3, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidCursor($encodedString, $e);
        }

        if (! is_array($parameters)) {
            throw new InvalidCursor($encodedString);
        }

        $isNext = (bool) ($parameters['_isNext'] ?? true);

        unset($parameters['_isNext']);

        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                $parameters[$key] = self::decodeBase64Parameter($value) ?? throw new InvalidCursor($encodedString);

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidCursor($encodedString);
            }
        }

        return new self($parameters, $isNext);
    }

    /**
     * Decodes a parameter carried as {"base64": "..."}, or returns null if it is not one.
     *
     * @param array<array-key, mixed> $value
     */
    private static function decodeBase64Parameter(array $value): string|null
    {
        $base64 = $value[self::BASE64_KEY] ?? null;

        if (count($value) !== 1 || ! is_string($base64)) {
            return null;
        }

        $decoded = base64_decode($base64, strict: true);

        return $decoded === false ? null : $decoded;
    }
}
