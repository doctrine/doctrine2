<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\OrmFunctionalTestCase;

class GH8415Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema(
            [
                GH8415BaseClass::class,
                GH8415MediumSuperclass::class,
                GH8415LeafClass::class,
                GH8415AssociationTarget::class,
            ]
        );
    }

    public function testAssociationIsBasedOnBaseClass(): void
    {
        $target            = new GH8415AssociationTarget();
        $leaf              = new GH8415LeafClass();
        $leaf->baseField   = 'base';
        $leaf->mediumField = 'medium';
        $leaf->leafField   = 'leaf';
        $leaf->target      = $target;

        $this->_em->persist($target);
        $this->_em->persist($leaf);
        $this->_em->flush();
        $this->_em->clear();

        $query  = $this->_em->createQuery('SELECT leaf FROM Doctrine\Tests\ORM\Functional\Ticket\GH8415LeafClass leaf JOIN leaf.target t');
        $result = $query->getOneOrNullResult();

        $this->assertInstanceOf(GH8415LeafClass::class, $result);
        $this->assertSame('base', $result->baseField);
        $this->assertSame('medium', $result->mediumField);
        $this->assertSame('leaf', $result->leafField);
    }
}

/**
 * @Entity
 */
class GH8415AssociationTarget
{
    /**
     * @Column(type="integer")
     * @Id
     * @GeneratedValue
     * @var int
     */
    public $id;
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discriminator", type="string")
 * @DiscriminatorMap({"1" = "Doctrine\Tests\ORM\Functional\Ticket\GH8415LeafClass"})
 */
abstract class GH8415BaseClass
{
    /**
     * @Column(type="integer")
     * @Id
     * @GeneratedValue
     * @var int
     */
    public $id;

    /**
     * @ManyToOne(targetEntity="GH8415AssociationTarget")
     * @var GH8415AssociationTarget
     */
    public $target;

    /**
     * @Column(type="string")
     * @var string
     */
    public $baseField;
}

/**
 * @MappedSuperclass
 */
class GH8415MediumSuperclass extends GH8415BaseClass
{
    /**
     * @Column(type="string")
     * @var string
     */
    public $mediumField;
}

/**
 * @Entity
 */
class GH8415LeafClass extends GH8415MediumSuperclass
{
    /**
     * @Column(type="string")
     * @var string
     */
    public $leafField;
}
