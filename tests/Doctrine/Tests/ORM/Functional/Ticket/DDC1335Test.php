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
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function count;

/**
 * @group DDC-1335
 */
class DDC1335Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1335User::class),
                    $this->_em->getClassMetadata(DDC1335Phone::class),
                ]
            );
            $this->loadFixture();
        } catch (Exception $e) {
        }
    }

    public function testDql(): void
    {
        $dql    = 'SELECT u FROM ' . __NAMESPACE__ . '\DDC1335User u INDEX BY u.id';
        $query  = $this->_em->createQuery($dql);
        $result = $query->getResult();

        $this->assertEquals(count($result), 3);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertArrayHasKey(3, $result);

        $dql    = 'SELECT u, p FROM ' . __NAMESPACE__ . '\DDC1335User u INDEX BY u.email INNER JOIN u.phones p INDEX BY p.id';
        $query  = $this->_em->createQuery($dql);
        $result = $query->getResult();

        $this->assertEquals(count($result), 3);
        $this->assertArrayHasKey('foo@foo.com', $result);
        $this->assertArrayHasKey('bar@bar.com', $result);
        $this->assertArrayHasKey('foobar@foobar.com', $result);

        $this->assertEquals(count($result['foo@foo.com']->phones), 3);
        $this->assertEquals(count($result['bar@bar.com']->phones), 3);
        $this->assertEquals(count($result['foobar@foobar.com']->phones), 3);

        $foo    = $result['foo@foo.com']->phones->toArray();
        $bar    = $result['bar@bar.com']->phones->toArray();
        $foobar = $result['foobar@foobar.com']->phones->toArray();

        $this->assertArrayHasKey(1, $foo);
        $this->assertArrayHasKey(2, $foo);
        $this->assertArrayHasKey(3, $foo);

        $this->assertArrayHasKey(4, $bar);
        $this->assertArrayHasKey(5, $bar);
        $this->assertArrayHasKey(6, $bar);

        $this->assertArrayHasKey(7, $foobar);
        $this->assertArrayHasKey(8, $foobar);
        $this->assertArrayHasKey(9, $foobar);
    }

    public function testTicket(): void
    {
        $builder = $this->_em->createQueryBuilder();
        $builder->select('u')->from(DDC1335User::class, 'u', 'u.id');

        $dql    = $builder->getQuery()->getDQL();
        $result = $builder->getQuery()->getResult();

        $this->assertEquals(count($result), 3);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertArrayHasKey(3, $result);
        $this->assertEquals('SELECT u FROM ' . __NAMESPACE__ . '\DDC1335User u INDEX BY u.id', $dql);
    }

    public function testIndexByUnique(): void
    {
        $builder = $this->_em->createQueryBuilder();
        $builder->select('u')->from(DDC1335User::class, 'u', 'u.email');

        $dql    = $builder->getQuery()->getDQL();
        $result = $builder->getQuery()->getResult();

        $this->assertEquals(count($result), 3);
        $this->assertArrayHasKey('foo@foo.com', $result);
        $this->assertArrayHasKey('bar@bar.com', $result);
        $this->assertArrayHasKey('foobar@foobar.com', $result);
        $this->assertEquals('SELECT u FROM ' . __NAMESPACE__ . '\DDC1335User u INDEX BY u.email', $dql);
    }

    public function testIndexWithJoin(): void
    {
        $builder = $this->_em->createQueryBuilder();
        $builder->select('u', 'p')
                ->from(DDC1335User::class, 'u', 'u.email')
                ->join('u.phones', 'p', null, null, 'p.id');

        $dql    = $builder->getQuery()->getDQL();
        $result = $builder->getQuery()->getResult();

        $this->assertEquals(count($result), 3);
        $this->assertArrayHasKey('foo@foo.com', $result);
        $this->assertArrayHasKey('bar@bar.com', $result);
        $this->assertArrayHasKey('foobar@foobar.com', $result);

        $this->assertEquals(count($result['foo@foo.com']->phones), 3);
        $this->assertEquals(count($result['bar@bar.com']->phones), 3);
        $this->assertEquals(count($result['foobar@foobar.com']->phones), 3);

        $this->assertArrayHasKey(1, $result['foo@foo.com']->phones->toArray());
        $this->assertArrayHasKey(2, $result['foo@foo.com']->phones->toArray());
        $this->assertArrayHasKey(3, $result['foo@foo.com']->phones->toArray());

        $this->assertArrayHasKey(4, $result['bar@bar.com']->phones->toArray());
        $this->assertArrayHasKey(5, $result['bar@bar.com']->phones->toArray());
        $this->assertArrayHasKey(6, $result['bar@bar.com']->phones->toArray());

        $this->assertArrayHasKey(7, $result['foobar@foobar.com']->phones->toArray());
        $this->assertArrayHasKey(8, $result['foobar@foobar.com']->phones->toArray());
        $this->assertArrayHasKey(9, $result['foobar@foobar.com']->phones->toArray());

        $this->assertEquals('SELECT u, p FROM ' . __NAMESPACE__ . '\DDC1335User u INDEX BY u.email INNER JOIN u.phones p INDEX BY p.id', $dql);
    }

    private function loadFixture(): void
    {
        $p1 = ['11 xxxx-xxxx', '11 yyyy-yyyy', '11 zzzz-zzzz'];
        $p2 = ['22 xxxx-xxxx', '22 yyyy-yyyy', '22 zzzz-zzzz'];
        $p3 = ['33 xxxx-xxxx', '33 yyyy-yyyy', '33 zzzz-zzzz'];

        $u1 = new DDC1335User('foo@foo.com', 'Foo', $p1);
        $u2 = new DDC1335User('bar@bar.com', 'Bar', $p2);
        $u3 = new DDC1335User('foobar@foobar.com', 'Foo Bar', $p3);

        $this->_em->persist($u1);
        $this->_em->persist($u2);
        $this->_em->persist($u3);
        $this->_em->flush();
        $this->_em->clear();
    }
}

/**
 * @Entity
 */
class DDC1335User
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string", unique=true)
     */
    public $email;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @psalm-var Collection<int, DDC1335Phone>
     * @OneToMany(targetEntity="DDC1335Phone", mappedBy="user", cascade={"persist", "remove"})
     */
    public $phones;

    public function __construct($email, $name, array $numbers = [])
    {
        $this->name   = $name;
        $this->email  = $email;
        $this->phones = new ArrayCollection();

        foreach ($numbers as $number) {
            $this->phones->add(new DDC1335Phone($this, $number));
        }
    }
}

/**
 * @Entity
 */
class DDC1335Phone
{
    /**
     * @var int
     * @Id
     * @Column(name="id", type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(name="numericalValue", type="string", nullable = false)
     */
    public $numericalValue;

    /**
     * @var DDC1335User
     * @ManyToOne(targetEntity="DDC1335User", inversedBy="phones")
     * @JoinColumn(name="user_id", referencedColumnName="id", nullable = false)
     */
    public $user;

    public function __construct($user, $number)
    {
        $this->user           = $user;
        $this->numericalValue = $number;
    }
}
