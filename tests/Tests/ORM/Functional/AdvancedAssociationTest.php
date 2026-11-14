<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InverseJoinColumn;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Tests\IterableTester;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;
use function count;
use function is_numeric;

/**
 * Functional tests for the Single Table Inheritance mapping strategy.
 */
class AdvancedAssociationTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            Phrase::class,
            PhraseType::class,
            Definition::class,
            Lemma::class,
            Type::class,
        );
    }

    public function testIssue(): void
    {
        //setup
        $phrase = new Phrase();
        $phrase->setPhrase('lalala');

        $type = new PhraseType();
        $type->setType('nonsense');
        $type->setAbbreviation('non');

        $def1 = new Definition();
        $def1->setDefinition('def1');
        $def2 = new Definition();
        $def2->setDefinition('def2');

        $phrase->setType($type);
        $phrase->addDefinition($def1);
        $phrase->addDefinition($def2);

        $this->_em->persist($phrase);
        $this->_em->persist($type);

        $this->_em->flush();
        $this->_em->clear();
        //end setup

        // test1 - lazy-loading many-to-one after find()
        $phrase2 = $this->_em->find(Phrase::class, $phrase->getId());
        self::assertTrue(is_numeric($phrase2->getType()->getId()));

        $this->_em->clear();

        // test2 - eager load in DQL query
        $query = $this->_em->createQuery('SELECT p,t FROM Doctrine\Tests\ORM\Functional\Phrase p JOIN p.type t');
        $res   = $query->getResult();
        self::assertCount(1, $res);
        self::assertInstanceOf(PhraseType::class, $res[0]->getType());
        self::assertInstanceOf(PersistentCollection::class, $res[0]->getType()->getPhrases());
        self::assertFalse($res[0]->getType()->getPhrases()->isInitialized());

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);

        $this->_em->clear();

        // test2 - eager load in DQL query with double-join back and forth
        $query = $this->_em->createQuery('SELECT p,t,pp FROM Doctrine\Tests\ORM\Functional\Phrase p JOIN p.type t JOIN t.phrases pp');
        $res   = $query->getResult();
        self::assertCount(1, $res);
        self::assertInstanceOf(PhraseType::class, $res[0]->getType());
        self::assertInstanceOf(PersistentCollection::class, $res[0]->getType()->getPhrases());
        self::assertTrue($res[0]->getType()->getPhrases()->isInitialized());

        $this->_em->clear();

        // test3 - lazy-loading one-to-many after find()
        $phrase3     = $this->_em->find(Phrase::class, $phrase->getId());
        $definitions = $phrase3->getDefinitions();
        self::assertInstanceOf(PersistentCollection::class, $definitions);
        self::assertInstanceOf(Definition::class, $definitions[0]);

        $this->_em->clear();

        // test4 - lazy-loading after DQL query
        $query       = $this->_em->createQuery('SELECT p FROM Doctrine\Tests\ORM\Functional\Phrase p');
        $res         = $query->getResult();
        $definitions = $res[0]->getDefinitions();

        self::assertEquals(1, count($res));

        self::assertInstanceOf(Definition::class, $definitions[0]);
        self::assertEquals(2, $definitions->count());

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);
    }

    public function testManyToMany(): void
    {
        $lemma = new Lemma();
        $lemma->setLemma('abu');

        $type = new Type();
        $type->setType('nonsense');
        $type->setAbbreviation('non');

        $lemma->addType($type);

        $this->_em->persist($lemma);
        $this->_em->persist($type);
        $this->_em->flush();

        // test5 ManyToMany
        $query = $this->_em->createQuery('SELECT l FROM Doctrine\Tests\ORM\Functional\Lemma l');
        $res   = $query->getResult();
        $types = $res[0]->getTypes();

        self::assertInstanceOf(Type::class, $types[0]);

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);
    }
}

#[Table(name: 'lemma')]
#[Entity]
class Lemma
{
    final public const string CLASS_NAME = self::class;

    #[Id]
    #[Column(type: 'integer', name: 'lemma_id')]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[Column(type: 'string', name: 'lemma_name', unique: true, length: 255)]
    private string|null $lemma = null;

    #[ManyToMany(targetEntity: 'Type', mappedBy: 'lemmas', cascade: ['persist'])]
    private Collection $types;

    public function __construct()
    {
        $this->types = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setLemma(string $lemma): void
    {
        $this->lemma = $lemma;
    }

    public function getLemma(): string
    {
        return $this->lemma;
    }

    public function addType(Type $type): void
    {
        if (! $this->types->contains($type)) {
            $this->types[] = $type;
            $type->addLemma($this);
        }
    }

    public function removeType(Type $type): void
    {
        $removed = $this->sources->removeElement($type);
        if ($removed !== null) {
            $removed->removeLemma($this);
        }
    }

    public function getTypes(): Collection
    {
        return $this->types;
    }
}

#[Table(name: 'type')]
#[Entity]
class Type
{
    final public const string CLASS_NAME = self::class;

