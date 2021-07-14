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

/**
 * @group DDC-2494
 * @group non-cacheable
 */
class DDC2494Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DDC2494TinyIntType::$calls = [];

        Type::addType('ddc2494_tinyint', DDC2494TinyIntType::class);

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC2494Currency::class),
                $this->_em->getClassMetadata(DDC2494Campaign::class),
            ]
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

        $this->assertArrayHasKey('convertToDatabaseValue', DDC2494TinyIntType::$calls);
        $this->assertCount(3, DDC2494TinyIntType::$calls['convertToDatabaseValue']);

        $item = $this->_em->find(DDC2494Campaign::class, $campaign->getId());

        $this->assertInstanceOf(DDC2494Campaign::class, $item);
        $this->assertInstanceOf(DDC2494Currency::class, $item->getCurrency());

        $queryCount = $this->getCurrentQueryCount();

        $this->assertInstanceOf('\Doctrine\Common\Proxy\Proxy', $item->getCurrency());
        $this->assertFalse($item->getCurrency()->__isInitialized());

        $this->assertArrayHasKey('convertToPHPValue', DDC2494TinyIntType::$calls);
        $this->assertCount(1, DDC2494TinyIntType::$calls['convertToPHPValue']);

        $this->assertIsInt($item->getCurrency()->getId());
        $this->assertCount(1, DDC2494TinyIntType::$calls['convertToPHPValue']);
        $this->assertFalse($item->getCurrency()->__isInitialized());

        $this->assertEquals($queryCount, $this->getCurrentQueryCount());

        $this->assertIsInt($item->getCurrency()->getTemp());
        $this->assertCount(3, DDC2494TinyIntType::$calls['convertToPHPValue']);
        $this->assertTrue($item->getCurrency()->__isInitialized());

        $this->assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }
}

/**
 * @Table(name="ddc2494_currency")
 * @Entity
 */
class DDC2494Currency
{
    /**
     * @var int
     * @Id
     * @Column(type="integer", type="ddc2494_tinyint")
     */
    protected $id;

    /**
     * @var int
     * @Column(name="temp", type="ddc2494_tinyint", nullable=false)
     */
    protected $temp;

    /**
     * @psalm-var Collection<int, DDC2494Campaign>
     * @OneToMany(targetEntity="DDC2494Campaign", mappedBy="currency")
     */
    protected $campaigns;

    public function __construct(int $id, int $temp)
    {
        $this->id   = $id;
        $this->temp = $temp;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTemp(): int
    {
        return $this->temp;
    }

    /**
     * @psalm-return Collection<int, DDC2494Campaign>
     */
    public function getCampaigns(): Collection
    {
        return $this->campaigns;
    }
}

/**
 * @Table(name="ddc2494_campaign")
 * @Entity
 */
class DDC2494Campaign
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    protected $id;

    /**
     * @var DDC2494Currency
     * @ManyToOne(targetEntity="DDC2494Currency", inversedBy="campaigns")
     * @JoinColumn(name="currency_id", referencedColumnName="id", nullable=false)
     */
    protected $currency;

    public function __construct(DDC2494Currency $currency)
    {
        $this->currency = $currency;
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
    /** @psalm-var array<string, list<array{value:mixed, return: string, platform: AbstractPlatform}>> */
    public static $calls = [];

    /**
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getSmallIntTypeDeclarationSQL($fieldDeclaration);
    }

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
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
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        $return = (int) $value;

        self::$calls[__FUNCTION__][] = [
            'value'     => $value,
            'return'    => $return,
            'platform'  => $platform,
        ];

        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'ddc2494_tinyint';
    }
}
