<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function serialize;
use function unserialize;

class DDC381Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC381Entity::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function testCallUnserializedProxyMethods(): void
    {
        $entity = new DDC381Entity();

        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();
        $persistedId = $entity->getId();

        $entity = $this->_em->getReference(DDC381Entity::class, $persistedId);

        // explicitly load proxy (getId() does not trigger reload of proxy)
        $id = $entity->getOtherMethod();

        $data   = serialize($entity);
        $entity = unserialize($data);

        $this->assertEquals($persistedId, $entity->getId());
    }
}

/**
 * @Entity
 */
class DDC381Entity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    public function getId(): int
    {
        return $this->id;
    }

    public function getOtherMethod(): void
    {
    }
}
