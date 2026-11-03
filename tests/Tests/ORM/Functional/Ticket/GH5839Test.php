<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Types\StringType;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function array_map;

/** @see https://github.com/doctrine/orm/issues/5839 */
#[Group('GH5839')]
final class GH5839Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(GH5839TrimmedStringType::NAME, GH5839TrimmedStringType::class);

        $this->createSchemaForModels(GH5839Zone::class);

        foreach (['   c' => 'C', 'bb' => 'B', '  a' => 'A'] as $boundary => $label) {
            $zone           = new GH5839Zone();
            $zone->boundary = $boundary;
            $zone->label    = $label;
            $this->_em->persist($zone);
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->_em)) {
            $this->_em->getConnection()->executeStatement('DELETE FROM GH5839Zone');
        }

        parent::tearDown();
    }

    public function testOrderOnConvertedColumn(): void
    {
        $query = $this->_em->createQuery(
            'SELECT z FROM ' . GH5839Zone::class . ' z ORDER BY LENGTH(z.boundary) ASC',
        );

        $page = (new OffsetPaginator())->paginate($query, new Window(0, 2));

        self::assertSame(
            ['B', 'A'],
            array_map(static fn (GH5839Zone $zone): string => $zone->label, $page->getItems()),
        );
    }

    #[DataProvider('useOutputWalkers')]
    public function testOrderOnComputedScalarAlias(bool $useOutputWalkers): void
    {
        $query = $this->_em->createQuery(
            'SELECT z, LENGTH(z.boundary) AS distance FROM ' . GH5839Zone::class . ' z ORDER BY distance ASC',
        );
        $query->setHydrationMode(Query::HYDRATE_ARRAY);

        $page = (new OffsetPaginator(true, $useOutputWalkers))->paginate($query, new Window(0, 2));

        self::assertSame(
            ['B', 'A'],
            array_map(static fn (array $row): string => $row[0]['label'], $page->getItems()),
        );
    }

    /** @return list<array{bool}> */
    public static function useOutputWalkers(): array
    {
        return [[true], [false]];
    }
}

#[Entity]
class GH5839Zone
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var string */
    #[Column(type: GH5839TrimmedStringType::NAME, length: 50)]
    public $boundary;

    /** @var string */
    #[Column(type: 'string', length: 50)]
    public $label;
}

final class GH5839TrimmedStringType extends StringType
{
    public const NAME = 'gh5839_trimmed_string';

    public function getName(): string
    {
        return self::NAME;
    }

    /** {@inheritDoc} */
    public function convertToPHPValueSQL($sqlExpr, $platform): string
    {
        return 'TRIM(' . $sqlExpr . ')';
    }
}
