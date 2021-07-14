<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\PreFlush;
use Doctrine\Tests\OrmFunctionalTestCase;

use function get_class;

/**
 * @group DDC-2996
 */
class DDC2996Test extends OrmFunctionalTestCase
{
    public function testIssue(): void
    {
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC2996User::class),
                $this->_em->getClassMetadata(DDC2996UserPreference::class),
            ]
        );

        $pref        = new DDC2996UserPreference();
        $pref->user  = new DDC2996User();
        $pref->value = 'foo';

        $this->_em->persist($pref);
        $this->_em->persist($pref->user);
        $this->_em->flush();

        $pref->value = 'bar';
        $this->_em->flush();

        $this->assertEquals(1, $pref->user->counter);

        $this->_em->clear();

        $pref = $this->_em->find(DDC2996UserPreference::class, $pref->id);
        $this->assertEquals(1, $pref->user->counter);
    }
}

/**
 * @Entity
 */
class DDC2996User
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $counter = 0;
}

/**
 * @Entity @HasLifecycleCallbacks
 */
class DDC2996UserPreference
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;
    /**
     * @var string
     * @Column(type="string")
     */
    public $value;

    /**
     * @var DDC2996User
     * @ManyToOne(targetEntity="DDC2996User")
     */
    public $user;

    /**
     * @PreFlush
     */
    public function preFlush($event): void
    {
        $em  = $event->getEntityManager();
        $uow = $em->getUnitOfWork();

        if ($uow->getOriginalEntityData($this->user)) {
            $this->user->counter++;
            $uow->recomputeSingleEntityChangeSet(
                $em->getClassMetadata(get_class($this->user)),
                $this->user
            );
        }
    }
}
