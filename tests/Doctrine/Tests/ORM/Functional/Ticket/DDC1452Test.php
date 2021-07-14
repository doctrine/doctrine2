<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-1452
 */
class DDC1452Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('cms');
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1452EntityA::class),
                    $this->_em->getClassMetadata(DDC1452EntityB::class),
                ]
            );
        } catch (Exception $ignored) {
        }
    }

    public function testIssue(): void
    {
        $a1        = new DDC1452EntityA();
        $a1->title = 'foo';

        $a2        = new DDC1452EntityA();
        $a2->title = 'bar';

        $b              = new DDC1452EntityB();
        $b->entityAFrom = $a1;
        $b->entityATo   = $a2;

        $this->_em->persist($a1);
        $this->_em->persist($a2);
        $this->_em->persist($b);
        $this->_em->flush();
        $this->_em->clear();

        $dql     = 'SELECT a, b, ba FROM ' . __NAMESPACE__ . '\DDC1452EntityA AS a LEFT JOIN a.entitiesB AS b LEFT JOIN b.entityATo AS ba';
        $results = $this->_em->createQuery($dql)->setMaxResults(1)->getResult();

        $this->assertSame($results[0], $results[0]->entitiesB[0]->entityAFrom);
        $this->assertNotInstanceOf(Proxy::class, $results[0]->entitiesB[0]->entityATo);
        $this->assertInstanceOf(Collection::class, $results[0]->entitiesB[0]->entityATo->getEntitiesB());
    }

    public function testFetchJoinOneToOneFromInverse(): void
    {
        $address          = new CmsAddress();
        $address->city    = 'Bonn';
        $address->country = 'Germany';
        $address->street  = 'Somestreet';
        $address->zip     = 12345;

        $user           = new CmsUser();
        $user->name     = 'beberlei';
        $user->username = 'beberlei';
        $user->status   = 'active';
        $user->address  = $address;
        $address->user  = $user;

        $this->_em->persist($address);
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $dql  = 'SELECT a, u FROM Doctrine\Tests\Models\CMS\CmsAddress a INNER JOIN a.user u';
        $data = $this->_em->createQuery($dql)->getResult();
        $this->_em->clear();

        $this->assertNotInstanceOf(Proxy::class, $data[0]->user);

        $dql  = 'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.address a';
        $data = $this->_em->createQuery($dql)->getResult();

        $this->assertNotInstanceOf(Proxy::class, $data[0]->address);
    }
}

/**
 * @Entity
 */
class DDC1452EntityA
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column
     */
    public $title;

    /**
     * @psalm-var Collection<int, DDC1452EntityB>
     * @OneToMany(targetEntity="DDC1452EntityB", mappedBy="entityAFrom")
     */
    public $entitiesB;

    public function __construct()
    {
        $this->entitiesB = new ArrayCollection();
    }

    /**
     * @psalm-return Collection<int, DDC1452EntityB>
     */
    public function getEntitiesB(): Collection
    {
        return $this->entitiesB;
    }
}

/**
 * @Entity
 */
class DDC1452EntityB
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC1452EntityA
     * @ManyToOne(targetEntity="DDC1452EntityA", inversedBy="entitiesB")
     */
    public $entityAFrom;
    /**
     * @var DDC1452EntityA
     * @ManyToOne(targetEntity="DDC1452EntityA")
     */
    public $entityATo;
}