    #[Id]
    #[Column(type: 'integer', name: 'type_id')]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[Column(type: 'string', name: 'type_name', unique: true)]
    private string|null $type = null;

    #[Column(type: 'string', name: 'type_abbreviation', unique: true)]
    private string|null $abbreviation = null;

    #[JoinTable(name: 'lemma_type')]
    #[JoinColumn(name: 'type_id', referencedColumnName: 'type_id')]
    #[InverseJoinColumn(name: 'lemma_id', referencedColumnName: 'lemma_id')]
    #[ManyToMany(targetEntity: 'Lemma')]
    private Collection $lemmas;

    public function __construct()
    {
        $this->lemmas = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setAbbreviation(string $abbreviation): void
    {
        $this->abbreviation = $abbreviation;
    }

    public function getAbbreviation(): string
    {
        return $this->abbreviation;
    }

    public function addLemma(Lemma $lemma): void
    {
        if (! $this->lemmas->contains($lemma)) {
            $this->lemmas[] = $lemma;
            $lemma->addType($this);
        }
    }

    public function removeLEmma(Lemma $lemma): void
    {
        $removed = $this->lemmas->removeElement($lemma);
        if ($removed !== null) {
            $removed->removeType($this);
        }
    }

    public function getCategories(): Collection
    {
        return $this->categories;
    }
}


#[Table(name: 'phrase')]
#[Entity]
class Phrase
{
    final public const string CLASS_NAME = self::class;

    #[Id]
    #[Column(type: 'integer', name: 'phrase_id')]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[Column(type: 'string', name: 'phrase_name', unique: true, length: 255)]
    private string|null $phrase = null;

    #[ManyToOne(targetEntity: 'PhraseType')]
    #[JoinColumn(name: 'phrase_type_id', referencedColumnName: 'phrase_type_id')]
    private PhraseType|null $type = null;

    /** @phpstan-var Collection<int, Definition> */
    #[OneToMany(targetEntity: 'Definition', mappedBy: 'phrase', cascade: ['persist'])]
    private $definitions;

    public function __construct()
    {
        $this->definitions = new ArrayCollection();
    }

    public function addDefinition(Definition $definition): void
    {
        $this->definitions[] = $definition;
        $definition->setPhrase($this);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setPhrase(string $phrase): void
    {
        $this->phrase = $phrase;
    }

    public function getPhrase(): string
    {
        return $this->phrase;
    }

    public function setType(PhraseType $type): void
    {
        $this->type = $type;
    }

    public function getType(): PhraseType
    {
        return $this->type;
    }

    public function getDefinitions(): Collection
    {
        return $this->definitions;
    }
}

#[Table(name: 'phrase_type')]
#[Entity]
class PhraseType
{
    final public const string CLASS_NAME = self::class;

    #[Id]
    #[Column(type: 'integer', name: 'phrase_type_id')]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[Column(type: 'string', name: 'phrase_type_name', unique: true)]
    private string|null $type = null;

    #[Column(type: 'string', name: 'phrase_type_abbreviation', unique: true)]
    private string|null $abbreviation = null;

    /** @phpstan-var Collection<int, Phrase> */
    #[OneToMany(targetEntity: 'Phrase', mappedBy: 'type')]
    private $phrases;

    public function __construct()
    {
        $this->phrases = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setAbbreviation(string $abbreviation): void
    {
        $this->abbreviation = $abbreviation;
    }

    public function getAbbreviation(): string
    {
        return $this->abbreviation;
    }

    public function setPhrases(ArrayCollection $phrases): void
    {
        $this->phrases = $phrases;
    }

    public function getPhrases(): Collection
    {
        return $this->phrases;
    }
}

#[Table(name: 'definition')]
#[Entity]
class Definition
{
    final public const string CLASS_NAME = self::class;

    #[Id]
    #[Column(type: 'integer', name: 'definition_id')]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[ManyToOne(targetEntity: 'Phrase')]
    #[JoinColumn(name: 'definition_phrase_id', referencedColumnName: 'phrase_id')]
    private Phrase|null $phrase = null;

    #[Column(type: 'text', name: 'definition_text')]
    private string|null $definition = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function setPhrase(Phrase $phrase): void
    {
        $this->phrase = $phrase;
    }

    public function getPhrase(): Phrase
    {
        return $this->phrase;
    }

    public function removePhrase(): void
    {
        if ($this->phrase !== null) {
            $phrase = $this->phrase;
            assert($phrase instanceof Phrase);
            $this->phrase = null;
            $phrase->removeDefinition($this);
        }
    }

    public function setDefinition(string $definition): void
    {
        $this->definition = $definition;
    }

    public function getDefinition(): string
    {
        return $this->definition;
    }
}
