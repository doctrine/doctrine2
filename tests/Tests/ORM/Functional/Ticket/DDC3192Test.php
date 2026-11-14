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

use function array_search;

#[Group('DDC-2494')]
#[Group('non-cacheable')]
class DDC3192Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (self::hasDbalType('ddc3192_currency_code')) {
            self::fail(
                'Type ddc3192_currency_code exists for testing DDC-3192 only, ' .
                'but it has already been registered for some reason',
            );
        }

        self::registerDbalType('ddc3192_currency_code', DDC3192CurrencyCode::class);

        $this->createSchemaForModels(
            DDC3192Currency::class,
            DDC3192Transaction::class,
        );
    }

    public function testIssue(): void
    {
        $currency = new DDC3192Currency('BYR');

        $this->_em->persist($currency);
        $this->_em->flush();

        $amount      = 50;
        $transaction = new DDC3192Transaction($amount, $currency);

        $this->_em->persist($transaction);
        $this->_em->flush();
        $this->_em->close();

        $resultByPersister = $this->_em->find(DDC3192Transaction::class, $transaction->id);

        // This works: DDC2494 makes persister set type mapping info to ResultSetMapping
        self::assertEquals('BYR', $resultByPersister->currency->code);

        $this->_em->close();

        $query = $this->_em->createQuery();
        $query->setDQL('SELECT t FROM ' . DDC3192Transaction::class . ' t WHERE t.id = ?1');
        $query->setParameter(1, $transaction->id);

        $resultByQuery = $query->getSingleResult();

        // This is fixed here: before the fix it used to return 974.
        // because unlike the BasicEntityPersister, SQLWalker doesn't set type info
        self::assertEquals('BYR', $resultByQuery->currency->code);
    }
}

#[Table(name: 'ddc3192_currency')]
#[Entity]
class DDC3192Currency
{
    /** @var Collection<int, DDC3192Transaction> */
    #[OneToMany(targetEntity: 'DDC3192Transaction', mappedBy: 'currency')]
    public $transactions;

    public function __construct(
        #[Id]
        #[Column(type: 'ddc3192_currency_code')]
        public string $code,
    ) {
    }
}

#[Table(name: 'ddc3192_transaction')]
#[Entity]
class DDC3192Transaction
{
    /** @var int */
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public $id;

    public function __construct(
        #[Column(type: 'integer')]
        public int $amount,
        #[ManyToOne(targetEntity: 'DDC3192Currency', inversedBy: 'transactions')]
        #[JoinColumn(name: 'currency_id', referencedColumnName: 'code', nullable: false)]
        public DDC3192Currency $currency,
    ) {
    }
}

class DDC3192CurrencyCode extends Type
{
    /** @phpstan-var array<string, int> */
    private static array $map = ['BYR' => 974];

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
    public function convertToDatabaseValue($value, AbstractPlatform $platform): int
    {
        return self::$map[$value];
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): mixed
    {
        return array_search((int) $value, self::$map, true);
    }

    public function getName(): string
    {
        return 'ddc3192_currency_code';
    }
}
