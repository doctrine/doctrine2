<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Tests\Models\GH12403\GH12403ScalarEntity;
use Doctrine\Tests\Models\OneToOneInverseSideLoad\InverseSide;
use Doctrine\Tests\Models\OneToOneInverseSideLoad\OwningSide;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\RequiresPhp;

/** @see https://github.com/doctrine/orm/issues/12403 */
#[RequiresPhp('>=8.4.0')]
class GH12403Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(GH12403ScalarEntity::class, OwningSide::class, InverseSide::class);
    }

    /**
     * A to-one association's target proxy can end up with every one of its
     * properties assigned (identifier at proxy-creation time, remaining
     * properties as a side effect of hydrating the owning side, e.g. the
     * inverse-association sync) without ever running the proxy's registered
     * initializer. PHP then reports such an object as no longer uninitialized,
     * even though UnitOfWork::createEntity() never ran for it and never
     * recorded a snapshot of its data.
     */
    public function testAssociationTargetCanSilentlyCompleteNativeLazyInitialization(): void
    {
        $owner   = new OwningSide();
        $inverse = new InverseSide();

        $owner->id       = 'gh12403-owner';
        $inverse->id     = 'gh12403-inverse';
        $owner->inverse  = $inverse;
        $inverse->owning = $owner;

        $this->_em->persist($owner);
        $this->_em->persist($inverse);
        $this->_em->flush();
        $this->_em->clear();

        $reloadedOwner = $this->_em->find(OwningSide::class, 'gh12403-owner');
        self::assertNotNull($reloadedOwner);

        $target = $reloadedOwner->inverse;

        if ($this->_em->getConfiguration()->isSecondLevelCacheEnabled()) {
            // With the Second Level Cache enabled, association hydration takes a
            // different path (through UnitOfWork::createEntity() via the cache
            // hydrator) that does not trigger the silent auto-initialization
            // below, so the target legitimately stays lazy here.
            self::assertTrue($this->isUninitializedObject($target));
        } else {
            self::assertFalse(
                $this->isUninitializedObject($target),
                'InverseSide has no scalar fields of its own, so hydrating the owning side leaves it fully assigned and therefore no longer "uninitialized" per PHP, despite never being loaded through UnitOfWork::createEntity().',
            );
        }

        // A flush must not error out or otherwise mishandle this entity, even
        // though it is now included in change-tracking despite having never
        // gone through the ORM's own hydration bookkeeping.
        $this->_em->flush();
        $this->_em->clear();

        $checked = $this->_em->find(InverseSide::class, 'gh12403-inverse');
        self::assertNotNull($checked);
        self::assertInstanceOf(OwningSide::class, $checked->owning);
        self::assertSame('gh12403-owner', $checked->owning->id);
    }

    /**
     * Direct reproduction of the reported symptom: once an entity's native
     * lazy object is completed by some means other than
     * UnitOfWork::createEntity(), its changes used to be silently and
     * permanently dropped from every future flush(), because the entity's
     * empty original-data snapshot was trusted forever.
     */
    public function testChangeIsPersistedAfterEntityIsInitializedWithoutGoingThroughCreateEntity(): void
    {
        $entity       = new GH12403ScalarEntity();
        $entity->name = 'old name';

        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        $id        = $entity->id;
        $reference = $this->_em->getReference(GH12403ScalarEntity::class, $id);

        self::assertTrue($this->isUninitializedObject($reference));

        // Simulate the object becoming initialized through a path other than
        // UnitOfWork::createEntity(), as observed for association proxies
        // above.
        $this->_em->getClassMetadata(GH12403ScalarEntity::class)->reflClass->markLazyObjectAsInitialized($reference);
        self::assertFalse($this->isUninitializedObject($reference));

        $reference->name = 'new name';
        $this->_em->flush();

        $name = $this->_em->getConnection()->fetchOne(
            'SELECT name FROM gh12403_scalar_entity WHERE id = ?',
            [$id],
        );

        self::assertSame('new name', $name);
    }
}
