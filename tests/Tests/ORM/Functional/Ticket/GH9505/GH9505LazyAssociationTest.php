<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH9505;

use Doctrine\DBAL\Types\Type;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Distinct from the GH9505Test added by GH-12496: that PR only skips a readonly property's
 * re-assignment when Query::HINT_REFRESH is set, so it does not cover this case — a plain
 * lazy-loaded reference, no refresh() involved at all.
 */
class GH9505LazyAssociationTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        if (! Type::hasType(GH9505ObjectIdType::NAME)) {
            Type::addType(GH9505ObjectIdType::NAME, GH9505ObjectIdType::class);
        }

        parent::setUp();
    }

    public function testLazyInitializationDoesNotThrowOnReadonlyObjectIdentifier(): void
    {
        $this->createSchemaForModels(EntityWithReadonlyObjectIdentifier::class);

        $this->_em->persist(new EntityWithReadonlyObjectIdentifier(new GH9505ObjectId('abc-123'), 'Test Name'));
        $this->_em->flush();
        $this->_em->clear();

        // getReference() eagerly assigns $id on the (still uninitialized) ghost — see
        // ProxyFactory::getProxy(). This GH9505ObjectId instance is *not* the same instance
        // that will come back from the row below, though both represent 'abc-123'.
        $proxy = $this->_em->getReference(EntityWithReadonlyObjectIdentifier::class, new GH9505ObjectId('abc-123'));

        // Accessing a non-identifier field triggers full lazy initialization. UnitOfWork::
        // createEntity() used to re-assign every mapped field from the freshly hydrated row,
        // including $id — a *third* GH9505ObjectId instance, equal in value but not identical
        // to the one the ghost already carries. ReadonlyAccessor::setValue() compares old and
        // new value with strict !==, which is never true for two distinct objects representing
        // the same value, so this used to throw:
        // LogicException("Attempting to change readonly property ...::$id").
        self::assertSame('Test Name', $proxy->getName());
        self::assertSame('abc-123', (string) $proxy->getId());
    }
}
