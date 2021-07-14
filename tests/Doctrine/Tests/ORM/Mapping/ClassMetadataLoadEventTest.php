<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmTestCase;

class ClassMetadataLoadEventTest extends OrmTestCase
{
    /**
     * @group DDC-1610
     */
    public function testEvent(): void
    {
        $em              = $this->getTestEntityManager();
        $metadataFactory = $em->getMetadataFactory();
        $evm             = $em->getEventManager();
        $evm->addEventListener(Events::loadClassMetadata, $this);
        $classMetadata = $metadataFactory->getMetadataFor(LoadEventTestEntity::class);
        $this->assertTrue($classMetadata->hasField('about'));
        $this->assertArrayHasKey('about', $classMetadata->reflFields);
        $this->assertInstanceOf('ReflectionProperty', $classMetadata->reflFields['about']);
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        $classMetadata = $eventArgs->getClassMetadata();
        $field         = [
            'fieldName' => 'about',
            'type' => 'string',
            'length' => 255,
        ];
        $classMetadata->mapField($field);
    }
}

/**
 * @Entity
 * @Table(name="load_event_test_entity")
 */
class LoadEventTestEntity
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", length=255)
     */
    private $name;

    /** @var mixed */
    private $about;
}
