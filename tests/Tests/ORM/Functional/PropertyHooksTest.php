<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Tests\Models\PropertyHooks\MappingVirtualProperty;
use Doctrine\Tests\Models\PropertyHooks\User;
use Doctrine\Tests\OrmFunctionalTestCase;

class PropertyHooksTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->_em->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL/MariaDB is case-insensitive by default, and the logic of this test relies on case sensitivity.');
        }

        $this->createSchemaForModels(
            User::class,
        );
    }

    public function testMapPropertyHooks(): void
    {
        $user           = new User();
        $user->fullName = 'John Doe';
        $user->language = 'EN';

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->find(User::class, $user->id);

        self::assertEquals('John', $user->first);
        self::assertEquals('Doe', $user->last);
        self::assertEquals('John Doe', $user->fullName);
        self::assertEquals('EN', $user->language, 'The property hook uppercases the language.');

        $language = $this->_em->createQuery('SELECT u.language FROM ' . User::class . ' u WHERE u.id = :id')
            ->setParameter('id', $user->id)
            ->getSingleScalarResult();

        $this->assertEquals('en', $language, 'Selecting a field from DQL does not go through the property hook, accessing raw data.');

        $this->_em->clear();

        $user = $this->_em->getRepository(User::class)->findOneBy(['language' => 'EN']);

        self::assertNull($user);

        $user = $this->_em->getRepository(User::class)->findOneBy(['language' => 'en']);

        self::assertNotNull($user);
    }

    public function testTriggerLazyLoadingWhenAccessingPropertyHooks(): void
    {
        $user           = new User();
        $user->fullName = 'Ludwig von Beethoven';
        $user->language = 'DE';

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $user = $this->_em->getReference(User::class, $user->id);

        $this->assertTrue($this->_em->getUnitOfWork()->isUninitializedObject($user));

        self::assertEquals('Ludwig', $user->first);
        self::assertEquals('von Beethoven', $user->last);
        self::assertEquals('Ludwig von Beethoven', $user->fullName);
        self::assertEquals('DE', $user->language, 'The property hook uppercases the language.');

        $this->assertFalse($this->_em->getUnitOfWork()->isUninitializedObject($user));

        $this->_em->clear();

        $user = $this->_em->getReference(User::class, $user->id);

        self::assertEquals('Ludwig von Beethoven', $user->fullName);
    }

    public function testMappingVirtualPropertyIsNotSupported(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Mapping virtual property "fullName" on entity "Doctrine\Tests\Models\PropertyHooks\MappingVirtualProperty" is not allowed.');

        $this->_em->getClassMetadata(MappingVirtualProperty::class);
    }
}
