<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\Models\ECommerce\ECommerceCategory;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Tests a bidirectional one-to-one association mapping (without inheritance).
 */
class OneToManySelfReferentialAssociationTest extends OrmFunctionalTestCase
{
    private $parent;
    private $firstChild;
    private $secondChild;

    protected function setUp() : void
    {
        $this->useModelSet('ecommerce');
        parent::setUp();
        $this->parent = new ECommerceCategory();
        $this->parent->setName('Programming languages books');
        $this->firstChild = new ECommerceCategory();
        $this->firstChild->setName('Java books');
        $this->secondChild = new ECommerceCategory();
        $this->secondChild->setName('Php books');
    }

    public function testSavesAOneToManyAssociationWithCascadeSaveSet() {
        $this->parent->addChild($this->firstChild);
        $this->parent->addChild($this->secondChild);
        $this->_em->persist($this->parent);

        $this->_em->flush();

        $this->assertForeignKeyIs($this->parent->getId(), $this->firstChild);
        $this->assertForeignKeyIs($this->parent->getId(), $this->secondChild);
    }

    public function testSavesAnEmptyCollection()
    {
        $this->_em->persist($this->parent);
        $this->_em->flush();

        $this->assertEquals(0, count($this->parent->getChildren()));
    }

    public function testDoesNotSaveAnInverseSideSet() {
        $this->parent->brokenAddChild($this->firstChild);
        $this->_em->persist($this->parent);
        $this->_em->flush();

        $this->assertForeignKeyIs(null, $this->firstChild);
    }

    public function testRemovesOneToManyAssociation()
    {
        $this->parent->addChild($this->firstChild);
        $this->parent->addChild($this->secondChild);
        $this->_em->persist($this->parent);

        $this->parent->removeChild($this->firstChild);
        $this->_em->flush();

        $this->assertForeignKeyIs(null, $this->firstChild);
        $this->assertForeignKeyIs($this->parent->getId(), $this->secondChild);
    }

    public function testEagerLoadsOneToManyAssociation()
    {
        $this->_createFixture();

        $query = $this->_em->createQuery('select c1, c2 from Doctrine\Tests\Models\ECommerce\ECommerceCategory c1 join c1.children c2');
        $result = $query->getResult();
        $this->assertEquals(1, count($result));
        $parent = $result[0];
        $children = $parent->getChildren();

        $this->assertInstanceOf(ECommerceCategory::class, $children[0]);
        $this->assertSame($parent, $children[0]->getParent());
        $this->assertEquals(' books', strstr($children[0]->getName(), ' books'));
        $this->assertInstanceOf(ECommerceCategory::class, $children[1]);
        $this->assertSame($parent, $children[1]->getParent());
        $this->assertEquals(' books', strstr($children[1]->getName(), ' books'));
    }

    public function testLazyLoadsOneToManyAssociation()
    {
        $this->_createFixture();
        $metadata = $this->_em->getClassMetadata(ECommerceCategory::class);
        $metadata->associationMappings['children']['fetch'] = ClassMetadata::FETCH_LAZY;

        $query = $this->_em->createQuery('select c from Doctrine\Tests\Models\ECommerce\ECommerceCategory c order by c.id asc');
        $result = $query->getResult();
        $parent = $result[0];
        $children = $parent->getChildren();

        $this->assertInstanceOf(ECommerceCategory::class, $children[0]);
        $this->assertSame($parent, $children[0]->getParent());
        $this->assertEquals(' books', strstr($children[0]->getName(), ' books'));
        $this->assertInstanceOf(ECommerceCategory::class, $children[1]);
        $this->assertSame($parent, $children[1]->getParent());
        $this->assertEquals(' books', strstr($children[1]->getName(), ' books'));
    }

    private function _createFixture()
    {
        $this->parent->addChild($this->firstChild);
        $this->parent->addChild($this->secondChild);
        $this->_em->persist($this->parent);

        $this->_em->flush();
        $this->_em->clear();
    }

    public function assertForeignKeyIs($value, ECommerceCategory $child) {
        $foreignKey = $this->_em->getConnection()->executeQuery('SELECT parent_id FROM ecommerce_categories WHERE id=?', [$child->getId()]
        )->fetchOne();
        $this->assertEquals($value, $foreignKey);
    }
}
