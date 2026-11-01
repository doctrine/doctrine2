<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Tools\Pagination\Exception\InvalidCursor;
use JsonException;

use function array_is_list;
use function array_key_exists;
use function base64_decode;
use function base64_encode;
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
     * Returns the parameters as they are bound: a binary value, such as a UUID
     * stored as binary, is raw bytes that JSON cannot encode. Use
     * {@see encodeToString()} to transport a cursor.
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
     * A parameter that is not valid UTF-8, such as a UUID stored as binary, is
     * Base64 encoded and its key listed under "_binary", so that
     * {@see fromEncodedString()} decodes it back.
     *
     * @throws JsonException If a parameter cannot be encoded, e.g. a non-finite float.
     */
    public function encodeToString(): string
    {
        $payload = $this->toArray();
        $binary  = [];

        foreach ($this->parameters as $key => $value) {
            if (! is_string($value) || preg_match('//u', $value) === 1) {
                continue;
            }

            $payload[$key] = base64_encode($value);
            $binary[]      = $key;
        }

        if ($binary !== []) {
            $payload['_binary'] = $binary;
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
        $binary = $parameters['_binary'] ?? [];

        unset($parameters['_isNext'], $parameters['_binary']);

        foreach ($parameters as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidCursor($encodedString);
            }
        }

        if (! is_array($binary) || ! array_is_list($binary)) {
            throw new InvalidCursor($encodedString);
        }

        $decodedKeys = [];

        foreach ($binary as $key) {
            if (! is_string($key) || isset($decodedKeys[$key]) || ! array_key_exists($key, $parameters) || ! is_string($parameters[$key])) {
                throw new InvalidCursor($encodedString);
            }

            $decodedKeys[$key] = true;

            $value = base64_decode($parameters[$key], strict: true);

            if ($value === false) {
                throw new InvalidCursor($encodedString);
            }

            $parameters[$key] = $value;
        }

        return new self($parameters, $isNext);
    }
}
