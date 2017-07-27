<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Annotation as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Functional tests for the Single Table Inheritance mapping strategy.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class OrderedJoinedTableInheritanceCollectionTest extends OrmFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();
        try {
            $this->schemaTool->createSchema(
                [
                $this->em->getClassMetadata(OJTIC_Pet::class),
                $this->em->getClassMetadata(OJTIC_Cat::class),
                $this->em->getClassMetadata(OJTIC_Dog::class),
                ]
            );
        } catch (\Exception $e) {
            // Swallow all exceptions. We do not test the schema tool here.
        }

        $dog = new OJTIC_Dog();
        $dog->name = "Poofy";

        $dog1 = new OJTIC_Dog();
        $dog1->name = "Zampa";
        $dog2 = new OJTIC_Dog();
        $dog2->name = "Aari";

        $dog1->mother = $dog;
        $dog2->mother = $dog;

        $dog->children[] = $dog1;
        $dog->children[] = $dog2;

        $this->em->persist($dog);
        $this->em->persist($dog1);
        $this->em->persist($dog2);
        $this->em->flush();
        $this->em->clear();
    }

    public function testOrderdOneToManyCollection()
    {
        $poofy = $this->em->createQuery("SELECT p FROM Doctrine\Tests\ORM\Functional\OJTIC_Pet p WHERE p.name = 'Poofy'")->getSingleResult();

        self::assertEquals('Aari', $poofy->children[0]->getName());
        self::assertEquals('Zampa', $poofy->children[1]->getName());

        $this->em->clear();

        $result = $this->em->createQuery(
            "SELECT p, c FROM Doctrine\Tests\ORM\Functional\OJTIC_Pet p JOIN p.children c WHERE p.name = 'Poofy'")
                ->getResult();

        self::assertEquals(1, count($result));
        $poofy = $result[0];

        self::assertEquals('Aari', $poofy->children[0]->getName());
        self::assertEquals('Zampa', $poofy->children[1]->getName());
    }
}

/**
 * @ORM\Entity
 * @ORM\InheritanceType("JOINED")
 * @ORM\DiscriminatorColumn(name="discr", type="string")
 * @ORM\DiscriminatorMap({
 *      "cat" = "OJTIC_Cat",
 *      "dog" = "OJTIC_Dog"})
 */
abstract class OJTIC_Pet
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     *
     * @ORM\Column
     */
    public $name;

    /**
     * @ORM\ManyToOne(targetEntity="OJTIC_PET")
     */
    public $mother;

    /**
     * @ORM\OneToMany(targetEntity="OJTIC_Pet", mappedBy="mother")
     * @ORM\OrderBy({"name" = "ASC"})
     */
    public $children;

    /**
     * @ORM\ManyToMany(targetEntity="OJTIC_Pet")
     * @ORM\JoinTable(name="OTJIC_Pet_Friends",
     *     joinColumns={@ORM\JoinColumn(name="pet_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="friend_id", referencedColumnName="id")})
     * @ORM\OrderBy({"name" = "ASC"})
     */
    public $friends;

    public function getName()
    {
        return $this->name;
    }
}

/**
 * @ORM\Entity
 */
class OJTIC_Cat extends OJTIC_Pet
{

}

/**
 * @ORM\Entity
 */
class OJTIC_Dog extends OJTIC_Pet
{

}
