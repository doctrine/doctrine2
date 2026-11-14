<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Query\Expr\Func;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\Mocks\AttributeDriverFactory;
use Doctrine\Tests\Models\DataTransferObjects\DtoWithArrayOfEnums;
use Doctrine\Tests\Models\DataTransferObjects\DtoWithEnum;
use Doctrine\Tests\Models\Enums\BookCategory;
use Doctrine\Tests\Models\Enums\BookGenre;
use Doctrine\Tests\Models\Enums\BookWithGenre;
use Doctrine\Tests\Models\Enums\Card;
use Doctrine\Tests\Models\Enums\CardNativeEnum;
use Doctrine\Tests\Models\Enums\CardWithDefault;
use Doctrine\Tests\Models\Enums\CardWithNullable;
use Doctrine\Tests\Models\Enums\Library;
use Doctrine\Tests\Models\Enums\Product;
use Doctrine\Tests\Models\Enums\Quantity;
use Doctrine\Tests\Models\Enums\Scale;
use Doctrine\Tests\Models\Enums\Suit;
use Doctrine\Tests\Models\Enums\TypedCard;
use Doctrine\Tests\Models\Enums\TypedCardNativeEnum;
use Doctrine\Tests\Models\Enums\Unit;
use Doctrine\Tests\OrmFunctionalTestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;
use function uniqid;

