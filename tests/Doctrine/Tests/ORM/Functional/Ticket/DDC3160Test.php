<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;
use function get_class;

/**
 * FlushEventTest
 */
class DDC3160Test extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        $this->useModelSet('cms');
        parent::setUp();
    }

    /**
     * @group DDC-3160
     */
    public function testNoUpdateOnInsert() : void
    {
        $listener = new DDC3160OnFlushListener();
        $this->em->getEventManager()->addEventListener(Events::onFlush, $listener);

        $user           = new CmsUser();
        $user->username = 'romanb';
        $user->name     = 'Roman';
        $user->status   = 'Dev';

        $this->em->persist($user);
        $this->em->flush();

        $this->em->refresh($user);

        self::assertEquals('romanc', $user->username);
        self::assertEquals(1, $listener->inserts);
        self::assertEquals(0, $listener->updates);
    }
}

class DDC3160OnFlushListener
{
    public $inserts = 0;
    public $updates = 0;

    public function onFlush(OnFlushEventArgs $args)
    {
        $em  = $args->getEntityManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->inserts++;
            if ($entity instanceof CmsUser) {
                $entity->username = 'romanc';
                $cm               = $em->getClassMetadata(\get_class($entity));
                $uow->recomputeSingleEntityChangeSet($cm, $entity);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->updates++;
        }
    }
}
