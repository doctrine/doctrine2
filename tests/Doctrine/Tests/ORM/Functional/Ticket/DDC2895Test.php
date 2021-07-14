<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\PreUpdate;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function assert;
use function get_class;

class DDC2895Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC2895::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function testPostLoadOneToManyInheritance(): void
    {
        $cm = $this->_em->getClassMetadata(DDC2895::class);

        $this->assertEquals(
            [
                'prePersist' => ['setLastModifiedPreUpdate'],
                'preUpdate' => ['setLastModifiedPreUpdate'],
            ],
            $cm->lifecycleCallbacks
        );

        $ddc2895 = new DDC2895();

        $this->_em->persist($ddc2895);
        $this->_em->flush();
        $this->_em->clear();

        $ddc2895 = $this->_em->find(get_class($ddc2895), $ddc2895->id);
        assert($ddc2895 instanceof DDC2895);

        $this->assertNotNull($ddc2895->getLastModified());
    }
}

/**
 * @MappedSuperclass
 * @HasLifecycleCallbacks
 */
abstract class AbstractDDC2895
{
    /**
     * @Column(name="last_modified", type="datetimetz", nullable=false)
     * @var DateTime
     */
    protected $lastModified;

    /**
     * @PrePersist
     * @PreUpdate
     */
    public function setLastModifiedPreUpdate(): void
    {
        $this->setLastModified(new DateTime());
    }

    public function setLastModified(DateTime $lastModified): void
    {
        $this->lastModified = $lastModified;
    }

    public function getLastModified(): DateTime
    {
        return $this->lastModified;
    }
}

/**
 * @Entity
 * @HasLifecycleCallbacks
 */
class DDC2895 extends AbstractDDC2895
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @param mixed $id
     */
    public function setId($id): void
    {
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }
}
