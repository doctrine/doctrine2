<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-1526
 */
class DDC1526Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1526Menu::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $parents = [];
        for ($i = 0; $i < 9; $i++) {
            $entity = new DDC1526Menu();

            if (isset($parents[$i % 3])) {
                $entity->parent = $parents[$i % 3];
            }

            $this->_em->persist($entity);
            $parents[$i] = $entity;
        }

        $this->_em->flush();
        $this->_em->clear();

        $dql   = 'SELECT m, c
            FROM ' . __NAMESPACE__ . '\DDC1526Menu m
            LEFT JOIN m.children c';
        $menus = $this->_em->createQuery($dql)->getResult();

        // All Children collection now have to be initialized
        foreach ($menus as $menu) {
            $this->assertTrue($menu->children->isInitialized());
        }
    }
}

/**
 * @Entity
 */
class DDC1526Menu
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue
     */
    public $id;
    /**
     * @var DDC1526Menu
     * @ManyToOne(targetEntity="DDC1526Menu", inversedBy="children")
     */
    public $parent;

    /**
     * @psalm-var Collection<int, DDC1526Menu>
     * @OneToMany(targetEntity="DDC1526Menu", mappedBy="parent")
     */
    public $children;
}
