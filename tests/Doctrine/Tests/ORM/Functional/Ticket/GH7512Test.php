<?php declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\OrmFunctionalTestCase;

class GH7512Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH7512EntityA::class,
            GH7512EntityB::class,
            GH7512EntityC::class
        ]);

        $this->_em->persist(new GH7512EntityA());
        $this->_em->persist(new GH7512EntityC());
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testFindEntityByAssociationPropertyJoinedChildWithClearMetadata(): void
    {
        // unset metadata for entity B as though it hasn't been touched yet in application lifecycle.
        $this->_em->getMetadataFactory()->setMetadataFor(GH7512EntityB::class, null);
        $result = $this->_em->getRepository(GH7512EntityC::class)->findBy([
            'entityA' => new GH7512EntityB()
        ]);
        $this->assertEmpty($result);
    }
}

/**
 * @Entity()
 * @InheritanceType("JOINED")
 * @DiscriminatorMap({
 *     "entitya"=Doctrine\Tests\ORM\Functional\Ticket\GH7512EntityA::class,
 *     "entityB"=Doctrine\Tests\ORM\Functional\Ticket\GH7512EntityB::class
 * })
 */
class GH7512EntityA
{
    /**
     * @Column(type="integer")
     * @Id()
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @OneToMany(targetEntity="Doctrine\Tests\ORM\Functional\Ticket\GH7512EntityC", mappedBy="entityA")
     */
    private $entityCs;
}

/**
 * @Entity()
 */
class GH7512EntityB extends GH7512EntityA
{

}

/**
 * @Entity()
 */
class GH7512EntityC
{
    /**
     * @Column(type="integer")
     * @Id()
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ManyToOne(targetEntity="Doctrine\Tests\ORM\Functional\Ticket\GH7512EntityA", inversedBy="entityCs")
     */
    private $entityA;
}
