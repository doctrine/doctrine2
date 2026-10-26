<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12706;

use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\RequiresPhp;
use ReflectionClass;

/** @see https://github.com/doctrine/orm/issues/12706 */
#[RequiresPhp('>=8.4.0')]
final class GH12706Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(Studio::class);
    }

    public function testUninitializedLazyEmbeddableIsPersisted(): void
    {
        $address = (new ReflectionClass(Address::class))->newLazyGhost(
            static function (Address $address): void {
                $address->__construct('Paris');
            },
        );

        $studio = new Studio($address);
        $this->_em->persist($studio);
        $this->_em->flush();

        self::assertSame('Paris', $this->_em->getConnection()->fetchOne(
            'SELECT address_city FROM gh12706_studio WHERE id = ?',
            [$studio->id],
        ));
    }

    public function testRefreshingIntoUninitializedLazyEmbeddableIsNotOverwrittenByItsInitializer(): void
    {
        $studio = new Studio(new Address('Paris'));
        $this->_em->persist($studio);
        $this->_em->flush();

        $this->_em->getConnection()->update('gh12706_studio', ['address_city' => 'Lyon'], ['id' => $studio->id]);

        $studio->address = (new ReflectionClass(Address::class))->newLazyGhost(
            static function (Address $address): void {
                $address->__construct('Paris');
            },
        );

        $this->_em->refresh($studio);
        $this->_em->flush();

        self::assertSame('Lyon', $studio->address->city);
        self::assertSame('Lyon', $this->_em->getConnection()->fetchOne(
            'SELECT address_city FROM gh12706_studio WHERE id = ?',
            [$studio->id],
        ));
    }
}
