<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\OrmFunctionalTestCase;

class GH8043Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH8043FirstEntity::class,
            GH8043SecondEntity::class,
            GH8043CompositeEntity::class,
            GH8043ChildEntity::class,
        ]);
    }

    public function testCompositeForeignEntityIdentity(): void
    {
        $this->assertSQLEquals(
            'SELECT g0_.composite_first_id AS sclr_0 FROM GH8043ChildEntity g0_',
            $this->_em->createQuery("SELECT IDENTITY(e.composite, 'first') FROM " . GH8043ChildEntity::class . ' e')->getSQL()
        );
    }
}

/** @Entity */
class GH8043FirstEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    private $id;
}

/** @Entity */
class GH8043SecondEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    private $id;
}

/** @Entity */
class GH8043CompositeEntity
{
    /**
     * @var GH8043FirstEntity
     * @Id
     * @ManyToOne(targetEntity="GH8043FirstEntity")
     */
    private $first;

    /**
     * @var GH8043SecondEntity
     * @Id
     * @ManyToOne(targetEntity="GH8043SecondEntity")
     */
    private $second;
}

/** @Entity */
class GH8043ChildEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    private $id;

    /**
     * @var GH8043CompositeEntity
     * @ManyToOne(targetEntity="GH8043CompositeEntity")
     * @JoinColumns({
     *  @JoinColumn(name="composite_first_id", referencedColumnName="first_id"),
     *  @JoinColumn(name="composite_second_id", referencedColumnName="second_id"),
     * })
     */
    private $composite;
}
