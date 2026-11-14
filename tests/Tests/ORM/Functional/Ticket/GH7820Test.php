<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemInterface;
use SortDirection;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

use function array_map;
use function is_string;

/**
 * When using a {@see \Doctrine\ORM\Tools\Pagination\OffsetPaginator} to iterate over a query
 * that has entities with a custom DBAL type used in the identifier, then `$id->__toString()`
 * is used implicitly by {@see \PDOStatement::bindValue()}, instead of being converted by the
 * expected {@see \Doctrine\DBAL\Types\Type::convertToDatabaseValue()}.
 *
 * In order to reproduce this, you must have identifiers implementing
 * `#__toString()` (to allow {@see \Doctrine\ORM\UnitOfWork} to hash them) and other accessors
 * that are used by the custom DBAL type during DB/PHP conversions.
 *
 * If `#__toString()` and the DBAL type conversions are asymmetric, then the paginator will fail
 * to find records.
 * Tricky situation, but this very much affects `ramsey/uuid-doctrine` and anyone relying on (for
 * example) the {@see \Ramsey\Uuid\Doctrine\UuidBinaryType} type.
 */
#[Group('GH7820')]
class GH7820Test extends OrmFunctionalTestCase
{
    private const array SONG = [
        'What is this song all about?',
        'Can\'t figure any lyrics out',
        'How do the words to it go?',
        'I wish you\'d tell me, I don\'t know',
        'Don\'t know, don\'t know, don\'t know, I don\'t know!',
        'Don\'t know, don\'t know, don\'t know...',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(GH7820LineTextType::class, GH7820LineTextType::class);

        $this->setUpEntitySchema([GH7820Line::class]);

        $this->_em->createQuery('DELETE FROM ' . GH7820Line::class . ' l')
            ->execute();

        foreach (self::SONG as $index => $line) {
            $this->_em->persist(new GH7820Line(GH7820LineText::fromText($line), $index));
        }

        $this->_em->flush();
    }

    public function testWillFindSongsInPaginator(): void
    {
        $lines = $this->fetchSongLinesWithPaginator();

        self::assertSame(self::SONG, $lines);
    }

    #[Group('GH7837')]
    public function testWillFindSongsInPaginatorEvenWithCachedQueryParsing(): void
    {
        // Enable the query cache
        $this->_em->getConfiguration()
            ->getQueryCache()
            ->clear();

        // Fetch song lines with the paginator, also priming the query cache
        $lines = $this->fetchSongLinesWithPaginator();
        self::assertSame(self::SONG, $lines, 'Expected to return expected data before query cache is populated with DQL -> SQL translation. Were SQL parameters translated?');

        // Fetch song lines again
        $lines = $this->fetchSongLinesWithPaginator();
        self::assertSame(self::SONG, $lines, 'Expected to return expected data even when DQL -> SQL translation is present in cache. Were SQL parameters translated again?');
    }

    public function testPaginatorDoesNotForceCacheToUpdateEntries(): void
    {
        $this->_em->getConfiguration()->setQueryCache(new class extends ArrayAdapter {
            public function save(CacheItemInterface $item): bool
            {
                Assert::assertFalse($this->hasItem($item->getKey()), 'The cache should not have to overwrite the entry');

                return parent::save($item);
            }
        });

        // "Prime" the cache (in fact, that should not even happen)
        $this->fetchSongLinesWithPaginator();

        // Make sure we can query again without overwriting the cache
        $this->fetchSongLinesWithPaginator();
    }

    public function testPaginatorQueriesWillBeCached(): void
    {
        $cache = new class extends ArrayAdapter {
            /** @var bool */
            private $failOnCacheMiss = false;

            public function failOnCacheMiss(): void
            {
                $this->failOnCacheMiss = true;
            }

            public function getItem($key): CacheItem
            {
                $item = parent::getItem($key);
                Assert::assertTrue(! $this->failOnCacheMiss || $item->isHit(), 'cache was missed');

                return $item;
            }
        };
        $this->_em->getConfiguration()->setQueryCache($cache);

        // Prime the cache
        $this->fetchSongLinesWithPaginator();

        $cache->failOnCacheMiss();

        $this->fetchSongLinesWithPaginator();
    }

    private function fetchSongLinesWithPaginator(): array
    {
        $query = $this->_em->getRepository(GH7820Line::class)
            ->createQueryBuilder('l')
            ->orderBy('l.lineNumber', SortDirection::Ascending);

        $page = (new OffsetPaginator())->paginate($query, new Window(0, 100));

        return array_map(static fn (GH7820Line $line): string => $line->toString(), $page->getItems());
    }
}

#[Entity]
class GH7820Line
{
    public function __construct(
        #[Id]
        #[Column(type: 'Doctrine\Tests\ORM\Functional\Ticket\GH7820LineTextType', length: 255)]
        private GH7820LineText $text,
        #[Column(type: 'integer')]
        private int $lineNumber,
    ) {
    }

    public function toString(): string
    {
        return $this->text->getText();
    }
}

final class GH7820LineText implements Stringable
{
    private function __construct(private string $text)
    {
    }

    public static function fromText(string $text): self
    {
        return new self($text);
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function __toString(): string
    {
        return 'Line: ' . $this->text;
    }
}

final class GH7820LineTextType extends StringType
{
    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): mixed
    {
        $text = parent::convertToPHPValue($value, $platform);

        if (! is_string($text)) {
            return $text;
        }

        return GH7820LineText::fromText($text);
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        if (! $value instanceof GH7820LineText) {
            return parent::convertToDatabaseValue($value, $platform);
        }

        return parent::convertToDatabaseValue($value->getText(), $platform);
    }

    /** {@inheritDoc} */
    public function getName(): string
    {
        return self::class;
    }
}