class EnumTest extends OrmFunctionalTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $mappingDriver = AttributeDriverFactory::createAttributeDriver([__DIR__ . '/../../Models/Enums']);

        $this->_em         = $this->getEntityManager(null, $mappingDriver);
        $this->_schemaTool = new SchemaTool($this->_em);

        if ($this->isSecondLevelCacheEnabled) {
            $this->markTestSkipped();
        }
    }

    /** @param class-string $cardClass */
    #[DataProvider('provideCardClasses')]
    public function testEnumMapping(string $cardClass): void
    {
        $this->setUpEntitySchema([$cardClass]);

        $card       = new $cardClass();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $fetchedCard = $this->_em->find($cardClass, $card->id);

        $this->assertInstanceOf(Suit::class, $fetchedCard->suit);
        $this->assertEquals(Suit::Clubs, $fetchedCard->suit);
    }

    public function testEnumHydrationObjectHydrator(): void
    {
        $this->setUpEntitySchema([Card::class]);

        $card1       = new Card();
        $card1->suit = Suit::Clubs;
        $card2       = new Card();
        $card2->suit = Suit::Hearts;

        $this->_em->persist($card1);
        $this->_em->persist($card2);
        $this->_em->flush();

        unset($card1, $card2);
        $this->_em->clear();

        /** @var list<Card> $foundCards */
        $foundCards = $this->_em->createQueryBuilder()
            ->select('c')
            ->from(Card::class, 'c')
            ->where('c.suit = :suit')
            ->setParameter('suit', Suit::Clubs)
            ->getQuery()
            ->getResult();

        self::assertNotEmpty($foundCards);
        foreach ($foundCards as $card) {
            self::assertSame(Suit::Clubs, $card->suit);
        }
    }

    public function testEnumArrayHydrationObjectHydrator(): void
    {
        $this->setUpEntitySchema([Scale::class]);

        $scale                 = new Scale();
        $scale->supportedUnits = [Unit::Gram, Unit::Meter];

        $this->_em->persist($scale);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(Scale::class, 's')
            ->select('s')
            ->getQuery()
            ->getResult();

        self::assertInstanceOf(Scale::class, $result[0]);
        self::assertEqualsCanonicalizing([Unit::Gram, Unit::Meter], $result[0]->supportedUnits);
    }

    public function testEnumHydration(): void
    {
        $this->setUpEntitySchema([Card::class, CardWithNullable::class]);

        $card       = new Card();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(Card::class, 'c')
            ->select('c.id, c.suit')
            ->getQuery()
            ->getResult();

        $this->assertInstanceOf(Suit::class, $result[0]['suit']);
        $this->assertEquals(Suit::Clubs, $result[0]['suit']);
    }

    public function testEnumHydrationArrayHydrator(): void
    {
        $this->setUpEntitySchema([Card::class, CardWithNullable::class]);

        $card       = new Card();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(Card::class, 'c')
            ->select('c')
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        $this->assertInstanceOf(Suit::class, $result[0]['suit']);
        $this->assertEquals(Suit::Clubs, $result[0]['suit']);
    }

    public function testNullableEnumHydration(): void
    {
        $this->setUpEntitySchema([Card::class, CardWithNullable::class]);

        $cardWithNullable       = new CardWithNullable();
        $cardWithNullable->suit = null;

        $this->_em->persist($cardWithNullable);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(CardWithNullable::class, 'c')
            ->select('c.id, c.suit')
            ->getQuery()
            ->getResult();

        $this->assertNull($result[0]['suit']);
    }

    public function testEnumArrayHydration(): void
    {
        $this->setUpEntitySchema([Scale::class]);

        $scale                 = new Scale();
        $scale->supportedUnits = [Unit::Gram, Unit::Meter];

        $this->_em->persist($scale);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(Scale::class, 's')
            ->select('s.id, s.supportedUnits')
            ->getQuery()
            ->getResult();

        self::assertEqualsCanonicalizing([Unit::Gram, Unit::Meter], $result[0]['supportedUnits']);
    }

    public function testEnumInDtoHydration(): void
    {
        $this->setUpEntitySchema([Card::class, CardWithNullable::class]);

        $card       = new Card();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(CardWithNullable::class, 'c')
            ->select('NEW ' . DtoWithEnum::class . '(c.suit)')
            ->getQuery()
            ->getResult();

        $this->assertNull($result[0]->suit);
    }

    public function testNullableEnumInDtoHydration(): void
    {
        $this->setUpEntitySchema([Card::class, CardWithNullable::class]);

        $cardWithNullable       = new CardWithNullable();
        $cardWithNullable->suit = null;

        $this->_em->persist($cardWithNullable);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(CardWithNullable::class, 'c')
            ->select('NEW ' . DtoWithEnum::class . '(c.suit)')
            ->getQuery()
            ->getResult();

        $this->assertNull($result[0]->suit);
    }

    public function testEnumArrayInDtoHydration(): void
    {
        $this->setUpEntitySchema([Scale::class]);

        $scale                 = new Scale();
        $scale->supportedUnits = [Unit::Gram, Unit::Meter];

        $this->_em->persist($scale);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(Scale::class, 's')
            ->select(new Func('NEW ' . DtoWithArrayOfEnums::class, ['s.supportedUnits']))
            ->getQuery()
            ->getResult();

        self::assertEqualsCanonicalizing([Unit::Gram, Unit::Meter], $result[0]->supportedUnits);
    }

    public function testEnumSingleEntityChangeSetsSimpleObjectHydrator(): void
    {
        $this->setUpEntitySchema([Card::class]);

        $card       = new Card();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->find(Card::class, $card->id);

        $this->_em->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $this->_em->getClassMetadata(Card::class),
            $result,
        );

        self::assertFalse($this->_em->getUnitOfWork()->isScheduledForUpdate($result));

        $result->suit = Suit::Hearts;

        $this->_em->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $this->_em->getClassMetadata(Card::class),
            $result,
        );

        self::assertTrue($this->_em->getUnitOfWork()->isScheduledForUpdate($result));
    }

    public function testEnumSingleEntityChangeSetsObjectHydrator(): void
    {
        $this->setUpEntitySchema([Card::class]);

        $card       = new Card();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->find(Card::class, $card->id);

        $this->_em->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $this->_em->getClassMetadata(Card::class),
            $result,
        );

        self::assertFalse($this->_em->getUnitOfWork()->isScheduledForUpdate($result));
    }

    public function testEnumArraySingleEntityChangeSets(): void
    {
        $this->setUpEntitySchema([Scale::class]);

        $scale                 = new Scale();
        $scale->supportedUnits = [Unit::Gram];

        $this->_em->persist($scale);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->find(Scale::class, $scale->id);

        $this->_em->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $this->_em->getClassMetadata(Scale::class),
            $result,
        );

        self::assertFalse($this->_em->getUnitOfWork()->isScheduledForUpdate($result));
    }

    public function testEnumChangeSetsSimpleObjectHydrator(): void
    {
        $this->setUpEntitySchema([Card::class]);

        $card       = new Card();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->find(Card::class, $card->id);

        $this->_em->getUnitOfWork()->computeChangeSets();

        self::assertFalse($this->_em->getUnitOfWork()->isScheduledForUpdate($result));
    }

    public function testEnumChangeSetsObjectHydrator(): void
    {
        $this->setUpEntitySchema([Card::class]);

        $card       = new Card();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(Card::class, 'c')
            ->select('c')
            ->getQuery()
            ->getResult();

        $this->_em->getUnitOfWork()->computeChangeSets();

        self::assertFalse($this->_em->getUnitOfWork()->isScheduledForUpdate($result[0]));
    }

    public function testEnumArrayChangeSets(): void
    {
        $this->setUpEntitySchema([Scale::class]);

        $scale                 = new Scale();
        $scale->supportedUnits = [Unit::Gram];

        $this->_em->persist($scale);
        $this->_em->flush();
        $this->_em->clear();

        $result = $this->_em->createQueryBuilder()
            ->from(Scale::class, 's')
            ->select('s')
            ->getQuery()
            ->getResult();

        $this->_em->getUnitOfWork()->computeChangeSets();

        self::assertFalse($this->_em->getUnitOfWork()->isScheduledForUpdate($result[0]));
    }

    public function testFindByEnum(): void
    {
        $this->setUpEntitySchema([Card::class]);

        $card1       = new Card();
        $card1->suit = Suit::Clubs;
        $card2       = new Card();
        $card2->suit = Suit::Hearts;

        $this->_em->persist($card1);
        $this->_em->persist($card2);
        $this->_em->flush();

        unset($card1, $card2);
        $this->_em->clear();

        /** @var list<Card> $foundCards */
        $foundCards = $this->_em->getRepository(Card::class)->findBy(['suit' => Suit::Clubs]);
        $this->assertNotEmpty($foundCards);
        foreach ($foundCards as $card) {
            $this->assertSame(Suit::Clubs, $card->suit);
        }
    }

    /** @param class-string $cardClass */
    #[DataProvider('provideCardClasses')]
    public function testEnumWithNonMatchingDatabaseValueThrowsException(string $cardClass): void
    {
        if ($cardClass === TypedCardNativeEnum::class) {
            self::markTestSkipped('MySQL won\'t allow us to insert invalid values in this case.');
        }

        $this->setUpEntitySchema([$cardClass]);

        $card       = new $cardClass();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $metadata = $this->_em->getClassMetadata($cardClass);
        $this->_em->getConnection()->update(
            $metadata->table['name'],
            [$metadata->fieldMappings['suit']->columnName => 'Z'],
            [$metadata->fieldMappings['id']->columnName => $card->id],
        );

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(sprintf(
            <<<'EXCEPTION'
Context: Trying to hydrate enum property "%s::$suit"
Problem: Case "Z" is not listed in enum "Doctrine\Tests\Models\Enums\Suit"
Solution: Either add the case to the enum type or migrate the database column to use another case of the enum
EXCEPTION
            ,
            $cardClass,
        ));

        $this->_em->find($cardClass, $card->id);
    }

    /** @return iterable<string, array{class-string}> */
    public static function provideCardClasses(): iterable
    {
        yield Card::class => [Card::class];
        yield TypedCard::class => [TypedCard::class];
        yield CardNativeEnum::class => [CardNativeEnum::class];
        yield TypedCardNativeEnum::class => [TypedCardNativeEnum::class];
    }

    public function testItAllowsReadingAttributes(): void
    {
        $metadata = $this->_em->getClassMetadata(Card::class);
        $property = $metadata->propertyAccessors['suit']->getUnderlyingReflector();

        $attributes = $property->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertEquals(Column::class, $attributes[0]->getName());
    }

    public function testEnumMappingWithEmbeddable(): void
    {
        $this->setUpEntitySchema([Product::class]);

        $product                  = new Product();
        $product->quantity        = new Quantity();
        $product->quantity->value = 10;
        $product->quantity->unit  = Unit::Gram;

        $this->_em->persist($product);
        $this->_em->flush();
        $this->_em->clear();

        $fetchedProduct = $this->_em->find(Product::class, $product->id);

        $this->assertInstanceOf(Unit::class, $fetchedProduct->quantity->unit);
        $this->assertEquals(Unit::Gram, $fetchedProduct->quantity->unit);
    }

    public function testEnumArrayMapping(): void
    {
        $this->setUpEntitySchema([Scale::class]);

        $scale                 = new Scale();
        $scale->supportedUnits = [Unit::Gram, Unit::Meter];

        $this->_em->persist($scale);
        $this->_em->flush();
        $this->_em->clear();

        $fetchedScale = $this->_em->find(Scale::class, $scale->id);

        $this->assertIsArray($fetchedScale->supportedUnits);
        $this->assertContains(Unit::Gram, $fetchedScale->supportedUnits);
        $this->assertContains(Unit::Meter, $fetchedScale->supportedUnits);
    }

    public function testEnumWithDefault(): void
    {
        $this->setUpEntitySchema([CardWithDefault::class]);

        $table  = $this->_em->getClassMetadata(CardWithDefault::class)->getTableName();
        $cardId = uniqid('', true);

        $this->_em->getConnection()->insert($table, ['id' => $cardId]);
        $card = $this->_em->find(CardWithDefault::class, $cardId);

        self::assertSame(Suit::Hearts, $card->suit);
    }

    #[DataProvider('provideGenreMatchingExpressions')]
    public function testEnumCollectionMatchingOnOneToMany(Comparison $comparison): void
    {
        $this->setUpEntitySchema([BookWithGenre::class, Library::class, BookCategory::class]);

        $library = new Library();

        $fictionBook          = new BookWithGenre(BookGenre::FICTION);
        $fictionBook->library = $library;

        $nonfictionBook          = new BookWithGenre(BookGenre::NON_FICTION);
        $nonfictionBook->library = $library;

        $this->_em->persist($library);
        $this->_em->persist($nonfictionBook);
        $this->_em->persist($fictionBook);

        $this->_em->flush();
        $this->_em->clear();

        $library = $this->_em->find(Library::class, $library->id);
        self::assertFalse($library->books->isInitialized(), 'Pre-condition: lazy collection');

        $result = $library->books->matching(Criteria::create()->where($comparison));

        self::assertCount(1, $result);
        self::assertSame($nonfictionBook->id, $result[0]->id);
    }

    #[DataProvider('provideGenreMatchingExpressions')]
    public function testEnumCollectionMatchingOnManyToMany(Comparison $comparison): void
    {
        $this->setUpEntitySchema([Library::class, BookWithGenre::class, BookCategory::class]);

        $category = new BookCategory();

        $fictionBook = new BookWithGenre(BookGenre::FICTION);
        $fictionBook->categories->add($category);

        $nonfictionBook = new BookWithGenre(BookGenre::NON_FICTION);
        $nonfictionBook->categories->add($category);

        $this->_em->persist($category);
        $this->_em->persist($nonfictionBook);
        $this->_em->persist($fictionBook);

        $this->_em->flush();
        $this->_em->clear();

        $category = $this->_em->find(BookCategory::class, $category->id);
        self::assertFalse($category->books->isInitialized(), 'Pre-condition: lazy collection');

        $result = $category->books->matching(Criteria::create()->where($comparison));

        self::assertCount(1, $result);
        self::assertSame($nonfictionBook->id, $result[0]->id);
    }

    public static function provideGenreMatchingExpressions(): Generator
    {
        yield [Criteria::expr()->eq('genre', BookGenre::NON_FICTION)];
        yield [Criteria::expr()->in('genre', [BookGenre::NON_FICTION])];
    }
}
