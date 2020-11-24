<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\Tests\Models\ECommerce\ECommerceCustomer;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Tests a self referential one-to-one association mapping (without inheritance).
 * Relation is defined as the mentor that a customer choose. The mentor could
 * help only one other customer, while a customer can choose only one mentor
 * for receiving support.
 * Inverse side is not present.
 */
class OneToOneSelfReferentialAssociationTest extends OrmFunctionalTestCase
{
    private $customer;
    private $mentor;

    protected function setUp() : void
    {
        $this->useModelSet('ecommerce');
        parent::setUp();
        $this->customer = new ECommerceCustomer();
        $this->customer->setName('Anakin Skywalker');
        $this->mentor = new ECommerceCustomer();
        $this->mentor->setName('Obi-wan Kenobi');
    }

    public function testSavesAOneToOneAssociationWithCascadeSaveSet() {
        $this->customer->setMentor($this->mentor);
        $this->_em->persist($this->customer);
        $this->_em->flush();

        $this->assertForeignKeyIs($this->mentor->getId());
    }

    public function testRemovesOneToOneAssociation()
    {
        $this->customer->setMentor($this->mentor);
        $this->_em->persist($this->customer);
        $this->customer->removeMentor();

        $this->_em->flush();

        $this->assertForeignKeyIs(null);
    }

    public function testFind()
    {
        $id = $this->_createFixture();

        $customer = $this->_em->find(ECommerceCustomer::class, $id);
        $this->assertNotInstanceOf(Proxy::class, $customer->getMentor());
    }

    public function testEagerLoadsAssociation()
    {
        $this->_createFixture();

        $query = $this->_em->createQuery('select c, m from Doctrine\Tests\Models\ECommerce\ECommerceCustomer c left join c.mentor m order by c.id asc');
        $result = $query->getResult();
        $customer = $result[0];
        $this->assertLoadingOfAssociation($customer);
    }

    /**
     * @group mine
     * @return unknown_type
     */
    public function testLazyLoadsAssociation()
    {
        $this->_createFixture();

        $metadata = $this->_em->getClassMetadata(ECommerceCustomer::class);
        $metadata->associationMappings['mentor']['fetch'] = ClassMetadata::FETCH_LAZY;

        $query = $this->_em->createQuery("select c from Doctrine\Tests\Models\ECommerce\ECommerceCustomer c where c.name='Luke Skywalker'");
        $result = $query->getResult();
        $customer = $result[0];
        $this->assertLoadingOfAssociation($customer);
    }

    public function testMultiSelfReference()
    {
        try {
            $this->_schemaTool->createSchema(
                [
                $this->_em->getClassMetadata(MultiSelfReference::class)
                ]
            );
        } catch (\Exception $e) {
            // Swallow all exceptions. We do not test the schema tool here.
        }

        $entity1 = new MultiSelfReference();
        $this->_em->persist($entity1);
        $entity1->setOther1($entity2 = new MultiSelfReference);
        $entity1->setOther2($entity3 = new MultiSelfReference);
        $this->_em->flush();

        $this->_em->clear();

        $entity2 = $this->_em->find(get_class($entity1), $entity1->getId());

        $this->assertInstanceOf(MultiSelfReference::class, $entity2->getOther1());
        $this->assertInstanceOf(MultiSelfReference::class, $entity2->getOther2());
        $this->assertNull($entity2->getOther1()->getOther1());
        $this->assertNull($entity2->getOther1()->getOther2());
        $this->assertNull($entity2->getOther2()->getOther1());
        $this->assertNull($entity2->getOther2()->getOther2());
    }

    public function assertLoadingOfAssociation($customer)
    {
        $this->assertInstanceOf(ECommerceCustomer::class, $customer->getMentor());
        $this->assertEquals('Obi-wan Kenobi', $customer->getMentor()->getName());
    }

    public function assertForeignKeyIs($value) {
        $foreignKey = $this->_em->getConnection()->executeQuery('SELECT mentor_id FROM ecommerce_customers WHERE id=?', [$this->customer->getId()]
        )->fetchOne();
        $this->assertEquals($value, $foreignKey);
    }

    private function _createFixture()
    {
        $customer = new ECommerceCustomer;
        $customer->setName('Luke Skywalker');
        $mentor = new ECommerceCustomer;
        $mentor->setName('Obi-wan Kenobi');
        $customer->setMentor($mentor);

        $this->_em->persist($customer);

        $this->_em->flush();
        $this->_em->clear();

        return $customer->getId();
    }
}

/**
 * @Entity
 */
class MultiSelfReference {
    /** @Id @GeneratedValue(strategy="AUTO") @Column(type="integer") */
    private $id;
    /**
     * @OneToOne(targetEntity="MultiSelfReference", cascade={"persist"})
     * @JoinColumn(name="other1", referencedColumnName="id")
     */
    private $other1;
    /**
     * @OneToOne(targetEntity="MultiSelfReference", cascade={"persist"})
     * @JoinColumn(name="other2", referencedColumnName="id")
     */
    private $other2;

    public function getId() {return $this->id;}
    public function setOther1($other1) {$this->other1 = $other1;}
    public function getOther1() {return $this->other1;}
    public function setOther2($other2) {$this->other2 = $other2;}
    public function getOther2() {return $this->other2;}
}
