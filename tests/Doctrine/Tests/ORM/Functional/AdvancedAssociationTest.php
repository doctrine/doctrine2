<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
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
        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(Phrase::class),
            $this->_em->getClassMetadata(PhraseType::class),
            $this->_em->getClassMetadata(Definition::class),
            $this->_em->getClassMetadata(Lemma::class),
            $this->_em->getClassMetadata(Type::class),
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->_schemaTool->dropSchema([
            $this->_em->getClassMetadata(Phrase::class),
            $this->_em->getClassMetadata(PhraseType::class),
            $this->_em->getClassMetadata(Definition::class),
            $this->_em->getClassMetadata(Lemma::class),
            $this->_em->getClassMetadata(Type::class),
        ]);
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
        $this->assertTrue(is_numeric($phrase2->getType()->getId()));

        $this->_em->clear();

        // test2 - eager load in DQL query
        $query = $this->_em->createQuery('SELECT p,t FROM Doctrine\Tests\ORM\Functional\Phrase p JOIN p.type t');
        $res   = $query->getResult();
        $this->assertEquals(1, count($res));
        $this->assertInstanceOf(PhraseType::class, $res[0]->getType());
        $this->assertInstanceOf(PersistentCollection::class, $res[0]->getType()->getPhrases());
        $this->assertFalse($res[0]->getType()->getPhrases()->isInitialized());

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);

        $this->_em->clear();

        // test2 - eager load in DQL query with double-join back and forth
        $query = $this->_em->createQuery('SELECT p,t,pp FROM Doctrine\Tests\ORM\Functional\Phrase p JOIN p.type t JOIN t.phrases pp');
        $res   = $query->getResult();
        $this->assertEquals(1, count($res));
        $this->assertInstanceOf(PhraseType::class, $res[0]->getType());
        $this->assertInstanceOf(PersistentCollection::class, $res[0]->getType()->getPhrases());
        $this->assertTrue($res[0]->getType()->getPhrases()->isInitialized());

        $this->_em->clear();

        // test3 - lazy-loading one-to-many after find()
        $phrase3     = $this->_em->find(Phrase::class, $phrase->getId());
        $definitions = $phrase3->getDefinitions();
        $this->assertInstanceOf(PersistentCollection::class, $definitions);
        $this->assertInstanceOf(Definition::class, $definitions[0]);

        $this->_em->clear();

        // test4 - lazy-loading after DQL query
        $query       = $this->_em->createQuery('SELECT p FROM Doctrine\Tests\ORM\Functional\Phrase p');
        $res         = $query->getResult();
        $definitions = $res[0]->getDefinitions();

        $this->assertEquals(1, count($res));

        $this->assertInstanceOf(Definition::class, $definitions[0]);
        $this->assertEquals(2, $definitions->count());

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

        $this->assertInstanceOf(Type::class, $types[0]);

        $this->_em->clear();

        IterableTester::assertResultsAreTheSame($query);
    }
}

/**
 * @Entity
 * @Table(name="lemma")
 */
class Lemma
{
    public const CLASS_NAME = self::class;

    /**
     * @var int
     * @Id
     * @Column(type="integer", name="lemma_id")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", name="lemma_name", unique=true, length=255)
     */
    private $lemma;

    /**
     * @var kateglo\application\utilities\collections\ArrayCollection
     * @ManyToMany(targetEntity="Type", mappedBy="lemmas", cascade={"persist"})
     */
    private $types;

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

    /**
     * @param kateglo\application\models\Type $type
     */
    public function addType(Type $type): void
    {
        if (! $this->types->contains($type)) {
            $this->types[] = $type;
            $type->addLemma($this);
        }
    }

    /**
     * @param kateglo\application\models\Type $type
     */
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

/**
 * @Entity
 * @Table(name="type")
 */
class Type
{
    public const CLASS_NAME = self::class;

    /**
     * @var int
     * @Id
     * @Column(type="integer", name="type_id")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", name="type_name", unique=true)
     */
    private $type;

    /**
     * @var string
     * @Column(type="string", name="type_abbreviation", unique=true)
     */
    private $abbreviation;

    /**
     * @var kateglo\application\helpers\collections\ArrayCollection
     * @ManyToMany(targetEntity="Lemma")
     * @JoinTable(name="lemma_type",
     *      joinColumns={@JoinColumn(name="type_id", referencedColumnName="type_id")},
     *      inverseJoinColumns={@JoinColumn(name="lemma_id", referencedColumnName="lemma_id")}
     * )
     */
    private $lemmas;

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

    /**
     * @param kateglo\application\models\Lemma $lemma
     */
    public function addLemma(Lemma $lemma): void
    {
        if (! $this->lemmas->contains($lemma)) {
            $this->lemmas[] = $lemma;
            $lemma->addType($this);
        }
    }

    /**
     * @param kateglo\application\models\Lemma $lemma
     */
    public function removeLEmma(Lemma $lemma): void
    {
        $removed = $this->lemmas->removeElement($lemma);
        if ($removed !== null) {
            $removed->removeType($this);
        }
    }

    public function getCategories(): kateglo\application\helpers\collections\ArrayCollection
    {
        return $this->categories;
    }
}


/**
 * @Entity
 * @Table(name="phrase")
 */
class Phrase
{
    public const CLASS_NAME = self::class;

    /**
     * @var int
     * @Id
     * @Column(type="integer", name="phrase_id")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", name="phrase_name", unique=true, length=255)
     */
    private $phrase;

    /**
     * @var PhraseType
     * @ManyToOne(targetEntity="PhraseType")
     * @JoinColumn(name="phrase_type_id", referencedColumnName="phrase_type_id")
     */
    private $type;

    /**
     * @psalm-var Collection<int, Definition>
     * @OneToMany(targetEntity="Definition", mappedBy="phrase", cascade={"persist"})
     */
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

/**
 * @Entity
 * @Table(name="phrase_type")
 */
class PhraseType
{
    public const CLASS_NAME = self::class;

    /**
     * @var int
     * @Id
     * @Column(type="integer", name="phrase_type_id")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", name="phrase_type_name", unique=true)
     */
    private $type;

    /**
     * @var string
     * @Column(type="string", name="phrase_type_abbreviation", unique=true)
     */
    private $abbreviation;

    /**
     * @psalm-var Collection<int, Phrase>
     * @OneToMany(targetEntity="Phrase", mappedBy="type")
     */
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

/**
 * @Entity
 * @Table(name="definition")
 */
class Definition
{
    public const CLASS_NAME = self::class;

    /**
     * @var int
     * @Id
     * @Column(type="integer", name="definition_id")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var Phrase
     * @ManyToOne(targetEntity="Phrase")
     * @JoinColumn(name="definition_phrase_id", referencedColumnName="phrase_id")
     */
    private $phrase;

    /**
     * @var string
     * @Column(type="text", name="definition_text")
     */
    private $definition;

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
            assert($phrase instanceof kateglo\application\models\Phrase);
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
