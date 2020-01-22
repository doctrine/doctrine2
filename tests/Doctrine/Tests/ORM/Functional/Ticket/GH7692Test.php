<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\EntityNotFoundException;
use Doctrine\Tests\OrmFunctionalTestCase;

class GH7692Test extends OrmFunctionalTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH7692AddressBook::class,
            GH7692Project::class,
            GH7692Contact::class,
        ]);
    }

    public function testWithoutEagerLoading(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage("Entity of type 'Doctrine\Tests\ORM\Functional\Ticket\GH7692Contact' for IDs category(999), number(999) was not found");

        // Create a row that references missing rows
        $this->_em->getConnection()->insert('address_book', [
            // This composite foreign key doesn't exist
            'contact_category' => 999,
            'contact_number' => 999,
        ]);

        $books = $this->_em->createQuery('SELECT a FROM Doctrine\Tests\ORM\Functional\Ticket\GH7692AddressBook a')->getResult();
        $this->assertCount(1, $books);
        $books[0]->contact->name; // access the property on the proxy to trigger the exception
    }

    public function testWithEagerLoading(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage("Entity of type 'Doctrine\Tests\ORM\Functional\Ticket\GH7692Contact' for IDs category(999), number(999) was not found");

        // Create a row that references missing rows
        $this->_em->getConnection()->insert('project', [
            // This composite foreign key doesn't exist
            'contact_category' => 999,
            'contact_number' => 999,
        ]);

        $this->_em->createQuery('SELECT p FROM Doctrine\Tests\ORM\Functional\Ticket\GH7692Project p')->getResult();
    }
}

/**
 * @Entity
 * @Table(name="address_book")
 */
class GH7692AddressBook
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * WITHOUT EAGER
     *
     * @ManyToOne(targetEntity="Doctrine\Tests\ORM\Functional\Ticket\GH7692Contact")
     * @JoinColumns({
     *     @JoinColumn(name="contact_category", referencedColumnName="category"),
     *     @JoinColumn(name="contact_number", referencedColumnName="number")
     * })
     */
    public $contact;
}

/**
 * @Entity
 * @Table(name="project")
 */
class GH7692Project
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * WITH EAGER
     *
     * @ManyToOne(targetEntity="Doctrine\Tests\ORM\Functional\Ticket\GH7692Contact", fetch="EAGER")
     * @JoinColumns({
     *     @JoinColumn(name="contact_category", referencedColumnName="category"),
     *     @JoinColumn(name="contact_number", referencedColumnName="number")
     * })
     */
    public $contact;
}

/**
 * @Entity
 */
class GH7692Contact
{
    /**
     * @Id
     * @Column(type="integer")
     */
    public $category;

    /**
     * @Id
     * @Column(type="integer")
     */
    public $number;

    /**
     * @Column(type="string")
     */
    public $name;
}
