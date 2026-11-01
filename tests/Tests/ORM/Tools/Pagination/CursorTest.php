<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\ORM\Tools\Pagination\Cursor;
use Doctrine\ORM\Tools\Pagination\Exception\InvalidCursor;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function base64_decode;
use function base64_encode;
use function json_decode;
use function json_encode;
use function rtrim;
use function strtr;

use const INF;

class CursorTest extends TestCase
{
    public function testCursorIsFinal(): void
    {
        $reflection = new ReflectionClass(Cursor::class);

        self::assertTrue($reflection->isFinal());
    }

    public function testEncodeAndDecode(): void
    {
        $parameters = ['id' => 10, 'name' => 'test'];
        $cursor     = new Cursor($parameters, true);

        $decoded = Cursor::fromEncodedString($cursor->encodeToString());

        self::assertSame($parameters, $decoded->getParameters());
        self::assertTrue($decoded->isNext());
    }

    public function testEncodeAndDecodeWithPreviousCursor(): void
    {
        $cursor = new Cursor(['id' => 10], false);

        $decoded = Cursor::fromEncodedString($cursor->encodeToString());

        self::assertTrue($decoded->isPrevious());
    }

    public function testEncodeProducesUrlSafeString(): void
    {
        $cursor  = new Cursor(['id' => 123456789, 'foo' => 'bar/baz+test']);
        $encoded = $cursor->encodeToString();

        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        self::assertStringNotContainsString('=', $encoded);
    }

    public function testFromEncodedStringThrowsForInvalidBase64(): void
    {
        $this->expectException(InvalidCursor::class);

        Cursor::fromEncodedString('not-valid-base64!@#$');
    }

    public function testFromEncodedStringThrowsForInvalidJson(): void
    {
        $this->expectException(InvalidCursor::class);

        Cursor::fromEncodedString(rtrim(strtr(base64_encode('not-json'), '+/', '-_'), '='));
    }

    public function testFromEncodedStringChainsPreviousExceptionForInvalidJson(): void
    {
        try {
            Cursor::fromEncodedString(rtrim(strtr(base64_encode('not-json'), '+/', '-_'), '='));
            self::fail('Expected InvalidCursor to be thrown.');
        } catch (InvalidCursor $e) {
            self::assertInstanceOf(JsonException::class, $e->getPrevious());
        }
    }

    public function testFromEncodedStringDefaultsToNextWhenIsNextMissing(): void
    {
        $json = json_encode(['id' => 10]);
        self::assertIsString($json);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $cursor = Cursor::fromEncodedString($encoded);

        self::assertTrue($cursor->isNext());
    }

    public function testToArray(): void
    {
        $cursor = new Cursor(['id' => 1], true);

        self::assertSame(['id' => 1, '_isNext' => true], $cursor->toArray());
    }

    public function testFromEncodedStringThrowsForNonStringInput(): void
    {
        $this->expectException(InvalidCursor::class);

        Cursor::fromEncodedString(['injected' => 'array']);
    }

    public function testFromEncodedStringThrowsForNonScalarParameterValue(): void
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['p.id' => [1, 2, 3], '_isNext' => true])), '+/', '-_'), '=');
        $this->expectException(InvalidCursor::class);
        Cursor::fromEncodedString($payload);
    }

    public function testFromEncodedStringCastsIsNextToBool(): void
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['id' => 10, '_isNext' => 0])), '+/', '-_'), '=');
        $cursor  = Cursor::fromEncodedString($payload);

        self::assertFalse($cursor->isNext());
        self::assertTrue($cursor->isPrevious());
    }

    public function testFromEncodedStringThrowsForNonArrayJson(): void
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode('just-a-string')), '+/', '-_'), '=');
        $this->expectException(InvalidCursor::class);
        Cursor::fromEncodedString($payload);
    }

    public function testEncodeAndDecodeBinaryParameter(): void
    {
        $parameters = ['u.id' => "\x01\x92\xe4\xa5\xff\x00", 'u.name' => 'test'];
        $cursor     = new Cursor($parameters, false);

        $decoded = Cursor::fromEncodedString($cursor->encodeToString());

        self::assertSame($parameters, $decoded->getParameters());
        self::assertTrue($decoded->isPrevious());
    }

    public function testEncodeListsOnlyBinaryParameters(): void
    {
        $cursor = new Cursor(['u.id' => "\xff", 'u.name' => 'é']);

        self::assertSame(
            ['u.id' => '/w==', 'u.name' => 'é', '_isNext' => true, '_binary' => ['u.id']],
            json_decode((string) base64_decode(strtr($cursor->encodeToString(), '-_', '+/')), true),
        );
    }

    public function testEncodeWithoutBinaryParameterKeepsThePayloadUnchanged(): void
    {
        $cursor = new Cursor(['id' => 10, 'name' => 'test']);

        self::assertSame(
            self::encode(['id' => 10, 'name' => 'test', '_isNext' => true]),
            $cursor->encodeToString(),
        );
    }

    public function testEncodeThrowsForUnencodableParameter(): void
    {
        $this->expectException(JsonException::class);

        (new Cursor(['price' => INF]))->encodeToString();
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidBinaryPayloads')]
    public function testFromEncodedStringThrowsForInvalidBinaryParameters(array $payload): void
    {
        $this->expectException(InvalidCursor::class);

        Cursor::fromEncodedString(self::encode($payload));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBinaryPayloads(): iterable
    {
        yield 'not a list' => [['u.id' => '/w==', '_binary' => 'u.id']];
        yield 'keyed list' => [['u.id' => '/w==', '_binary' => ['key' => 'u.id']]];
        yield 'unknown key' => [['u.id' => '/w==', '_binary' => ['u.name']]];
        yield 'non-string key' => [['u.id' => '/w==', '_binary' => [1]]];
        yield 'non-string value' => [['u.id' => 10, '_binary' => ['u.id']]];
        yield 'invalid base64' => [['u.id' => 'not base64!', '_binary' => ['u.id']]];

        // "QUJDRA==" decodes to "ABCD", itself valid Base64: decoding it twice would move the pivot
        yield 'duplicate key' => [['u.id' => 'QUJDRA==', '_binary' => ['u.id', 'u.id']]];
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }
}
