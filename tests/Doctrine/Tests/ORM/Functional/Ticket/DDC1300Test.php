<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;

/**
 * @group DDC-1300
 */
class DDC1300Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1300Foo::class),
                $this->_em->getClassMetadata(DDC1300FooLocale::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $foo               = new DDC1300Foo();
        $foo->fooReference = 'foo';

        $this->_em->persist($foo);
        $this->_em->flush();

        $locale         = new DDC1300FooLocale();
        $locale->foo    = $foo;
        $locale->locale = 'en';
        $locale->title  = 'blub';

        $this->_em->persist($locale);
        $this->_em->flush();

        $query  = $this->_em->createQuery('SELECT f, fl FROM Doctrine\Tests\ORM\Functional\Ticket\DDC1300Foo f JOIN f.fooLocaleRefFoo fl');
        $result =  $query->getResult();

        $this->assertEquals(1, count($result));
    }
}

/**
 * @Entity
 */
class DDC1300Foo
{
    /**
     * @var int fooID
     * @Column(name="fooID", type="integer", nullable=false)
     * @GeneratedValue(strategy="AUTO")
     * @Id
     */
    public $fooID = null;

    /**
     * @var string fooReference
     * @Column(name="fooReference", type="string", nullable=true, length=45)
     */
    public $fooReference = null;

    /**
     * @psalm-var Collection<int, DDC1300FooLocale>
     * @OneToMany(targetEntity="DDC1300FooLocale", mappedBy="foo",
     * cascade={"persist"})
     */
    public $fooLocaleRefFoo = null;

    /**
     * @param mixed[]|null $options
     */
    public function __construct(?array $options = null)
    {
        $this->fooLocaleRefFoo = new ArrayCollection();
    }
}

/**
 * @Entity
 */
class DDC1300FooLocale
{
    /**
     * @var DDC1300Foo
     * @ManyToOne(targetEntity="DDC1300Foo")
     * @JoinColumn(name="fooID", referencedColumnName="fooID")
     * @Id
     */
    public $foo = null;

    /**
     * @var string locale
     * @Column(name="locale", type="string", nullable=false, length=5)
     * @Id
     */
    public $locale = null;

    /**
     * @var string title
     * @Column(name="title", type="string", nullable=true, length=150)
     */
    public $title = null;
}
