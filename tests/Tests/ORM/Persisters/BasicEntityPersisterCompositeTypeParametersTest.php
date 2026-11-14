<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Persisters;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Models\Enums\Suit;
use Doctrine\Tests\Models\GeoNames\Admin1;
use Doctrine\Tests\Models\GeoNames\Admin1AlternateName;
use Doctrine\Tests\Models\GeoNames\Country;
use Doctrine\Tests\OrmTestCase;

use function defined;

class BasicEntityPersisterCompositeTypeParametersTest extends OrmTestCase
{
    protected BasicEntityPersister $persister;
    protected EntityManagerMock $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = $this->getTestEntityManager();

        $this->entityManager->getClassMetadata(Country::class);
        $this->entityManager->getClassMetadata(Admin1::class);
        $this->entityManager->getClassMetadata(Admin1AlternateName::class);

        $this->persister = new BasicEntityPersister($this->entityManager, $this->entityManager->getClassMetadata(Admin1AlternateName::class));
    }

    public function testExpandParametersWillExpandCompositeEntityKeys(): void
    {
        $country = new Country('IT', 'Italy');
        $admin1  = new Admin1(10, 'Rome', $country);

        [$values, $types] = $this->persister->expandParameters(['admin1' => $admin1]);

        self::assertEquals(['integer', 'string'], $types);
        self::assertEquals([10, 'IT'], $values);
    }

    public function testExpandParametersPreservesCompositeIdentifierAndCriteriaOrder(): void
    {
        $rome  = new Admin1(10, 'Rome', new Country('IT', 'Italy'));
        $paris = new Admin1(20, 'Paris', new Country('FR', 'France'));

        [$values, $types] = $this->persister->expandParameters([
            'name'   => ['first' => 'Rome', 7 => null, 'nested' => ['enum' => Suit::Hearts, 'city' => 'Paris'], 'last' => 'Rome'],
            'admin1' => [9 => $rome, 'skip' => null, 2 => $paris, 'duplicate' => $rome],
            'id'     => 'suffix',
        ]);

        self::assertSame(['Rome', ['H', 'Paris'], 'Rome', 10, 'IT', 20, 'FR', 10, 'IT', 'suffix'], $values);
        self::assertSame(['string', ArrayParameterType::STRING, 'string', 'integer', 'string', 'integer', 'string', 'integer', 'string', 'string'], $types);
    }

    public function testExpandCriteriaParametersWillExpandCompositeEntityKeys(): void
    {
        $country = new Country('IT', 'Italy');
        $admin1  = new Admin1(10, 'Rome', $country);

        $criteria = Criteria::create();
        $criteria->andWhere(Criteria::expr()->eq('admin1', $admin1));

        [$values, $types] = $this->persister->expandCriteriaParameters($criteria);

        self::assertEquals(['integer', 'string'], $types);
        self::assertEquals([10, 'IT'], $values);
    }

    public function testExpandCriteriaParametersPreservesValueAndTypeOrder(): void
    {
        $rome  = new Admin1(10, 'Rome', new Country('IT', 'Italy'));
        $paris = new Admin1(20, 'Paris', new Country('FR', 'France'));

        $criteria = defined(Criteria::class . '::ASC') ? Criteria::create(true) : Criteria::create();
        $criteria->where(Criteria::expr()->in('name', ['first' => Suit::Hearts, 9 => null, 'duplicate' => Suit::Hearts]));
        $criteria->andWhere(Criteria::expr()->notIn('admin1', [7 => $rome, 'null' => null, 2 => $paris, 'duplicate' => $rome]));
        $criteria->andWhere(Criteria::expr()->eq('id', Suit::Spades));
        $criteria->andWhere(Criteria::expr()->eq('name', null));
        $criteria->andWhere(Criteria::expr()->neq('id', null));

        [$values, $types] = $this->persister->expandCriteriaParameters($criteria);

        self::assertSame(['H', 'H', 10, 'IT', 20, 'FR', 10, 'IT', 'S'], $values);
        self::assertSame(['string', 'string', 'integer', 'string', 'integer', 'string', 'integer', 'string', 'string'], $types);
    }
}
