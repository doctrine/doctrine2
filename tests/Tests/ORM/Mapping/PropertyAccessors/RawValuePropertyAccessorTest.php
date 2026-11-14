<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping\PropertyAccessors;

use Doctrine\ORM\Mapping\PropertyAccessors\RawValuePropertyAccessor;
use Doctrine\Tests\Models\PropertyHooks\User;
use Doctrine\Tests\OrmTestCase;
use ReflectionObject;

use function trim;

class RawValuePropertyAccessorTest extends OrmTestCase
{
    public function testSetGetValue(): void
    {
        $object        = new User();
        $reflection    = new ReflectionObject($object);
        $accessorFirst = RawValuePropertyAccessor::fromReflectionProperty($reflection->getProperty('first'));
        $accessorLast  = RawValuePropertyAccessor::fromReflectionProperty($reflection->getProperty('last'));

        $accessorFirst->setValue($object, 'Benjamin');
        $accessorLast->setValue($object, 'Eberlei');

        self::assertEquals('Benjamin Eberlei', $object->fullName);
        self::assertEquals('Benjamin', $accessorFirst->getValue($object));
        self::assertEquals('Eberlei', $accessorLast->getValue($object));

        $accessorFirst->setValue($object, '');
        $accessorLast->setValue($object, '');

        self::assertEquals('', trim($object->fullName));
    }

    public function testSetGetValueWithLanguage(): void
    {
        $object     = new User();
        $reflection = new ReflectionObject($object);
        $accessor   = RawValuePropertyAccessor::fromReflectionProperty($reflection->getProperty('language'));

        $accessor->setValue($object, 'en');

        self::assertEquals('EN', $object->language);
        self::assertEquals('en', $accessor->getValue($object));

        $accessor->setValue($object, 'EN');

        self::assertEquals('EN', $object->language);
        self::assertEquals('EN', $accessor->getValue($object));
    }
}
