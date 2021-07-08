<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-6499
 */
class DDC6499OneToManyRelationshipTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(Application::class),
                $this->_em->getClassMetadata(Person::class),
                $this->_em->getClassMetadata(ApplicationPerson::class),
            ]
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->_schemaTool->dropSchema(
            [
                $this->_em->getClassMetadata(Application::class),
                $this->_em->getClassMetadata(Person::class),
                $this->_em->getClassMetadata(ApplicationPerson::class),
            ]
        );
    }

    /**
     * Test for the bug described in issue #6499.
     */
    public function testIssue(): void
    {
        if (! $this->_em->getConnection()->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            self::markTestSkipped('Platform does not support foreign keys.');
        }

        $person = new Person();
        $this->_em->persist($person);

        $application = new Application();
        $this->_em->persist($application);

        $applicationPerson = new ApplicationPerson($person, $application);

        $this->_em->persist($applicationPerson);
        $this->_em->flush();
        $this->_em->clear();

        $personFromDatabase      = $this->_em->find(Person::class, $person->id);
        $applicationFromDatabase = $this->_em->find(Application::class, $application->id);

        self::assertEquals($personFromDatabase->id, $person->id, 'Issue #6499 will result in a Integrity constraint violation before reaching this point.');
        self::assertFalse($personFromDatabase->getApplicationPeople()->isEmpty());

        self::assertEquals($applicationFromDatabase->id, $application->id, 'Issue #6499 will result in a Integrity constraint violation before reaching this point.');
        self::assertFalse($applicationFromDatabase->getApplicationPeople()->isEmpty());
    }
}

/**
 * @Entity()
 * @Table("ddc6499_application")
 */
class Application
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     * @var int
     */
    public $id;

    /**
     * @OneToMany(targetEntity=ApplicationPerson::class, mappedBy="application", orphanRemoval=true, cascade={"persist"})
     * @JoinColumn(nullable=false)
     * @var Collection
     */
    private $applicationPeople;

    public function __construct()
    {
        $this->applicationPeople = new ArrayCollection();
    }

    public function getApplicationPeople(): Collection
    {
        return $this->applicationPeople;
    }
}
/**
 * @Entity()
 * @Table("ddc6499_person")
 */
class Person
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     * @var int
     */
    public $id;

    /**
     * @OneToMany(targetEntity=ApplicationPerson::class, mappedBy="person", orphanRemoval=true, cascade={"persist"})
     * @JoinColumn(nullable=false)
     * @var Collection
     */
    private $applicationPeople;

    public function __construct()
    {
        $this->applicationPeople = new ArrayCollection();
    }

    public function getApplicationPeople(): Collection
    {
        return $this->applicationPeople;
    }
}

/**
 * @Entity()
 * @Table("ddc6499_application_person")
 */
class ApplicationPerson
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     * @var int
     */
    public $id;

    /**
     * @ManyToOne(targetEntity=Application::class, inversedBy="applicationPeople", cascade={"persist"})
     * @JoinColumn(nullable=false)
     * @var Application
     */
    public $application;

    /**
     * @ManyToOne(targetEntity=Person::class, inversedBy="applicationPeople", cascade={"persist"})
     * @JoinColumn(nullable=false)
     * @var Person
     */
    public $person;

    public function __construct(Person $person, Application $application)
    {
        $this->person      = $person;
        $this->application = $application;
    }
}
