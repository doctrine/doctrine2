<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('DDC-2494')]
#[Group('non-cacheable')]
class DDC2494Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DDC2494TinyIntType::$calls = [];

        self::registerDbalType('ddc2494_tinyint', DDC2494TinyIntType::class);

        $this->createSchemaForModels(
            DDC2494Currency::class,
            DDC2494Campaign::class,
        );
    }

    public function testIssue(): void
    {
        $currency = new DDC2494Currency(1, 2);

        $this->_em->persist($currency);
        $this->_em->flush();

        $campaign = new DDC2494Campaign($currency);

        $this->_em->persist($campaign);
        $this->_em->flush();
        $this->_em->close();

        self::assertArrayHasKey('convertToDatabaseValue', DDC2494TinyIntType::$calls);
        self::assertCount(3, DDC2494TinyIntType::$calls['convertToDatabaseValue']);

        $item = $this->_em->find(DDC2494Campaign::class, $campaign->getId());

        self::assertInstanceOf(DDC2494Campaign::class, $item);
        self::assertInstanceOf(DDC2494Currency::class, $item->getCurrency());

        $this->getQueryLog()->reset()->enable();

        self::assertTrue($this->isUninitializedObject($item->getCurrency()));

        self::assertArrayHasKey('convertToPHPValue', DDC2494TinyIntType::$calls);
        self::assertCount(1, DDC2494TinyIntType::$calls['convertToPHPValue']);

        self::assertIsInt($item->getCurrency()->getId());
        self::assertCount(1, DDC2494TinyIntType::$calls['convertToPHPValue']);
        self::assertTrue($this->isUninitializedObject($item->getCurrency()));

        $this->assertQueryCount(0);

        self::assertIsInt($item->getCurrency()->getTemp());
        self::assertCount(3, DDC2494TinyIntType::$calls['convertToPHPValue']);
        self::assertFalse($this->isUninitializedObject($item->getCurrency()));

        $this->assertQueryCount(1);
    }
}

#[Table(name: 'ddc2494_currency')]
#[Entity]
class DDC2494Currency
{
    /** @phpstan-var Collection<int, DDC2494Campaign> */
    #[OneToMany(targetEntity: 'DDC2494Campaign', mappedBy: 'currency')]
    protected $campaigns;

    public function __construct(
        #[Id]
        #[Column(type: 'ddc2494_tinyint')]
        protected int $id,
        #[Column(name: 'temp', type: 'ddc2494_tinyint', nullable: false)]
        protected int $temp,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTemp(): int
    {
        return $this->temp;
    }

    /** @phpstan-return Collection<int, DDC2494Campaign> */
    public function getCampaigns(): Collection
    {
        return $this->campaigns;
    }
}

#[Table(name: 'ddc2494_campaign')]
#[Entity]
class DDC2494Campaign
{
    /** @var int */
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    protected $id;

    public function __construct(
        #[ManyToOne(targetEntity: 'DDC2494Currency', inversedBy: 'campaigns')]
        #[JoinColumn(name: 'currency_id', referencedColumnName: 'id', nullable: false)]
        protected DDC2494Currency $currency,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCurrency(): DDC2494Currency
    {
        return $this->currency;
    }
}

class DDC2494TinyIntType extends Type
{
    /** @phpstan-var array<string, list<array{value:mixed, return: string, platform: AbstractPlatform}>> */
    public static $calls = [];

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getSmallIntTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): string
    {
        $return = (string) $value;

        self::$calls[__FUNCTION__][] = [
            'value'     => $value,
            'return'    => $return,
            'platform'  => $platform,
        ];

        return $return;
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): int
    {
        $return = (int) $value;

        self::$calls[__FUNCTION__][] = [
            'value'     => $value,
            'return'    => $return,
            'platform'  => $platform,
        ];

        return $return;
    }

    public function getName(): string
    {
        return 'ddc2494_tinyint';
    }
}
