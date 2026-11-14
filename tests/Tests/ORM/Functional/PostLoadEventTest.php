<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

class PostLoadEventTest extends OrmFunctionalTestCase
{
    private int|null $userId = null;

    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();

        $this->loadFixture();
    }

    public function testLoadedEntityUsingFindShouldTriggerEvent(): void
    {
        $mockListener = $this->createMock(PostLoadListener::class);

        // CmsUser and CmsAddres, because it's a ToOne inverse side on CmsUser
        $mockListener
            ->expects(self::exactly(2))
            ->method('postLoad');

        $eventManager = $this->_em->getEventManager();

        $eventManager->addEventListener([Events::postLoad], $mockListener);

        $this->_em->find(CmsUser::class, $this->userId);
    }

    public function testLoadedEntityUsingQueryShouldTriggerEvent(): void
    {
        $mockListener = $this->createMock(PostLoadListener::class);

        // CmsUser and CmsAddres, because it's a ToOne inverse side on CmsUser
        $mockListener
            ->expects(self::exactly(2))
            ->method('postLoad');

        $eventManager = $this->_em->getEventManager();

        $eventManager->addEventListener([Events::postLoad], $mockListener);

        $query = $this->_em->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :id');

        $query->setParameter('id', $this->userId);
        $query->getResult();
    }

    public function testLoadedAssociationToOneShouldTriggerEvent(): void
    {
        $mockListener = $this->createMock(PostLoadListener::class);

        // CmsUser (root), CmsAddress (ToOne inverse side), CmsEmail (joined association)
        $mockListener
            ->expects(self::exactly(3))
            ->method('postLoad');

        $eventManager = $this->_em->getEventManager();

        $eventManager->addEventListener([Events::postLoad], $mockListener);

        $query = $this->_em->createQuery('SELECT u, e FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.email e WHERE u.id = :id');

        $query->setParameter('id', $this->userId);
        $query->getResult();
    }

    public function testLoadedAssociationToManyShouldTriggerEvent(): void
    {
        $mockListener = $this->createMock(PostLoadListener::class);

        // CmsUser (root), CmsAddress (ToOne inverse side), 2 CmsPhonenumber (joined association)
        $mockListener
            ->expects(self::exactly(4))
            ->method('postLoad');

        $eventManager = $this->_em->getEventManager();

        $eventManager->addEventListener([Events::postLoad], $mockListener);

        $query = $this->_em->createQuery('SELECT u, p FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.phonenumbers p WHERE u.id = :id');

        $query->setParameter('id', $this->userId);
        $query->getResult();
    }

    public function testLoadedProxyEntityShouldTriggerEvent(): void
    {
        $eventManager = $this->_em->getEventManager();

        // Should not be invoked during getReference call
        $mockListener = $this->createMock(PostLoadListener::class);

        $mockListener
            ->expects(self::never())
            ->method('postLoad');

        $eventManager->addEventListener([Events::postLoad], $mockListener);

        $userProxy = $this->_em->getReference(CmsUser::class, $this->userId);

        // Now deactivate original listener and attach new one
        $eventManager->removeEventListener([Events::postLoad], $mockListener);

        $mockListener2 = $this->createMock(PostLoadListener::class);

        $mockListener2
            ->expects(self::exactly(2))
            ->method('postLoad');

        $eventManager->addEventListener([Events::postLoad], $mockListener2);

        $userProxy->getName();
    }

    public function testLoadedProxyPartialShouldTriggerEvent(): void
    {
        $eventManager = $this->_em->getEventManager();

        // Should not be invoked during getReference call
        $mockListener = $this->createMock(PostLoadListener::class);

        // CmsUser (partially loaded), CmsAddress (inverse ToOne), 2 CmsPhonenumber
        $mockListener
            ->expects(self::exactly(4))
            ->method('postLoad');

        $eventManager->addEventListener([Events::postLoad], $mockListener);

        $query = $this->_em->createQuery('SELECT PARTIAL u.{id, name}, p FROM Doctrine\Tests\Models\CMS\CmsUser u JOIN u.phonenumbers p WHERE u.id = :id');

        $query->setParameter('id', $this->userId);
        $query->getResult();
    }

    public function testLoadedProxyAssociationToOneShouldTriggerEvent(): void
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);

        $mockListener = $this->createMock(PostLoadListener::class);

        // CmsEmail (proxy)
        $mockListener
            ->expects(self::exactly(1))
            ->method('postLoad');

        $eventManager = $this->_em->getEventManager();

        $eventManager->addEventListener([Events::postLoad], $mockListener);

        $emailProxy = $user->getEmail();

        $emailProxy->getEmail();
    }

    public function testLoadedProxyAssociationToManyShouldTriggerEvent(): void
    {
        $user = $this->_em->find(CmsUser::class, $this->userId);

        $mockListener = $this->createMock(PostLoadListener::class);

        // 2 CmsPhonenumber (proxy)
        $mockListener
            ->expects(self::exactly(2))
            ->method('postLoad');

        $eventManager = $this->_em->getEventManager();

        $eventManager->addEventListener([Events::postLoad], $mockListener);

        $phonenumbersCol = $user->getPhonenumbers();

        $phonenumbersCol->first();
    }

    #[Group('DDC-3005')]
    public function testAssociationsArePopulatedWhenEventIsFired(): void
    {
        $checkerListener = new PostLoadListenerCheckAssociationsArePopulated();
        $this->_em->getEventManager()->addEventListener([Events::postLoad], $checkerListener);

        $qb = $this->_em->getRepository(CmsUser::class)->createQueryBuilder('u');
        $qb->leftJoin('u.email', 'email');
        $qb->addSelect('email');
        $qb->getQuery()->getSingleResult();

        self::assertTrue($checkerListener->checked, 'postLoad event is not invoked');
        self::assertTrue($checkerListener->populated, 'Association of email is not populated in postLoad event');
    }

    #[Group('DDC-3005')]
    public function testEventRaisedCorrectTimesWhenOtherEntityLoadedInEventHandler(): void
    {
        $eventManager = $this->_em->getEventManager();
        $listener     = new PostLoadListenerLoadEntityInEventHandler();
        $eventManager->addEventListener([Events::postLoad], $listener);

        $this->_em->find(CmsUser::class, $this->userId);
        self::assertSame(1, $listener->countHandledEvents(CmsUser::class), CmsUser::class . ' should be handled once!');
        self::assertSame(1, $listener->countHandledEvents(CmsEmail::class), CmsEmail::class . ' should be handled once!');
    }

    private function loadFixture(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Roman';
        $user->username = 'romanb';
        $user->status   = 'developer';

        $address          = new CmsAddress();
        $address->country = 'Germany';
        $address->city    = 'Berlin';
        $address->zip     = '12345';

        $user->setAddress($address);

        $email = new CmsEmail();
        $email->setEmail('roman@domain.com');

        $user->setEmail($email);

        $ph1              = new CmsPhonenumber();
        $ph1->phonenumber = '0301234';

        $ph2              = new CmsPhonenumber();
        $ph2->phonenumber = '987654321';

        $user->addPhonenumber($ph1);
        $user->addPhonenumber($ph2);

        $this->_em->persist($user);
        $this->_em->flush();

        $this->userId = $user->getId();

        $this->_em->clear();
    }
}

class PostLoadListener
{
    public function postLoad(PostLoadEventArgs $event): void
    {
        // Expected to be mocked out
        echo 'Should never be called!';
    }
}

class PostLoadListenerCheckAssociationsArePopulated
{
    public bool $checked = false;

    public bool $populated = false;

    public function postLoad(PostLoadEventArgs $event): void
    {
        $object = $event->getObject();
        if ($object instanceof CmsUser) {
            if ($this->checked) {
                throw new RuntimeException('Expected to be one user!');
            }

            $this->checked   = true;
            $this->populated = $object->getEmail() !== null;
        }
    }
}

class PostLoadListenerLoadEntityInEventHandler
{
    public function __construct()
    {
    }

    /** @var array<class-string, int> */
    private array $firedByClasses = [];

    public function postLoad(PostLoadEventArgs $event): void
    {
        $object = $event->getObject();
        $class  = $object::class;
        if (! isset($this->firedByClasses[$class])) {
            $this->firedByClasses[$class] = 1;
        } else {
            $this->firedByClasses[$class]++;
        }

        if ($object instanceof CmsUser) {
            $object->getEmail()->getEmail();
        }
    }

    public function countHandledEvents($className): int
    {
        return $this->firedByClasses[$className] ?? 0;
    }
}
