<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function count;

/**
 * Functional tests for the Single Table Inheritance mapping strategy.
 */
class OrderedJoinedTableInheritanceCollectionTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(OJTICPet::class),
                    $this->_em->getClassMetadata(OJTICCat::class),
                    $this->_em->getClassMetadata(OJTICDog::class),
                ]
            );
        } catch (Exception $e) {
            // Swallow all exceptions. We do not test the schema tool here.
        }

        $dog       = new OJTICDog();
        $dog->name = 'Poofy';

        $dog1       = new OJTICDog();
        $dog1->name = 'Zampa';
        $dog2       = new OJTICDog();
        $dog2->name = 'Aari';

        $dog1->mother = $dog;
        $dog2->mother = $dog;

        $dog->children[] = $dog1;
        $dog->children[] = $dog2;

        $this->_em->persist($dog);
        $this->_em->persist($dog1);
        $this->_em->persist($dog2);
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testOrderdOneToManyCollection(): void
    {
        $poofy = $this->_em->createQuery("SELECT p FROM Doctrine\Tests\ORM\Functional\OJTICPet p WHERE p.name = 'Poofy'")->getSingleResult();

        $this->assertEquals('Aari', $poofy->children[0]->getName());
        $this->assertEquals('Zampa', $poofy->children[1]->getName());

        $this->_em->clear();

        $result = $this->_em->createQuery(
            "SELECT p, c FROM Doctrine\Tests\ORM\Functional\OJTICPet p JOIN p.children c WHERE p.name = 'Poofy'"
        )
                ->getResult();

        $this->assertEquals(1, count($result));
        $poofy = $result[0];

        $this->assertEquals('Aari', $poofy->children[0]->getName());
        $this->assertEquals('Zampa', $poofy->children[1]->getName());
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({
 *      "cat" = "OJTICCat",
 *      "dog" = "OJTICDog"})
 */
abstract class OJTICPet
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column
     */
    public $name;

    /**
     * @var OJTICPet
     * @ManyToOne(targetEntity="OJTICPet")
     */
    public $mother;

    /**
     * @psalm-var Collection<int, OJTICPet>
     * @OneToMany(targetEntity="OJTICPet", mappedBy="mother")
     * @OrderBy({"name" = "ASC"})
     */
    public $children;

    /**
     * @psalm-var Collection<int, OJTICPet>
     * @ManyToMany(targetEntity="OJTICPet")
     * @JoinTable(name="OTJIC_Pet_Friends",
     *     joinColumns={@JoinColumn(name="pet_id", referencedColumnName="id")},
     *     inverseJoinColumns={@JoinColumn(name="friend_id", referencedColumnName="id")})
     * @OrderBy({"name" = "ASC"})
     */
    public $friends;

    public function getName(): string
    {
        return $this->name;
    }
}

/**
 * @Entity
 */
class OJTICCat extends OJTICPet
{
}

/**
 * @Entity
 */
class OJTICDog extends OJTICPet
{
}
