<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

/**
 * Functional tests for the Single Table Inheritance mapping strategy.
 */
class DDC69Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(Lemma::class),
            $this->_em->getClassMetadata(Relation::class),
            $this->_em->getClassMetadata(RelationType::class),
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->_schemaTool->dropSchema([
            $this->_em->getClassMetadata(Lemma::class),
            $this->_em->getClassMetadata(Relation::class),
            $this->_em->getClassMetadata(RelationType::class),
        ]);
    }

    public function testIssue(): void
    {
        //setup
        $lemma1 = new Lemma();
        $lemma1->setLemma('foo');

        $lemma2 = new Lemma();
        $lemma2->setLemma('bar');

        $lemma3 = new Lemma();
        $lemma3->setLemma('batz');

        $lemma4 = new Lemma();
        $lemma4->setLemma('bla');

        $type1 = new RelationType();
        $type1->setType('nonsense');
        $type1->setAbbreviation('non');

        $type2 = new RelationType();
        $type2->setType('quatsch');
        $type2->setAbbreviation('qu');

        $relation1 = new Relation();
        $relation1->setParent($lemma1);
        $relation1->setChild($lemma2);
        $relation1->setType($type1);

        $relation2 = new Relation();
        $relation2->setParent($lemma1);
        $relation2->setChild($lemma3);
        $relation2->setType($type1);

        $relation3 = new Relation();
        $relation3->setParent($lemma1);
        $relation3->setChild($lemma4);
        $relation3->setType($type2);

        $lemma1->addRelation($relation1);
        $lemma1->addRelation($relation2);
        $lemma1->addRelation($relation3);

        $this->_em->persist($type1);
        $this->_em->persist($type2);
        $this->_em->persist($lemma1);
        $this->_em->persist($lemma2);
        $this->_em->persist($lemma3);
        $this->_em->persist($lemma4);

        $this->_em->flush();
        $this->_em->clear();
        //end setup

        // test One To Many
        $query = $this->_em->createQuery("SELECT l FROM Doctrine\Tests\ORM\Functional\Ticket\Lemma l Where l.lemma = 'foo'");
        $res   = $query->getResult();
        $lemma = $res[0];

        $this->assertEquals('foo', $lemma->getLemma());
        $this->assertInstanceOf(Lemma::class, $lemma);
        $relations = $lemma->getRelations();

        foreach ($relations as $relation) {
            $this->assertInstanceOf(Relation::class, $relation);
            $this->assertTrue($relation->getType()->getType() !== '');
        }

        $this->_em->clear();
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
     * @var Collection<int, Relation>
     * @OneToMany(targetEntity="Relation", mappedBy="parent", cascade={"persist"})
     */
    private $relations;

    public function __construct()
    {
        $this->types     = new ArrayCollection();
        $this->relations = new ArrayCollection();
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

    public function addRelation(Relation $relation): void
    {
        $this->relations[] = $relation;
        $relation->setParent($this);
    }

    public function removeRelation(Relation $relation): void
    {
        $removed = $this->relations->removeElement($relation);
        assert($removed instanceof Relation);
        if ($removed !== null) {
            $removed->removeParent();
        }
    }

    /**
     * @psalm-return Collection<int, Relation>
     */
    public function getRelations(): Collection
    {
        return $this->relations;
    }
}

/**
 * @Entity
 * @Table(name="relation")
 */
class Relation
{
    public const CLASS_NAME = self::class;

    /**
     * @var int
     * @Id
     * @Column(type="integer", name="relation_id")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var Lemma|null
     * @ManyToOne(targetEntity="Lemma", inversedBy="relations")
     * @JoinColumn(name="relation_parent_id", referencedColumnName="lemma_id")
     */
    private $parent;

    /**
     * @var Lemma
     * @OneToOne(targetEntity="Lemma")
     * @JoinColumn(name="relation_child_id", referencedColumnName="lemma_id")
     */
    private $child;

    /**
     * @var RelationType
     * @ManyToOne(targetEntity="RelationType", inversedBy="relations")
     * @JoinColumn(name="relation_type_id", referencedColumnName="relation_type_id")
     */
    private $type;

    public function setParent(Lemma $parent): void
    {
        $this->parent = $parent;
    }

    public function getParent(): Phrase
    {
        return $this->parent;
    }

    public function removeParent(): void
    {
        if ($this->lemma !== null) {
            $lemma        = $this->parent;
            $this->parent = null;
            $lemma->removeRelation($this);
        }
    }

    public function setChild(Lemma $child): void
    {
        $this->child = $child;
    }

    public function getChild(): Lemma
    {
        return $this->child;
    }

    public function setType(RelationType $type): void
    {
        $this->type = $type;
    }

    public function getType(): RelationType
    {
        return $this->type;
    }

    public function removeType(): void
    {
        if ($this->type !== null) {
            $type       = $this->type;
            $this->type = null;
            $type->removeRelation($this);
        }
    }
}

/**
 * @Entity
 * @Table(name="relation_type")
 */
class RelationType
{
    public const CLASS_NAME = self::class;

    /**
     * @var int
     * @Id
     * @Column(type="integer", name="relation_type_id")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", name="relation_type_name", unique=true, length=255)
     */
    private $type;

    /**
     * @var string
     * @Column(type="string", name="relation_type_abbreviation", unique=true, length=255)
     */
    private $abbreviation;

    /**
     * @var Collection<int, Relation>
     * @OneToMany(targetEntity="Relation", mappedBy="type", cascade={"persist"})
     */
    private $relations;

    public function __construct()
    {
        $relations = new ArrayCollection();
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

    public function addRelation(Relation $relation): void
    {
        $this->relations[] = $relation;
        $relation->setType($this);
    }

    public function removeRelation(Relation $relation): void
    {
        $removed = $this->relations->removeElement($relation);
        assert($removed instanceof Relation);
        if ($removed !== null) {
            $removed->removeLemma();
        }
    }

    /**
     * @psalm-return Collection<int, Relation>
     */
    public function getRelations(): Collection
    {
        return $this->relations;
    }
}
