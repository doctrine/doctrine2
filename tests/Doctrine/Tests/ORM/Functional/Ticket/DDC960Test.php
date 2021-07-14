<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

class DDC960Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC960Root::class),
                    $this->_em->getClassMetadata(DDC960Child::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    /**
     * @group DDC-960
     */
    public function testUpdateRootVersion(): void
    {
        $child = new DDC960Child('Test');
        $this->_em->persist($child);
        $this->_em->flush();

        $child->setName('Test2');

        $this->_em->flush();

        $this->assertEquals(2, $child->getVersion());
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorMap({
 *  "root" = "DDC960Root",
 *  "child" = "DDC960Child"
 * })
 */
class DDC960Root
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    private $id;

    /**
     * @var int
     * @Column(type="integer")
     * @Version
     */
    private $version;

    public function getId(): int
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}

/**
 * @Entity
 */
class DDC960Child extends DDC960Root
{
    /**
     * @Column(type="string")
     * @var string
     */
    private $name;

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function setName($name): void
    {
        $this->name = $name;
    }
}
